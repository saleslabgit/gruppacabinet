<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Services\SettingService;
use App\Support\BynAmount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class SettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Setting::class);
    }

    public function rules(): array
    {
        $money = function (string $attribute, mixed $value, Closure $fail): void {
            try {
                BynAmount::parse((string) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
        $rules = [
            'confirmed' => ['accepted'],
            'placement_price' => ['bail', 'nullable', 'string', $money],
            'extension_price' => ['bail', 'nullable', 'string', $money],
        ];
        foreach (SettingService::INTEGER_KEYS as $key) {
            $rules[$key] = ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX];
        }
        $rules['placement_duration_days'][] = 'max:'.SettingService::maximumPlacementDays();
        $rules['expiry_warning_days'][] = 'lt:placement_duration_days';

        return $rules;
    }

    /** @return array<string, int|null> */
    public function values(): array
    {
        $values = [];
        foreach (SettingService::INTEGER_KEYS as $key) {
            $values[$key] = (int) $this->validated($key);
        }
        foreach (['placement', 'extension'] as $type) {
            $price = $this->validated($type.'_price');
            $values[$type.'_price_minor_units'] = $price === null ? null : BynAmount::parse($price);
        }

        return $values;
    }

    public function messages(): array
    {
        return ['required' => 'Заполните это поле.', 'string' => 'Введите сумму текстом.',
            'integer' => 'Введите целое число.', 'min' => 'Введите положительное число.',
            'max' => 'Значение превышает технический предел :max.',
            'lt' => 'Срок предупреждения должен быть меньше срока размещения.', 'accepted' => 'Подтвердите изменение настроек.'];
    }
}
