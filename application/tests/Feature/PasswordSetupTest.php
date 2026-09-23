<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Jobs\SendPasswordSetup;
use App\Mail\PasswordSetupMail;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\PasswordSetupService;
use App\Services\PsychologistActions;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SuccessfulMailFake;
use Tests\TestCase;

class PasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private PasswordSetupService $setup;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        config(['mail.default' => 'smtp']);
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->user = User::where('email', 'psychologist@gruppa.test')->sole();
        $this->user->update(['password' => null]);
        $this->admin = User::where('email', 'admin@gruppa.test')->sole();
        $this->setup = app(PasswordSetupService::class);
        SuccessfulMailFake::install();
    }

    private function ttl(int $hours): void
    {
        Setting::where('key', SettingService::PASSWORD_SETUP_LINK_TTL_HOURS)->update(['value' => (string) $hours]);
        app(SettingService::class)->invalidate(SettingService::PASSWORD_SETUP_LINK_TTL_HOURS);
    }

    private function job(): SendPasswordSetup
    {
        return unserialize(json_decode(DB::table('jobs')->latest('id')->value('payload'), true)['data']['command']);
    }

    public function test_framework_storage_current_ttl_and_exact_boundary(): void
    {
        $this->ttl(2);
        $broker = $this->setup->broker();
        $this->assertInstanceOf(PasswordBroker::class, $broker);
        $this->assertInstanceOf(DatabaseTokenRepository::class, $broker->getRepository());
        $token = $broker->createToken($this->user);
        $stored = DB::table('password_reset_tokens')->sole();
        $this->assertNotSame($token, $stored->token);
        $this->assertTrue(Hash::check($token, $stored->token));
        $this->travel(7199)->seconds();
        $this->assertTrue($this->setup->valid($this->user->email, $token));
        $this->travel(1)->seconds();
        $this->assertTrue($this->setup->valid($this->user->email, $token)); // Laravel isPast: exact instant valid.
        $this->travel(1)->seconds();
        $this->assertFalse($this->setup->valid($this->user->email, $token));
        $this->ttl(3);
        $this->assertTrue($this->setup->valid($this->user->email, $token));
        $this->setup->broker()->deleteToken($this->user);
        $this->assertFalse($this->setup->valid($this->user->email, $token));
    }

    public function test_approval_queues_once_after_commit_and_rollback_does_not_queue(): void
    {
        Log::spy();
        $this->user->update(['status' => 'pending']);
        DB::beginTransaction();
        app(PsychologistActions::class)->run($this->user, $this->admin, 'approved');
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        Log::shouldNotHaveReceived('info', ['mail.password_setup.queued', ['user_id' => $this->user->id]]);
        $this->assertDatabaseCount('jobs', 0);
        $this->actingAs($this->admin)->post('/admin/psychologists/'.$this->user->id.'/approve', ['confirmed' => 1])->assertRedirect();
        $this->assertSame(UserStatus::Approved, $this->user->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        Log::shouldHaveReceived('info')->once()->with('mail.password_setup.queued', ['user_id' => $this->user->id]);
        Mail::assertNothingSent();
        $this->job()->handle($this->setup, app(SettingService::class));
        Mail::assertSent(PasswordSetupMail::class, fn ($mail) => $mail->hasTo($this->user->email));
    }

    public function test_queue_failure_does_not_roll_back_approval_and_existing_password_does_not_queue(): void
    {
        $this->user->update(['status' => 'pending']);
        Log::spy();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('synthetic failure'));
        app(PsychologistActions::class)->run($this->user, $this->admin, 'approved');
        $this->assertSame(UserStatus::Approved, $this->user->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Log::shouldNotHaveReceived('info', ['mail.password_setup.queued', ['user_id' => $this->user->id]]);
        $this->user->refresh()->update(['status' => 'pending', 'password' => 'Synthetic-only-password']);
        app(PsychologistActions::class)->run($this->user, $this->admin, 'approved');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_resend_invalidates_old_link_and_queued_job_and_audits_safely(): void
    {
        config(['mail.default' => 'sendmail']);
        $oldToken = $this->setup->broker()->createToken($this->user);
        $oldJob = new SendPasswordSetup($this->user->id, $oldToken);
        $url = '/admin/psychologists/'.$this->user->id.'/password-setup';
        $this->actingAs($this->admin)->post($url)->assertRedirect();
        $this->assertFalse($this->setup->valid($this->user->email, $oldToken));
        $oldJob->handle($this->setup, app(SettingService::class));
        Mail::assertNothingSent();
        $this->job()->handle($this->setup, app(SettingService::class));
        Mail::assertSent(PasswordSetupMail::class, function ($mail) {
            parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $query);

            return $this->setup->valid($query['email'], basename(parse_url($mail->setupUrl, PHP_URL_PATH)));
        });
        $entry = AuditLog::where('action', 'user.password_setup_resent')->sole();
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertNull($entry->metadata);
        $this->actingAs($this->admin)->post($url)->assertStatus(429);
    }

    public static function ineligible(): array
    {
        return [['pending'], ['rejected'], ['disabled'], ['deleted'], ['admin'], ['password']];
    }

    #[DataProvider('ineligible')]
    public function test_ineligible_account_cannot_use_setup_resend_or_send_job(string $state): void
    {
        $token = $this->setup->broker()->createToken($this->user);
        if ($state === 'deleted') {
            $this->user->delete();
        } else {
            $this->user->update(match ($state) {
                'pending', 'rejected' => ['status' => $state],
                'password' => ['password' => 'Synthetic-only-password'],
                default => [$state => true],
            });
        }
        $this->get('/password/setup/'.$token.'?email='.urlencode($this->user->email))->assertOk()->assertSee('Ссылка недействительна');
        $this->post('/password/setup', ['email' => $this->user->email, 'token' => $token, 'password' => 'Synthetic-only-password', 'password_confirmation' => 'Synthetic-only-password'])->assertStatus(422);
        $this->actingAs($this->admin)->post('/admin/psychologists/'.$this->user->id.'/password-setup')->assertStatus($state === 'deleted' ? 404 : 403);
        (new SendPasswordSetup($this->user->id, $token))->handle($this->setup, app(SettingService::class));
        Mail::assertNothingSent();
    }

    public function test_setup_validation_consumption_rotation_no_login_then_real_login(): void
    {
        $token = $this->setup->broker()->createToken($this->user);
        $url = '/password/setup/'.$token.'?email='.urlencode($this->user->email);
        $this->get($url)->assertOk()->assertSee('name="_token"', false)->assertSee('method="POST"', false);
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $data = ['token' => $token, 'email' => $this->user->email, 'password' => 'short', 'password_confirmation' => 'other'];
        $this->post('/password/setup', $data)->assertStatus(422)->assertSee('не менее 8');
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $this->assertStringNotContainsString('short', serialize(session()->all()));
        $data['password'] = $data['password_confirmation'] = 'Synthetic-only-password';
        $remember = $this->user->remember_token;
        $this->post('/password/setup', $data)->assertOk()->assertSee('Пароль установлен');
        $this->assertGuest();
        $this->assertTrue(Hash::check($data['password'], $this->user->fresh()->password));
        $this->assertNotSame($remember, $this->user->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/password/setup', $data)->assertStatus(422)->assertSee('Ссылка недействительна');
        $this->post('/login', ['email' => $this->user->email, 'password' => $data['password']])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_limits_roles_csrf_and_safe_exception_response(): void
    {
        $url = '/admin/psychologists/'.$this->user->id.'/password-setup';
        $this->post($url)->assertRedirect('/login');
        $this->actingAs($this->user)->post($url)->assertForbidden();
        $this->app->instance('env', 'local');
        $this->actingAs($this->admin)->post($url)->assertStatus(419);
        $this->app->instance('env', 'testing');
        for ($i = 0; $i < 10; $i++) {
            $this->get('/password/setup/invalid?email=synthetic@example.test')->assertOk();
        }
        $this->post('/password/setup', ['token' => 'sensitive-synthetic'])->assertStatus(429)->assertDontSee('sensitive-synthetic');
    }

    public function test_missing_fields_mismatch_expired_token_and_debug_exception_are_safe(): void
    {
        $this->ttl(1);
        $token = $this->setup->broker()->createToken($this->user);
        $this->post('/password/setup')->assertStatus(422);
        $data = ['email' => $this->user->email, 'token' => $token, 'password' => 'Synthetic-password', 'password_confirmation' => 'Different-password'];
        $this->post('/password/setup', $data)->assertStatus(422)->assertSee('Пароли не совпадают');
        $this->travel(3601)->seconds();
        $this->get('/password/setup/'.$token.'?email='.urlencode($this->user->email))->assertOk()->assertSee('Ссылка недействительна')->assertDontSee($token);
        $this->post('/password/setup', $data)->assertStatus(422)->assertDontSee($token);
        config(['app.debug' => true]);
        $this->mock(PasswordSetupService::class)->shouldReceive('valid')->andThrow(new \RuntimeException('synthetic-sensitive-value'));
        $this->get('/password/setup/'.$token.'?email='.urlencode($this->user->email))->assertStatus(500)->assertDontSee($token)->assertDontSee('synthetic-sensitive-value');
        $this->assertStringNotContainsString($token, serialize(session()->all()));
    }

    public function test_smtp_retry_keeps_token_and_base_path_url(): void
    {
        URL::forceRootUrl('https://gruppa.info/cabinet');
        URL::forceScheme('https');
        $token = $this->setup->broker()->createToken($this->user);
        $job = new SendPasswordSetup($this->user->id, $token);
        config(['mail.default' => 'log']);
        try {
            $job->handle($this->setup, app(SettingService::class));
            $this->fail('Delivery must fail');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString($token, $exception->getMessage());
        }
        $this->assertTrue($this->setup->valid($this->user->email, $token));
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic SMTP outage'));
        try {
            $job->handle($this->setup, app(SettingService::class));
            $this->fail('SMTP must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Password setup delivery failed; retry the queued job.', $exception->getMessage());
        }
        $this->assertTrue($this->setup->valid($this->user->email, $token));
        SuccessfulMailFake::install();
        $job->handle($this->setup, app(SettingService::class));
        Mail::assertSent(PasswordSetupMail::class, fn ($mail) => str_starts_with($mail->setupUrl, 'https://gruppa.info/cabinet/password/setup/') && $mail->ttlHours === app(SettingService::class)->passwordSetupLinkTtlHours());
    }

    public function test_invitation_queued_log_waits_for_outer_commit_and_disappears_on_rollback(): void
    {
        Log::spy();
        DB::beginTransaction();
        $this->setup->invite($this->user->id, $this->admin);
        $this->assertDatabaseCount('jobs', 1);
        Log::shouldNotHaveReceived('info');
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Log::shouldNotHaveReceived('info');

        DB::beginTransaction();
        $this->setup->invite($this->user->id, $this->admin);
        $this->assertDatabaseCount('jobs', 1);
        Log::shouldNotHaveReceived('info');
        DB::commit();
        Log::shouldHaveReceived('info')->once()->with('mail.password_setup.queued', ['user_id' => $this->user->id]);
        Mail::assertNothingSent();
    }

    public static function deliveryTransports(): array
    {
        return [
            ['smtp', false], ['sendmail', false], ['smtp', true], ['sendmail', true],
            ['log', false], ['array', false], ['failover', false], ['roundrobin', false],
        ];
    }

    #[DataProvider('deliveryTransports')]
    public function test_delivery_transports_and_safe_diagnostics(string $transport, bool $outage): void
    {
        config(['mail.default' => $transport]);
        $records = [];
        foreach (['info', 'error'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(function ($event, $context) use (&$records, $level): void {
                $records[] = [$level, $event, $context];
            });
        }
        $token = $this->setup->broker()->createToken($this->user);
        $job = new SendPasswordSetup($this->user->id, $token);
        $private = [$token, $this->user->email, route('password.setup', ['token' => $token, 'email' => $this->user->email])];
        $private[] = 'synthetic-transport-secret';
        $private[] = 'synthetic MIME body';
        $private[] = serialize($job);
        if ($outage) {
            Mail::shouldReceive('to')->once()->andReturnSelf();
            Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException(implode(' ', $private)));
        }
        $supported = in_array($transport, ['smtp', 'sendmail'], true);
        $failed = $outage || ! $supported;
        try {
            $job->handle($this->setup, app(SettingService::class));
            $this->assertFalse($failed);
        } catch (\RuntimeException $exception) {
            $this->assertTrue($failed);
            $this->assertSame('Password setup delivery failed; retry the queued job.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            foreach ($private as $value) {
                $this->assertStringNotContainsString($value, (string) $exception);
            }
        }
        $context = ['user_id' => $this->user->id, 'transport' => $supported ? $transport : 'unsupported', 'attempt' => 1];
        $this->assertSame(['info', 'mail.password_setup.started', $context], $records[0]);
        $this->assertSame($failed
            ? ['error', 'mail.password_setup.failed', $context + ['exception_class' => \RuntimeException::class]]
            : ['info', 'mail.password_setup.accepted_by_transport', $context], $records[1]);
        $this->assertCount(2, $records);
        foreach ($private as $value) {
            $this->assertStringNotContainsString($value, serialize($records));
        }
        if (! $supported) {
            Mail::assertNothingSent();
        }
        $this->assertTrue($this->setup->valid($this->user->email, $token));
        if (! $failed) {
            Mail::assertSent(PasswordSetupMail::class, 1);
            $this->setup->broker()->deleteToken($this->user);
            $job->handle($this->setup, app(SettingService::class));
            Mail::assertSent(PasswordSetupMail::class, 1);
            $this->assertCount(3, $records); // Started, but stale token never accepted.
        }
    }
}
