<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\SessionInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost:8080/cabinet');
    }

    private function user(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => 'person@example.test', 'password' => 'password',
            'status' => UserStatus::Approved, 'admin' => false, 'disabled' => false,
        ], $attributes));
    }

    public static function roles(): array
    {
        return [[false, 'psychologist.home', '/', '/admin'], [true, 'admin.home', '/admin', '/']];
    }

    #[DataProvider('roles')]
    public function test_login_regenerates_session_and_obeys_role_boundaries(bool $admin, string $route, string $home, string $denied): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user(['admin' => $admin]);
        $this->get('http://localhost/login')->assertOk()->assertViewIs('auth.login')
            ->assertSee('method="POST"', false)->assertSee(route('login.store'), false)
            ->assertSee('name="_token"', false)->assertDontSee('data-prototype-form');
        $oldId = session()->getId();
        session()->put('login_marker', 'preserved');
        session()->save();
        $this->withCookie(config('session.cookie'), $oldId);
        $this->post('http://localhost/login', ['email' => ' PERSON@example.test ', 'password' => 'password'])
            ->assertRedirect(route($route));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertSame('preserved', session('login_marker'));
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $response = $this->get('http://localhost'.$home)->assertOk()->assertSee('method="POST"', false)
            ->assertSee(route('logout'), false)->assertDontSee('_prototype');
        if ($admin) {
            $response->assertViewIs('admin.home')->assertDontSee('Требуют внимания')->assertSee(route('admin.groups.index'), false)
                ->assertSee('Управляйте анкетами, группами и заявками участников.');
        } else {
            $response->assertViewIs('psychologist.groups.index')->assertViewHas('canCreateGroup', true)
                ->assertSee(route('psychologist.groups.store'), false);
        }
        $this->get('http://localhost'.$denied)->assertForbidden();
    }

    public static function failures(): array
    {
        return [['unknown'], ['password'], ['pending'], ['rejected'], ['disabled'], ['deleted'], ['null-password']];
    }

    #[DataProvider('failures')]
    public function test_login_failures_have_identical_generic_semantics(string $reason): void
    {
        if ($reason !== 'unknown') {
            $user = $this->user([
                'status' => in_array($reason, ['pending', 'rejected']) ? $reason : 'approved',
                'disabled' => $reason === 'disabled',
                'password' => $reason === 'null-password' ? null : 'password',
            ]);
            if ($reason === 'deleted') {
                $user->delete();
            }
        }
        $this->post('http://localhost/login', [
            'email' => 'person@example.test', 'password' => $reason === 'password' ? 'wrong' : 'password',
        ])->assertRedirect(route('login'))->assertSessionHas('login_state', 'error')
            ->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->get('http://localhost/login')->assertSee('Не удалось войти. Проверьте email и пароль.')
            ->assertDontSee('Доступ к кабинету ограничен');
    }

    public function test_validation_is_rendered_in_shared_fields_without_flashing_password(): void
    {
        $this->from('/login')->post('http://localhost/login', ['email' => 'invalid', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password'])->assertSessionMissing('_old_input.password');
        $this->get('http://localhost/login')->assertOk()->assertSee('Укажите корректный email.')
            ->assertSee('Укажите пароль.')->assertSee('is-invalid');
    }

    public function test_throttle_blocks_sixth_attempt_without_authentication(): void
    {
        $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('http://localhost/login', ['email' => 'person@example.test', 'password' => 'wrong'])
                ->assertSessionHas('login_state', 'error');
        }
        Auth::shouldReceive('guard')->never();
        $this->post('http://localhost/login', ['email' => 'PERSON@example.test', 'password' => 'password'])
            ->assertSessionHas('login_state', 'rate-limit');
        $this->get('http://localhost/login')->assertSee('Слишком много попыток входа. Попробуйте позже.');
    }

    public function test_throttle_expires_after_sixty_seconds(): void
    {
        $user = $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->travel(61)->seconds();
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('psychologist.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_success_clears_counter_and_counters_are_per_email_and_ip(): void
    {
        $user = $this->user();
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->assertSame(4, RateLimiter::attempts($key));
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->post('http://localhost/logout');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('http://localhost/login', ['email' => 'other@example.test', 'password' => 'wrong'])
            ->assertSessionHas('login_state', 'error');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.5'])
            ->post('http://localhost/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('psychologist.home'));
    }

    public function test_guests_and_generated_urls_respect_base_path(): void
    {
        foreach (['/', '/admin'] as $path) {
            $this->get('http://localhost'.$path)->assertRedirect('http://localhost:8080/cabinet/login');
        }
        foreach (['login' => '/login', 'login.store' => '/login', 'logout' => '/logout', 'psychologist.home' => '', 'admin.home' => '/admin'] as $name => $path) {
            $this->assertSame('http://localhost:8080/cabinet'.$path, route($name));
        }
    }

    public static function revokedStates(): array
    {
        return [['disabled'], ['rejected'], ['pending'], ['deleted'], ['missing']];
    }

    #[DataProvider('revokedStates')]
    public function test_existing_database_session_is_revoked_on_next_request(string $state): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user();
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password']);
        $oldId = session()->getId();
        session()->put('private_marker', 'must disappear');
        session()->save();
        $this->withCookie(config('session.cookie'), $oldId);
        if ($state === 'deleted') {
            $user->delete();
        } elseif ($state === 'missing') {
            $user->forceDelete();
        } else {
            DB::table('gp_users')->where('id', $user->id)->update(
                $state === 'disabled' ? ['disabled' => true] : ['status' => $state],
            );
        }
        // A fresh guard simulates the next HTTP request, including deleted-user lookup.
        Auth::forgetGuards();
        $this->get('http://localhost/')->assertRedirect(route('login'))->assertSessionHas('access_revoked')
            ->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
    }

    public function test_unchanged_approved_database_session_continues_to_work(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user();
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password']);
        $this->withCookie(config('session.cookie'), session()->getId());
        Auth::forgetGuards();
        $this->get('http://localhost/')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_session_invalidator_only_removes_target_sessions_and_rotates_recaller(): void
    {
        $user = $this->user(['remember_token' => 'previous-token']);
        $other = $this->user(['email' => 'other@example.test']);
        foreach (['first' => $user->id, 'second' => $user->id, 'other' => $other->id, 'guest' => null] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        app(SessionInvalidator::class)->invalidate($user);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other', 'user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'guest', 'user_id' => null]);
        $this->assertNotSame('previous-token', $user->fresh()->remember_token);
    }

    public function test_logout_is_post_only_and_destroys_session_and_csrf_token(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user();
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password']);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('http://localhost/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($user);
        $id = session()->getId();
        $token = session()->token();
        session()->put('private_marker', 'must disappear');
        $this->post('http://localhost/logout')->assertRedirect(route('login'))->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->get('http://localhost/')->assertRedirect(route('login'));
    }
}
