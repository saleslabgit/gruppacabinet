<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GroupLifecycleConcurrencyTest extends TestCase
{
    // Workers need committed fixtures and independent MySQL connections.
    use DatabaseMigrations;

    public static function races(): array
    {
        return [['active', 'none'], ['active', 'extend'], ['paused', 'none'], ['paused', 'extend'], ['active', 'hide'], ['paused', 'hide']];
    }

    #[DataProvider('races')]
    public function test_concurrent_candidates_wait_for_lock_and_recheck_committed_state(string $status, string $change): void
    {
        $owner = User::create(['email' => 'lock@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id, 'status' => $status, 'expires_at' => now()->subMinute()]);
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array'];
        $code = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "ready\n";
flush();
echo (int) $app->make(App\Services\GroupLifecycleService::class)->expireOne((int) $argv[1]);
WORKER;
        $workers = [];
        DB::beginTransaction();
        try {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-r', $code, (string) $group->id], base_path(), $env);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                // Read buffered output too: a worker may signal before we start waiting.
                while (! str_contains($worker->getOutput(), 'ready')) {
                    $worker->checkTimeout();
                    $this->assertTrue($worker->isRunning(), $worker->getErrorOutput());
                    usleep(10000);
                }
                $this->assertTrue($worker->isRunning());
            }
            if ($change === 'extend') {
                $group->update(['expires_at' => now()->addDay()]);
            }
            if ($change === 'hide') {
                $group->update(['psychologist_deleted_at' => now()]);
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim(str_replace("ready\n", '', $worker->getOutput()));
            }
            sort($results);
            $this->assertSame($change !== 'none' ? ['0', '0'] : ['0', '1'], $results);
            $this->assertSame($change !== 'none' ? GroupStatus::from($status) : GroupStatus::Expired, $group->fresh()->status);
            $this->assertSame($change !== 'none' ? 0 : 1, $group->statusHistory()->count());
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
