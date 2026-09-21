<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PsychologistActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('psychologist'));
    }

    public function messages(): array
    {
        return [
            'confirmed.required' => 'Подтвердите действие.',
            'confirmed.accepted' => 'Подтвердите действие.',
            'free.required' => 'Выберите тариф.',
            'free.boolean' => 'Выберите допустимый тариф.',
        ];
    }

    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'free' => $this->routeIs('admin.psychologists.tariff') ? ['required', 'boolean'] : ['exclude'],
        ];
    }
}
