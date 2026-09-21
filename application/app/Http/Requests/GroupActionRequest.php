<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;

class GroupActionRequest extends FormRequest
{
    public function group(): Group
    {
        $group = $this->route('group');

        return $group instanceof Group ? $group : Group::query()->where('owner_id', $this->user()->id)->findOrFail($group);
    }

    public function authorize(): bool
    {
        $ability = $this->routeIs('*.destroy') ? 'delete' : ($this->routeIs('*.activate') ? 'activate' : 'moderate');

        return $this->user()->can($ability, $this->group());
    }

    public function rules(): array
    {
        $rules = ['confirmed' => ['accepted']];
        if ($this->routeIs('*.revision')) {
            $rules['moderator_comment'] = ['required', 'string', 'min:10', 'max:16000'];
        }
        if ($this->routeIs('*.reject')) {
            $rules['rejection_reason'] = ['required', 'string', 'min:10', 'max:16000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['accepted' => 'Подтвердите действие.', 'required' => 'Укажите комментарий.', 'string' => 'Введите текст.',
            'min' => 'Введите не менее :min символов.', 'max' => 'Допустимо не более :max символов.'];
    }
}
