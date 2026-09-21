<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Jobs\SendPasswordSetup;
use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordSetupService
{
    public function __construct(private SettingService $settings) {}

    public function broker(): PasswordBroker
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new PasswordBroker(new DatabaseTokenRepository(
            DB::connection(), app('hash'), config('auth.passwords.users.table'), $key,
            $this->settings->passwordSetupLinkTtlHours() * 3600, 60,
        ), Auth::createUserProvider('users'));
    }

    public static function eligible(?User $user): bool
    {
        return $user && ! $user->admin && ! $user->disabled && ! $user->trashed()
            && $user->status === UserStatus::Approved && $user->password === null;
    }

    public function invite(int $userId, ?User $actor = null): void
    {
        // The database queue insert and token replacement commit together.
        DB::transaction(function () use ($userId, $actor): void {
            $user = User::query()->lockForUpdate()->find($userId);
            if ($actor) {
                abort_unless($user !== null, 404);
                Gate::forUser($actor)->authorize('passwordSetup', $user);
            }
            if (! self::eligible($user)) {
                if ($actor) {
                    throw ValidationException::withMessages(['action' => 'Установка пароля недоступна.']);
                }

                return;
            }
            $token = $this->broker()->createToken($user);
            Bus::dispatch((new SendPasswordSetup($user->id, $token))->onConnection('database')->beforeCommit());
            if ($actor) {
                app(AuditService::class)->record('user', $user->id, 'user.password_setup_resent', [], $actor);
            }
        });
    }

    public function valid(string $email, #[\SensitiveParameter] string $token): bool
    {
        $user = User::query()->where('email', $email)->first();

        return self::eligible($user) && $this->broker()->tokenExists($user, $token);
    }

    public function complete(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        return DB::transaction(function () use ($email, $token, $password): bool {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if (! self::eligible($user)) {
                return false;
            }
            $broker = $this->broker();
            if (! $broker->tokenExists($user, $token)) {
                return false;
            }
            $user->update(['password' => $password, 'remember_token' => Str::random(60)]);
            $broker->deleteToken($user);

            return true;
        });
    }
}
