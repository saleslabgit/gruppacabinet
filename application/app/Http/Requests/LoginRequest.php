<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email.',
            'email.email' => 'Укажите корректный email.',
            'email.string' => 'Укажите корректный email.',
            'email.max' => 'Email не должен превышать 255 символов.',
            'password.required' => 'Укажите пароль.',
            'password.string' => 'Укажите корректный пароль.',
        ];
    }

    public function throttleKey(): string
    {
        return 'login:'.hash('sha256', $this->string('email')->toString().'|'.$this->ip());
    }
}
