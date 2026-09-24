<?php

namespace App\Support;

class TrainingData
{
    public static function rules(): array
    {
        return [
            'modality_program' => ['nullable', 'string', 'max:255'],
            'training_center' => ['nullable', 'string', 'max:255'],
            'graduation_year' => ['nullable', 'integer', 'between:0,65535'],
            'training_hours' => ['nullable', 'integer', 'between:0,4294967295'],
        ];
    }

    public static function hasValue(array $data): bool
    {
        foreach (array_keys(self::rules()) as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                return true;
            }
        }

        return false;
    }

    public static function normalized(array $data): array
    {
        $values = [];
        foreach (array_keys(self::rules()) as $field) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }
            $values[$field] = $value !== null && in_array($field, ['graduation_year', 'training_hours'], true) ? (int) $value : $value;
        }

        return $values;
    }
}
