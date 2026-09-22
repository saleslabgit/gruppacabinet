<?php

namespace App\Payments;

use App\Enums\PaymentStatus;

final readonly class ProviderResult
{
    public function __construct(public array $fields, public ?string $merchantOrder) {}

    public function status(): ?PaymentStatus
    {
        return match ($this->fields['payment_type']) {
            '1', '4' => PaymentStatus::Succeeded,
            '2', '8' => PaymentStatus::Failed,
            '7' => PaymentStatus::Cancelled,
            default => null,
        };
    }
}
