<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentRecovery
{
    public function due(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Pending || ! $payment->started_at
            || $payment->started_at->gt(now()->utc()->subMinutes(20)) || ! $payment->binding_verified_at
            || ! $payment->transaction_id || $payment->status_check_attempts >= 4) {
            return false;
        }
        if (! $payment->recovery_started_at) {
            return true;
        }
        $elapsed = $payment->recovery_started_at->diffInSeconds(now()->utc(), false);
        $minutes = [0, 10, 30, 55][$payment->status_check_attempts];

        return $elapsed >= $minutes * 60 && $elapsed < 3600;
    }

    public function check(int $paymentId): void
    {
        $lock = Cache::lock('payment-provider-check:'.$paymentId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $payment = DB::transaction(function () use ($paymentId): ?Payment {
                $payment = Payment::query()->lockForUpdate()->find($paymentId);
                if (! $payment || ! $this->due($payment)) {
                    return null;
                }
                $payment->update(['recovery_started_at' => $payment->recovery_started_at ?? now()->utc(),
                    'last_status_check_at' => now()->utc(), 'status_check_attempts' => $payment->status_check_attempts + 1]);

                return $payment;
            });
            if (! $payment) {
                return;
            }
            try {
                $result = app(Webpay::class)->transaction($payment->transaction_id);
                app(ConfirmPayment::class)->apply($payment->id, $result);
            } catch (ProviderException $exception) {
                Log::warning('WEBPAY check unavailable.', ['payment_id' => $payment->id, 'code' => $exception->getMessage()]);
            }
        } finally {
            $lock->release();
        }
    }
}
