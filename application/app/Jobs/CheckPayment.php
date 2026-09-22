<?php

namespace App\Jobs;

use App\Payments\PaymentRecovery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $paymentId) {}

    public function uniqueId(): string
    {
        return 'payment-check:'.$this->paymentId;
    }

    public function handle(PaymentRecovery $recovery): void
    {
        $recovery->check($this->paymentId);
    }
}
