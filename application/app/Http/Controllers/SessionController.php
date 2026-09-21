<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Requests\LoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login', ['prototype' => false, 'variant' => 'normal']);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = $request->throttleKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->route('login')->with('login_state', 'rate-limit')->onlyInput('email');
        }

        if (! Auth::guard('web')->attempt([
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'status' => UserStatus::Approved->value,
            'disabled' => false,
        ])) {
            RateLimiter::hit($key, 60);

            return redirect()->route('login')->with('login_state', 'error')->onlyInput('email');
        }

        $request->session()->regenerate();
        RateLimiter::clear($key);

        return redirect()->route(Auth::guard('web')->user()->admin ? 'admin.home' : 'psychologist.home');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
