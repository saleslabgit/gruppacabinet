<?php

namespace App\Http\Requests\Admin;

use App\Models\DictionaryItem;
use App\Models\User;
use App\Support\DateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PsychologistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $psychologist = $this->route('psychologist');

        return $psychologist instanceof User
            ? $this->user()->can('manage', $psychologist)
            : $this->user()->can('viewAny', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $psychologist = $this->route('psychologist');
        $educationIds = DictionaryItem::query()
            ->whereHas('dictionary', fn ($query) => $query->where('code', 'education_type'))
            ->where(function ($query) use ($psychologist): void {
                $query->where('active', true);
                if ($psychologist instanceof User && $psychologist->education_type_id !== null) {
                    $query->orWhere('id', $psychologist->education_type_id);
                }
            })->pluck('id')->all();
        $rules = [
            'email' => ['required', 'email', 'max:255', Rule::unique('gp_users', 'email')->whereNull('deleted_at')->ignore($psychologist instanceof User ? $psychologist->id : null)],
            'education_type_id' => ['nullable', 'integer', Rule::in($educationIds)],
            'graduation_year' => ['nullable', 'integer', 'between:0,65535'],
            'training_hours' => ['nullable', 'integer', 'between:0,4294967295'],
            'groups_conducted_count' => ['nullable', 'integer', 'between:0,4294967295'],
            'license_expires_at' => ['nullable', 'date_format:Y-m-d'],
            'group_leading_experience' => ['nullable', 'string', 'max:16000'],
            'personal_data_consent_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:1970-01-02', 'before:2038-01-19'],
            'documents_confirmed' => ['nullable', 'boolean'],
            'education_confirmed' => ['nullable', 'boolean'],
            'live_session_ready' => ['nullable', 'boolean'],
        ];
        foreach (['last_name', 'first_name', 'middle_name', 'phone', 'other_education', 'modality_program', 'training_center', 'license_number', 'personal_data_consent_version'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }
        if (! $psychologist instanceof User) {
            $rules['free'] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email.',
            'email.email' => 'Укажите корректный email.',
            'email.unique' => 'Этот email уже используется.',
            'string' => 'Введите текстовое значение.',
            'max' => 'Допустимо не более :max символов.',
            'integer' => 'Введите целое число.',
            'between' => 'Введите число от :min до :max.',
            'boolean' => 'Выберите допустимое значение.',
            'date_format' => 'Укажите корректную дату.',
            'after_or_equal' => 'Дата согласия должна быть не раньше :date.',
            'before' => 'Дата согласия должна быть раньше :date.',
            'education_type_id.in' => 'Выберите доступный тип образования.',
            'free.required' => 'Выберите тариф.',
        ];
    }

    public function profileData(): array
    {
        $data = $this->validated();
        if (! empty($data['personal_data_consent_at'])) {
            $data['personal_data_consent_at'] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['personal_data_consent_at'], DateTimeFormatter::DISPLAY_TIMEZONE)->utc();
        }

        return $data;
    }
}
