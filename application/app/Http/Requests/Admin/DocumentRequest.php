<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('psychologist'));
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Выберите тип документа.',
            'type.in' => 'Выберите допустимый тип документа.',
            'file.required' => 'Выберите файл.',
            'file.file' => 'Не удалось прочитать файл.',
            'file.uploaded' => 'Не удалось загрузить файл. Проверьте размер файла.',
            'file.max' => 'Размер файла не должен превышать :max КБ.',
            'file.mimetypes' => 'Допустимы только файлы PDF, JPEG или PNG.',
        ];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(config('psychologist_documents.types')))],
            'file' => ['required', 'file', 'max:'.config('psychologist_documents.max_kb'), 'mimetypes:'.implode(',', config('psychologist_documents.mime_types'))],
        ];
    }
}
