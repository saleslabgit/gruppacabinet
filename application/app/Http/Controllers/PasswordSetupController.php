<?php

namespace App\Http\Controllers;

use App\Services\PasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PasswordSetupController extends Controller
{
    public function show(Request $request, string $token, PasswordSetupService $setup): Response
    {
        $email = is_string($request->input('email')) ? $request->input('email') : '';
        $valid = strlen($token) <= 255 && strlen($email) <= 255 && $setup->valid($email, $token);

        return $this->page($valid ? 'normal' : 'invalid', $valid ? $email : '', $valid ? $token : '');
    }

    public function store(Request $request, PasswordSetupService $setup): Response
    {
        $validator = Validator::make($request->only('email', 'token', 'password', 'password_confirmation'), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'password.min' => 'Пароль должен содержать не менее 8 символов.',
            'password.max' => 'Пароль должен содержать не более 255 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.required' => 'Введите пароль.',
            'password_confirmation.required' => 'Повторите пароль.',
        ]);
        if ($validator->errors()->hasAny(['email', 'token'])) {
            return $this->page('invalid', status: 422);
        }
        $email = $request->string('email')->toString();
        $token = $request->string('token')->toString();
        if (! $setup->valid($email, $token)) {
            return $this->page('invalid', status: 422);
        }
        if ($validator->fails()) {
            return $this->page('normal', $email, $token, $validator->errors()->messages(), 422);
        }
        $success = $setup->complete($email, $token, $request->string('password')->toString());

        return $this->page($success ? 'success' : 'invalid', status: $success ? 200 : 422);
    }

    private function page(string $variant, string $email = '', string $token = '', array $errors = [], int $status = 200): Response
    {
        return response()->view('auth.password', [
            'prototype' => false, 'variant' => $variant, 'user' => ['email' => $email], 'setupToken' => $token,
            'errors' => array_map(fn ($messages) => $messages[0], $errors), 'links' => ['login' => route('login')],
        ], $status, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
