<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PasswordSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('passwordSetup', $this->route('psychologist'));
    }

    public function rules(): array
    {
        return [];
    }
}
