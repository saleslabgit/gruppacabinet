<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('refund', $this->route('payment'));
    }

    public function rules(): array
    {
        return ['refund_comment' => ['required', 'string', 'max:16000'], 'confirmed' => ['accepted']];
    }
}
