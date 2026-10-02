<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordSetup;
use App\Mail\PasswordSetupMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PasswordSetupService;
use App\Services\SessionInvalidator;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SuccessfulMailFake;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
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
        $this->seed(DatabaseSeeder::class);
        $this->user = User::where('email', 'psychologist@gruppa.test')->sole();
        $this->admin = User::where('email', 'admin@gruppa.test')->sole();
        $this->setup = app(PasswordSetupService::class);
        SuccessfulMailFake::install();
    }

    private function requestLink(string $email): TestResponse
    {
        return $this->post('http://localhost/password/forgot', ['email' => $email]);
    }

    private function latestLink(): string
    {
        $job = unserialize(json_decode(DB::table('jobs')->latest('id')->value('payload'), true)['data']['command']);
        $job->handle($this->setup, app(SettingService::class));

        return Mail::sent(PasswordSetupMail::class)->last()->setupUrl;
    }

    private function token(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_real_form_login_entry_validation_csrf_and_base_path(): void
    {
        URL::forceRootUrl('https://gruppa.info/cabinet');
        URL::forceScheme('https');
        $this->get('http://localhost/login')->assertOk()->assertSee('Забыли пароль?')
            ->assertSee('https://gruppa.info/cabinet/password/forgot', false);
        $this->get('http://localhost/password/forgot')->assertOk()->assertViewIs('auth.password-forgot')
            ->assertSee('name="_token"', false)->assertSee('method="POST"', false)
            ->assertSee('https://gruppa.info/cabinet/password/forgot', false)
            ->assertDontSee('data-prototype-form')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->requestLink('not-an-email')->assertStatus(422)->assertSee('Укажите корректный email.');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->app->instance('env', 'local');
        $this->requestLink($this->user->email)->assertStatus(419);
    }

    public static function passwordPresence(): array
    {
        return [[null], ['password']];
    }

    #[DataProvider('passwordPresence')]
    public function test_eligible_accounts_receive_queued_link_with_neutral_copy(?string $password): void
    {
        $this->user->update(['password' => $password]);
        URL::forceRootUrl('https://gruppa.info/cabinet');
        URL::forceScheme('https');
        $this->requestLink(' PSYCHOLOGIST@GRUPPA.TEST ')->assertOk()->assertViewHas('variant', 'success');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $url = $this->latestLink();
        $this->assertStringStartsWith('https://gruppa.info/cabinet/password/setup/', $url);
        $this->assertTrue($this->setup->valid($this->user->email, $this->token($url)));
        $mail = Mail::sent(PasswordSetupMail::class)->sole();
        foreach (['mail.password-setup', 'mail.password-setup-text'] as $view) {
            $body = view($view, ['setupUrl' => $mail->setupUrl, 'ttlHours' => $mail->ttlHours])->render();
            $this->assertStringContainsString('Создана ссылка для установки нового пароля', $body);
            $this->assertStringContainsString('одноразовая', $body);
            $this->assertStringContainsString($mail->ttlHours.' ч.', $body);
            $this->assertStringContainsString('проигнорируйте', $body);
            $this->assertStringContainsString('не меняют пароль', $body);
            $this->assertStringNotContainsString('анкета принята', $body);
        }
    }

    public static function ineligible(): array
    {
        return [['unknown'], ['pending'], ['rejected'], ['disabled'], ['deleted'], ['admin']];
    }

    #[DataProvider('ineligible')]
    public function test_public_result_is_identical_for_ineligible_or_unknown_email(string $state): void
    {
        $success = $this->requestLink($this->user->email)->assertOk();
        $before = $this->user->fresh()->password;
        $storedToken = DB::table('password_reset_tokens')->value('token');
        $this->travel(61)->seconds();
        $email = $this->user->email;
        if ($state === 'unknown') {
            $email = 'unknown@example.test';
        } elseif ($state === 'deleted') {
            $this->user->delete();
        } else {
            $this->user->update(in_array($state, ['pending', 'rejected']) ? ['status' => $state] : [$state => true]);
        }
        $response = $this->requestLink($email)->assertOk();
        $this->assertSame($success->getContent(), $response->getContent());
        foreach (['Cache-Control', 'Referrer-Policy', 'Location', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            $this->assertSame($success->headers->get($header), $response->headers->get($header));
        }
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($storedToken, DB::table('password_reset_tokens')->value('token'));
        $this->assertSame($before, User::withTrashed()->findOrFail($this->user->id)->password);
        Mail::assertNothingSent();
        $this->requestLink($email)->assertStatus(429)->assertViewHas('variant', 'rate-limit');
    }

    public function test_public_and_admin_issuance_replace_each_other_and_stale_jobs_do_not_send(): void
    {
        $this->requestLink($this->user->email)->assertOk();
        $old = $this->token($this->latestLink());
        $this->actingAs($this->admin)->post('/admin/psychologists/'.$this->user->id.'/password-setup')->assertRedirect();
        $adminToken = $this->token($this->latestLink());
        $this->assertFalse($this->setup->valid($this->user->email, $old));
        $this->assertTrue($this->setup->valid($this->user->email, $adminToken));
        $this->travel(61)->seconds();
        $this->requestLink($this->user->email)->assertOk();
        $latest = $this->token($this->latestLink());
        $this->assertFalse($this->setup->valid($this->user->email, $adminToken));
        $this->assertTrue($this->setup->valid($this->user->email, $latest));
        (new SendPasswordSetup($this->user->id, $adminToken))->handle($this->setup, app(SettingService::class));
        Mail::assertSent(PasswordSetupMail::class, 3);
        $this->get('/password/setup/'.$old.'?email='.urlencode($this->user->email))->assertSee('Восстановить пароль');
    }

    public function test_limits_use_ip_and_normalized_email_independently_without_plaintext_keys(): void
    {
        $this->requestLink($this->user->email)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->requestLink(' PSYCHOLOGIST@GRUPPA.TEST ')->assertStatus(429)
            ->assertSee('Попробуйте через минуту.')->assertHeader('Retry-After');
        $this->assertDatabaseCount('jobs', 1);
        for ($i = 0; $i < 4; $i++) {
            $this->requestLink('unknown'.$i.'@example.test')->assertOk();
        }
        $this->requestLink('another@example.test')->assertStatus(429);
        $request = Request::create('/password/forgot', 'POST', ['email' => ' PSYCHOLOGIST@GRUPPA.TEST ']);
        $limits = RateLimiter::limiter('password-recovery')($request);
        $this->assertSame('email:'.hash('sha256', $this->user->email), $limits[1]->key);
        $this->travel(61)->seconds();
        $this->requestLink($this->user->email)->assertOk();
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_queue_failure_preserves_old_token_and_generic_public_result_and_safe_admin_error(): void
    {
        $success = $this->requestLink('unknown@example.test')->assertOk();
        $old = $this->setup->broker()->createToken($this->user);
        Log::spy();
        Bus::shouldReceive('dispatch')->twice()->andThrow(new \RuntimeException($this->user->email.' '.$old.' synthetic private body'));
        $failure = $this->requestLink($this->user->email)->assertOk();
        $this->assertSame($success->getContent(), $failure->getContent());
        $this->assertSame($success->headers->get('Cache-Control'), $failure->headers->get('Cache-Control'));
        $this->assertTrue($this->setup->valid($this->user->email, $old));
        $this->assertDatabaseCount('jobs', 0);
        Log::shouldHaveReceived('error')->once()->with('Password recovery request could not be queued.', ['exception_type' => \RuntimeException::class]);
        Log::shouldNotHaveReceived('info');
        $this->actingAs($this->admin)->post('/admin/psychologists/'.$this->user->id.'/password-setup')
            ->assertRedirect()->assertSessionHasErrors(['action' => 'Не удалось поставить письмо в очередь. Повторите отправку позже.']);
        $this->assertTrue($this->setup->valid($this->user->email, $old));
        $this->assertSame(0, AuditLog::where('action', 'user.password_link_sent')->count());
    }

    public function test_password_replacement_revokes_only_target_sessions_and_old_password(): void
    {
        foreach (['target-one' => $this->user->id, 'target-two' => $this->user->id, 'other' => $this->admin->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        $remember = $this->user->remember_token;
        $this->requestLink($this->user->email)->assertOk();
        $url = $this->latestLink();
        $this->get($url)->assertOk()->assertSee('Создайте новый пароль')->assertDontSee('первого входа');
        $data = ['email' => $this->user->email, 'token' => $this->token($url), 'password' => 'New-synthetic-password', 'password_confirmation' => 'New-synthetic-password'];
        $this->post('/password/setup', $data)->assertOk()->assertSee('Пароль установлен');
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other']);
        $this->assertNotSame($remember, $this->user->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/password/setup', $data)->assertStatus(422);
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertSessionHas('login_state', 'error');
        $this->assertGuest();
        $this->post('/login', ['email' => $this->user->email, 'password' => $data['password']])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public static function currentSession(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('currentSession')]
    public function test_current_target_session_logs_out_but_another_account_stays_authenticated(bool $target): void
    {
        config(['session.driver' => 'database']);
        $actor = $target ? $this->user : $this->admin;
        $this->post('/login', ['email' => $actor->email, 'password' => 'password'])->assertRedirect();
        $sessionId = session()->getId();
        $this->assertAuthenticatedAs($actor);
        $token = $this->setup->broker()->createToken($this->user);
        $this->post('/password/setup', ['email' => $this->user->email, 'token' => $token,
            'password' => 'New-password', 'password_confirmation' => 'New-password'])->assertOk();
        if ($target) {
            $this->assertGuest();
            $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
            $this->assertDatabaseMissing('sessions', ['user_id' => $this->user->id]);
        } else {
            $this->assertAuthenticatedAs($actor);
            $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $this->admin->id]);
        }
    }

    public static function revoked(): array
    {
        return [['disabled'], ['rejected'], ['deleted'], ['expired'], ['invalid']];
    }

    #[DataProvider('revoked')]
    public function test_link_rechecks_current_eligibility_and_validity(string $state): void
    {
        $token = $this->setup->broker()->createToken($this->user);
        if ($state === 'deleted') {
            $this->user->delete();
        } elseif ($state === 'expired') {
            $this->travel(app(SettingService::class)->passwordSetupLinkTtlHours() * 3600 + 1)->seconds();
        } elseif ($state === 'invalid') {
            $token = 'invalid-synthetic-token';
        } else {
            $this->user->update($state === 'disabled' ? ['disabled' => true] : ['status' => 'rejected']);
        }
        $this->get('/password/setup/'.$token.'?email='.urlencode($this->user->email))->assertOk()->assertSee('Ссылка недействительна');
        $this->post('/password/setup', ['email' => $this->user->email, 'token' => $token, 'password' => 'New-password', 'password_confirmation' => 'New-password'])->assertStatus(422);
        $this->assertFalse($this->setup->complete($this->user->email, $token, 'New-password'));
        $this->assertTrue(Hash::check('password', User::withTrashed()->findOrFail($this->user->id)->password));
    }

    public function test_session_invalidation_failure_rolls_back_password_and_token(): void
    {
        $token = $this->setup->broker()->createToken($this->user);
        $this->mock(SessionInvalidator::class)->shouldReceive('invalidate')->once()->andThrow(new \RuntimeException('synthetic failure'));
        $this->post('/password/setup', ['email' => $this->user->email, 'token' => $token, 'password' => 'New-password', 'password_confirmation' => 'New-password'])->assertStatus(500);
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        $this->assertTrue($this->setup->valid($this->user->email, $token));
    }

    #[DataProvider('passwordPresence')]
    public function test_admin_form_and_new_and_historical_audit_display(?string $password): void
    {
        $this->user->update(['password' => $password]);
        AuditLog::create(['entity_type' => 'user', 'entity_id' => $this->user->id, 'actor_id' => $this->admin->id, 'actor_type' => 'user', 'action' => 'user.password_setup_resent']);
        $path = '/admin/psychologists/'.$this->user->id;
        $this->actingAs($this->admin)->get($path)->assertOk()->assertSee('Отправить ссылку для нового пароля')->assertSee('Повторная отправка ссылки установки пароля');
        $this->post($path.'/password-setup')->assertRedirect();
        $entry = AuditLog::where('action', 'user.password_link_sent')->sole();
        $this->assertNull($entry->metadata);
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->get($path)->assertOk()->assertSee('Отправка ссылки для нового пароля');
    }
}
