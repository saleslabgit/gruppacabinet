<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Payments\ConfirmPayment;
use App\Payments\Webpay;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class WebpayConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function races(): array
    {
        return [['placement', false, false], ['extension', false, false], ['placement', true, false], ['extension', true, false], ['extension', false, true], ['extension', true, true]];
    }

    #[DataProvider('races')]
    public function test_signed_notify_races_apply_exactly_one_effect(string $type, bool $boundApi, bool $active): void
    {
        WebpayFixture::configure();
        $owner = User::create(['email' => 'race@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id, 'status' => $type === 'placement' ? 'awaiting_payment' : ($active ? 'active' : 'expired'),
            'expires_at' => $active ? now()->addDay() : now()->subDay(), 'placement_days' => 30]);
        $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'order_number' => 'GP-'.bin2hex(random_bytes(16)),
            'type' => $type, 'amount' => 5000, 'currency' => 'BYN', 'status' => 'pending', 'started_at' => now()->subMinutes(21),
            'extension_expires_at' => $type === 'extension' ? $group->expires_at : null,
            'extension_status' => $type === 'extension' ? ($active ? 'active' : 'expired') : null, 'extension_days' => 30]);
        $fields = WebpayFixture::notify($payment);
        if ($boundApi) {
            // Obtain actual verified binding through the service, while effect cannot apply.
            DB::table('gp_groups')->where('id', $group->id)->update(['status' => 'approved']);
            app(ConfirmPayment::class)->apply($payment->id, app(Webpay::class)->notify($fields));
            DB::table('gp_groups')->where('id', $group->id)->update(['status' => $type === 'placement' ? 'awaiting_payment' : ($active ? 'active' : 'expired')]);
            $this->assertNotNull($payment->fresh()->binding_verified_at);
        }
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array'];
        $code = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\Support\WebpayFixture::configure();
$fields = json_decode($argv[2], true);
$provider = app(App\Payments\Webpay::class);
if ($argv[3] === 'api') {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(['sandbox.webpay.by' => Illuminate\Support\Facades\Http::response(Tests\Support\WebpayFixture::xml($fields))]);
    $result = $provider->transaction($fields['transaction_id']);
} else {
    $result = $provider->notify($fields);
}
echo "ready\n"; flush();
echo app(App\Payments\ConfirmPayment::class)->apply((int) $argv[1], $result);
WORKER;
        $workers = [];
        DB::beginTransaction();
        try {
            Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-r', $code, (string) $payment->id, json_encode($fields), $boundApi && $i === 1 ? 'api' : 'notify'], base_path(), $env);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                while (! str_contains($worker->getOutput(), 'ready')) {
                    $worker->checkTimeout();
                    $this->assertTrue($worker->isRunning(), $worker->getErrorOutput());
                    usleep(10000);
                }
                $this->assertTrue($worker->isRunning());
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim(str_replace("ready\n", '', $worker->getOutput()));
            }
            sort($results);
            $this->assertSame(['applied', 'duplicate'], $results);
            $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
            $this->assertSame($active ? 0 : 1, $group->statusHistory()->count());
            if ($active) {
                $this->assertTrue($group->fresh()->expires_at->equalTo($group->expires_at->copy()->addDays(30)));
            }
            $this->assertSame($type === 'placement' ? 'draft' : ($active ? 'active' : 'approved'), $group->fresh()->status->value);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }
}
