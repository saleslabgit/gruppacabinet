<?php

namespace App\Http\Requests;

use App\Enums\GroupStatus;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Group::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(GroupStatus::class)],
            'free' => ['nullable', 'in:free,paid'],
            'sort' => ['nullable', 'in:created_at,published_at,expires_at'],
            'quick' => ['nullable', 'in:approved,abandoned'],
        ];
    }
}
