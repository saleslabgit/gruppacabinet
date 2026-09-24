<?php

namespace App\Http\Requests;

use App\Models\DictionaryItem;
use App\Models\Group;
use App\Services\GroupWorkflow;
use App\Support\DateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
{
    public function group(): ?Group
    {
        $group = $this->route('group');
        if ($group instanceof Group) {
            return $group;
        }
        if ($group === null) {
            return null;
        }

        return Group::query()->visibleToPsychologist($this->user()->id)->findOrFail($group);
    }

    public function authorize(): bool
    {
        $group = $this->group();

        return $group ? $this->user()->can($this->routeIs('*.submit') ? 'submit' : 'update', $group) : $this->user()->can('viewAny', Group::class);
    }

    public function rules(): array
    {
        $group = $this->group();
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:16000'],
            'schedule' => ['required', 'string', 'max:16000'],
            'meeting_duration_minutes' => ['required', 'integer', 'between:1,4294967295'],
            'participant_capacity' => ['required', 'integer', 'between:1,4294967295'],
            // 16 whole digits stay within PHP's signed integer range after conversion.
            'meeting_price' => ['required', 'string', 'regex:/\A[0-9]{1,16}(?:[.,][0-9]{1,2})?\z/'],
        ];
        foreach (['format_id' => 'group_format', 'gender_id' => 'gender'] as $field => $code) {
            $ids = DictionaryItem::query()->whereHas('dictionary', fn ($q) => $q->where('code', $code))
                ->where(function ($q) use ($group, $field): void {
                    $q->where('active', true);
                    if ($group?->$field !== null) {
                        $q->orWhere('id', $group->$field);
                    }
                })->pluck('id')->all();
            $rules[$field] = ['required', 'integer', Rule::in($ids)];
        }
        if ($group === null) {
            $rules['owner_id'] = ['required', 'integer', Rule::exists('gp_users', 'id')->where('admin', false)->where('disabled', false)->where('status', 'approved')->whereNull('deleted_at')];
        }

        if ($group !== null && $this->user()->admin) {
            foreach (['published_at', 'expires_at'] as $field) {
                $rules[$field] = ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i,Y-m-d\\TH:i:s',
                    'after_or_equal:1970-01-01T03:00:01', 'before_or_equal:2038-01-19T06:14:07'];
            }
        }

        return $rules;
    }

    public function content(): array
    {
        $data = Arr::only($this->validated(), GroupWorkflow::FIELDS);
        $parts = preg_split('/[.,]/', $data['meeting_price']);
        $data['meeting_price'] = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');

        if ($this->group() !== null && $this->user()->admin) {
            foreach (Arr::only($this->validated(), ['published_at', 'expires_at']) as $field => $value) {
                $data[$field] = $value === null ? null : CarbonImmutable::parse($value, DateTimeFormatter::DISPLAY_TIMEZONE)->utc();
            }
        }

        return $data;
    }

    public function messages(): array
    {
        return ['date_format' => 'Введите дату и время в формате ГГГГ-ММ-ДД ЧЧ:ММ.',
            'after_or_equal' => 'Дата должна быть не раньше :date (Минск).', 'before_or_equal' => 'Дата должна быть не позже :date (Минск).',
            'required' => 'Заполните это поле.', 'string' => 'Введите текст.', 'max' => 'Допустимо не более :max символов.',
            'integer' => 'Введите целое число.', 'between' => 'Введите число от :min до :max.',
            'in' => 'Выберите доступное значение справочника.', 'exists' => 'Выберите доступного психолога.',
            'meeting_price.regex' => 'Введите неотрицательную сумму с не более чем двумя знаками после запятой (до 16 цифр целой части).'];
    }
}
