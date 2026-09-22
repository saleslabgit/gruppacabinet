<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Payment::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:128'], 'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'type' => ['nullable', 'in:placement,extension'], 'owner_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])]];
    }
}
