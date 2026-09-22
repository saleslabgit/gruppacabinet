<?php

namespace Tests\Feature;

use App\Mail\ExpiryWarningMail;
use App\Mail\PasswordSetupMail;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Webpay;
use App\Services\PasswordSetupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Tests\Support\SuccessfulMailFake;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class SharedHostingRuntimeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database', 'queue.default' => 'database', 'mail.default' => 'smtp']);
        $this->seed(DatabaseSeeder::class);
        WebpayFixture::configure();
        Http::preventStrayRequests();
        SuccessfulMailFake::install();
    }

    public function test_database_cache_and_lock_exclude_an_independent_process(): void
    {
        $key = 'shared-hosting-test-'.bin2hex(random_bytes(8));
        $store = Cache::store('database');
        $store->put($key, 'synthetic', 60);
        $lock = $store->lock($key, 60);
        $this->assertTrue($lock->get());
        try {
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'mysql','database.connections.mysql.database'=>'gruppa_cabinet_test','cache.default'=>'database']);
Illuminate\Support\Facades\DB::purge('mysql');
$store=Illuminate\Support\Facades\Cache::store('database');
$lock=$store->lock($argv[1],60);$acquired=$lock->get();
echo json_encode([$store->get($argv[1]),$acquired]);
if($acquired){$lock->release();}
CODE;
            $process = new Process([PHP_BINARY, '-r', $script, $key], base_path());
            $process->mustRun();
            $this->assertSame(['synthetic', false], json_decode($process->getOutput(), true));
            $lock->release();
            $process->mustRun();
            $this->assertSame(['synthetic', true], json_decode($process->getOutput(), true));
        } finally {
            $lock->release();
            $store->forget($key);
        }
    }

    public function test_finite_database_worker_drains_mail_warning_and_trusted_payment_jobs(): void
    {
        $owner = User::create(['email' => 'shared-hosting@example.test', 'status' => 'approved']);
        app(PasswordSetupService::class)->invite($owner->id);
        $warning = Group::create(['owner_id' => $owner->id, 'title' => 'Synthetic warning', 'status' => 'active', 'expires_at' => now()->addDay(), 'placement_days' => 30]);
        $group = Group::create(['owner_id' => $owner->id, 'status' => 'awaiting_payment']);
        $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'type' => 'placement', 'order_number' => 'synthetic-shared-worker',
            'amount' => 5000, 'status' => 'pending', 'started_at' => now()->subMinutes(21), 'binding_verified_at' => now(), 'transaction_id' => '987654']);
        Http::fake(['*' => Http::response(WebpayFixture::xml(WebpayFixture::notify($payment)), 200)]);
        foreach (['groups:queue-expiry-warnings', 'payments:queue-recovery-checks'] as $command) {
            $this->assertSame(0, Artisan::call($command));
            $this->assertSame(0, Artisan::call($command));
        }
        $this->assertDatabaseCount('jobs', 3);
        $this->assertDatabaseCount('cache_locks', 2);
        // This is the actual finite Artisan worker, with fake SMTP/provider transports only.
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--timeout' => 45, '--max-time' => 50]));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('cache_locks', 0);
        $this->assertNotNull($warning->fresh()->expiry_warning_sent_at);
        $this->assertSame('succeeded', $payment->fresh()->status->value);
        $this->assertSame('draft', $group->fresh()->status->value);
        Mail::assertSent(PasswordSetupMail::class, 1);
        Mail::assertSent(ExpiryWarningMail::class, 1);
        Http::assertSentCount(1);
        Artisan::call('payments:queue-recovery-checks');
        Artisan::call('groups:queue-expiry-warnings');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_production_base_path_for_assets_routes_redirects_and_callback_forms(): void
    {
        config(['app.url' => 'https://gruppa.info/cabinet']);
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');
        $this->assertSame('https://gruppa.info/cabinet/ui.css', asset('ui.css'));
        $this->assertSame('https://gruppa.info/cabinet/login', redirect()->route('login')->getTargetUrl());
        $this->assertStringStartsWith('https://gruppa.info/cabinet/password/setup/', route('password.setup', ['token' => 'synthetic', 'email' => 'synthetic@example.test']));
        $payment = new Payment(['id' => 1, 'order_number' => 'synthetic-url', 'amount' => 5000]);
        $payment->id = 1;
        $fields = app(Webpay::class)->form($payment, '123')['fields'];
        foreach (['wsb_return_url', 'wsb_cancel_return_url', 'wsb_notify_url'] as $key) {
            $this->assertStringStartsWith('https://gruppa.info/cabinet/', $fields[$key]);
        }
    }
}
