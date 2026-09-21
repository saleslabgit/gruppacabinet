<?php

namespace App\Http\Requests;

use App\Models\Dictionary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DictionaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Dictionary::class);
    }

    public function rules(): array
    {
        return [
            'code' => $this->isMethod('PUT') ? ['prohibited'] : ['required', 'string', 'max:64', 'regex:/\A[a-z0-9_]+\z/', Rule::unique('gp_dictionaries', 'code')],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Заполните это поле.', 'string' => 'Введите текст.',
            'max' => 'Допустимо не более :max символов.', 'code.regex' => 'Код может содержать только a–z, цифры и подчёркивание.',
            'unique' => 'Такой код уже используется.', 'prohibited' => 'Код нельзя изменить после создания.',
            'integer' => 'Введите целое число.', 'between' => 'Введите число от :min до :max.',
            'boolean' => 'Укажите активность элемента.', 'accepted' => 'Подтвердите действие.'];
    }
}
