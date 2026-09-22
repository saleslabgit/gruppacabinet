<?php

namespace Tests\Feature;

use App\Support\DeploymentPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeploymentPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        $this->secret = bin2hex(random_bytes(24));
        config(['app.url' => 'https://gruppa.info/cabinet', 'app.debug' => false,
            'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'synthetic.invalid',
            'mail.mailers.smtp.password' => $this->secret, 'integration.secret' => $this->secret,
            'webpay.environment' => 'sandbox', 'webpay.store_id' => $this->secret,
            'webpay.secret_key' => $this->secret, 'webpay.api_username' => $this->secret, 'webpay.api_password' => $this->secret]);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    public static function timeoutCapabilities(): array
    {
        return ['native' => [null], 'available' => [true], 'unavailable' => [false]];
    }

    private function provideTimeoutCapability(bool $available): void
    {
        $this->app->instance(DeploymentPreflight::class, new class($available) extends DeploymentPreflight
        {
            public function __construct(private bool $available) {}

            protected function supportsHardWorkerTimeout(): bool
            {
                return $this->available;
            }
        });
    }

    #[DataProvider('timeoutCapabilities')]
    public function test_preflight_pass_is_redacted_read_only_and_removes_technical_probes(?bool $available): void
    {
        if ($available !== null) {
            $this->provideTimeoutCapability($available);
        }
        $before = [];
        foreach (['gp_users', 'gp_groups', 'gp_payments', 'jobs', 'cache', 'cache_locks'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $exit = Artisan::call('deployment:preflight');
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $expected = ($available ?? extension_loaded('pcntl'))
            ? 'PASS CLI worker hard timeout (pcntl): available'
            : 'WARN CLI worker hard timeout (pcntl): unavailable; verify cron process limit and shared-hosting fallback';
        $this->assertStringContainsString($expected, $output);
        $this->assertStringContainsString('MySQL', $output);
        $this->assertStringContainsString('PASS Database cache lock exclusion', $output);
        $this->assertStringContainsString('Integration secret: configured', $output);
        $this->assertStringNotContainsString($this->secret, $output);
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_preflight_reports_configuration_blockers_without_values(): void
    {
        $this->provideTimeoutCapability(false);
        config(['app.url' => 'http://'.$this->secret.'.invalid/wrong', 'app.debug' => true,
            'session.driver' => 'file', 'queue.default' => 'sync', 'cache.default' => 'file',
            'mail.default' => 'log', 'integration.secret' => null, 'webpay.secret_key' => null,
            'webpay.environment' => $this->secret]);
        $this->assertSame(1, Artisan::call('deployment:preflight'));
        $output = Artisan::output();
        $this->assertStringContainsString('WARN CLI worker hard timeout (pcntl): unavailable', $output);
        foreach (['Database sessions', 'Database queue', 'Shared database cache', 'HTTPS /cabinet APP_URL', 'APP_DEBUG disabled', 'SMTP delivery configured', 'Integration secret', 'WEBPAY secret_key', 'WEBPAY environment'] as $check) {
            $this->assertStringContainsString('FAIL '.$check, $output);
        }
        $this->assertStringNotContainsString($this->secret, $output);
    }

    public function test_missing_tables_and_unexpected_exceptions_are_safe_failures(): void
    {
        config(['cache.stores.database.table' => 'missing_synthetic_cache', 'session.table' => 'missing_synthetic_sessions']);
        Cache::forgetDriver('database');
        $this->assertSame(1, Artisan::call('deployment:preflight'));
        $output = Artisan::output();
        $this->assertStringContainsString('FAIL Database cache/locks', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->mock(DeploymentPreflight::class, fn ($mock) => $mock->shouldReceive('checks')->andThrow(new \RuntimeException($this->secret)));
        $this->assertSame(1, Artisan::call('deployment:preflight'));
        $this->assertStringNotContainsString($this->secret, Artisan::output());
    }
}
