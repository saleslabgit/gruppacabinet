<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->admin && ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved;
    }

    public function view(User $actor, Payment $payment): bool
    {
        return ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved
            && ($actor->admin || $actor->id === $payment->owner_id);
    }

    public function refund(User $actor, Payment $payment): bool
    {
        return $this->viewAny($actor) && $payment->status === PaymentStatus::Succeeded;
    }
}
