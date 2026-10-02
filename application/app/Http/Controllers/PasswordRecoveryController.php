<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PasswordRecoveryController extends Controller
{
    public function show(): Response
    {
        return $this->page();
    }

    public function store(Request $request, PasswordSetupService $setup): Response
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $email = Str::lower(trim($email));
        }
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Укажите email.',
            'email.string' => 'Укажите корректный email.',
            'email.email' => 'Укажите корректный email.',
            'email.max' => 'Email не должен превышать 255 символов.',
        ]);
        if ($validator->fails()) {
            return $this->page('validation', is_string($email) ? $email : '',
                ['email' => $validator->errors()->first('email')], 422);
        }

        try {
            $user = User::query()->where('email', $email)->first();
            if (PasswordSetupService::eligible($user)) {
                $setup->invite($user->id);
            }
        } catch (Throwable $exception) {
            // Public outcome must not disclose an eligible account during an outage.
            Log::error('Password recovery request could not be queued.', ['exception_type' => $exception::class]);
        }

        return $this->page('success');
    }

    public function rateLimited(array $headers): Response
    {
        return $this->page('rate-limit', status: 429)->withHeaders($headers);
    }

    private function page(string $variant = 'normal', string $email = '', array $errors = [], int $status = 200): Response
    {
        return response()->view('auth.password-forgot', [
            'prototype' => false, 'variant' => $variant, 'email' => $email,
            'errors' => $errors, 'links' => ['login' => route('login')],
        ], $status, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
