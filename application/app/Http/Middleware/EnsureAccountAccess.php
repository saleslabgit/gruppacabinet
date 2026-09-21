<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountAccess extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        // Keep Laravel authentication first, including its normal guest redirect.
        try {
            $this->authenticate($request, ['web']);
        } catch (AuthenticationException $exception) {
            // A deleted user is no longer returned by the Eloquent auth provider.
            if ($request->session()->has($guard->getName())) {
                return $this->revoke($request);
            }

            throw $exception;
        }

        $user = User::query()->find(Auth::guard('web')->id());
        if (! $user || $user->status !== UserStatus::Approved || $user->disabled) {
            return $this->revoke($request);
        }

        Auth::guard('web')->setUser($user);

        return $next($request);
    }

    private function revoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('access_revoked', 'Доступ к кабинету прекращён. Обратитесь к администратору.');
    }
}
