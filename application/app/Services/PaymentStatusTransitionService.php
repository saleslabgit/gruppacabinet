<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Payment;

class PaymentStatusTransitionService
{
    public function transition(Payment $payment, PaymentStatus $target): Payment
    {
        $current = $payment->status;

        if (! $current->canTransitionTo($target)) {
            throw InvalidStatusTransition::between($current, $target);
        }

        $payment->status = $target;
        $payment->save();

        return $payment;
    }
}
