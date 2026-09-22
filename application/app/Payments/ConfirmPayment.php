<?php

namespace App\Payments;

use App\Enums\GroupStatus;
use App\Enums\PaymentStatus;
use App\Models\Group;
use App\Models\Payment;
use App\Services\GroupStatusTransitionService;
use Illuminate\Support\Facades\DB;

class ConfirmPayment
{
    public function apply(int $paymentId, ProviderResult $result): string
    {
        return DB::transaction(function () use ($paymentId, $result): string {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $group = Group::withTrashed()->lockForUpdate()->findOrFail($payment->group_id);
            $fields = $result->fields;
            if ($result->merchantOrder !== null) {
                if ($result->merchantOrder !== $payment->order_number) {
                    throw new ProviderException('order_mismatch');
                }
            } elseif (! $payment->binding_verified_at || $payment->transaction_id !== $fields['transaction_id']) {
                throw new ProviderException('unbound_transaction');
            }
            if ($fields['payment_method'] !== 'cc' || $fields['currency_id'] !== 'BYN'
                || $payment->currency !== $fields['currency_id'] || $payment->amount !== Webpay::minor($fields['amount'])) {
                throw new ProviderException('payment_mismatch');
            }
            $target = $result->status();
            if ($target === null) {
                throw new ProviderException('unmapped_type');
            }
            if (($payment->transaction_id !== null && $payment->transaction_id !== $fields['transaction_id'])
                || ($payment->provider_order_id !== null && $payment->provider_order_id !== $fields['order_id'])
                || Payment::withTrashed()->where('transaction_id', $fields['transaction_id'])->whereKeyNot($payment->id)->exists()) {
                throw new ProviderException('transaction_conflict');
            }
            if (in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true)) {
                // A later void/refund must never rewrite a successful product effect.
                return $target === PaymentStatus::Succeeded ? 'duplicate' : 'terminal_unchanged';
            }
            if ($payment->status !== PaymentStatus::Pending) {
                if ($payment->status === $target && $payment->transaction_id === $fields['transaction_id']) {
                    return 'duplicate';
                }
                throw new ProviderException('invalid_state');
            }
            $payment->fill(['transaction_id' => $fields['transaction_id'], 'provider_order_id' => $fields['order_id'],
                'binding_verified_at' => $payment->binding_verified_at ?? now()->utc(), 'provider_response' => Webpay::safeFields($fields)]);
            if ($target === PaymentStatus::Succeeded) {
                if ($group->trashed() || ($payment->type === 'placement' && $group->status !== GroupStatus::AwaitingPayment)
                    || ($payment->type === 'extension' && (! $payment->extension_expires_at || ! in_array($payment->extension_status, ['active', 'expired'], true)
                        || ! in_array($group->status, [GroupStatus::Active, GroupStatus::Expired], true)))) {
                    $payment->save();

                    return 'manual_review';
                }
                $transitions = app(GroupStatusTransitionService::class);
                if ($payment->type === 'placement') {
                    $transitions->transition($group, GroupStatus::Draft);
                    $payment->product_effect = 'placement';
                } elseif ($payment->type === 'extension' && $group->status === GroupStatus::Active) {
                    if (! $group->expires_at || $payment->extension_days < 1
                        || $group->expires_at->copy()->addDays($payment->extension_days)->timestamp > 2147483647) {
                        $payment->save();

                        return 'manual_review';
                    }
                    $group->update(['expires_at' => $group->expires_at->copy()->addDays($payment->extension_days), 'expiry_warning_sent_at' => null]);
                    $payment->product_effect = 'extension-active';
                } elseif ($payment->type === 'extension') {
                    $transitions->transition($group, GroupStatus::Approved);
                    $payment->product_effect = 'extension-expired';
                } else {
                    throw new ProviderException('invalid_local_type');
                }
                $payment->paid_at = now()->utc();
            }
            $payment->status = $target;
            $payment->save();

            return 'applied';
        }, 3);
    }
}
