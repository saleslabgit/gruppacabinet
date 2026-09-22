<?php

namespace App\Support;

use App\Models\Payment;
use App\Payments\Webpay;

class PaymentPages
{
    public static function data(Payment $payment): array
    {
        return $payment->only(['id', 'order_number', 'transaction_id', 'amount', 'currency', 'type', 'created_at', 'paid_at',
            'refunded_at', 'refund_comment', 'last_status_check_at', 'status_check_attempts', 'product_effect']) + [
                'status' => $payment->status->value, 'manual_review' => $payment->manualReview(),
                'provider_response' => Webpay::safeFields($payment->provider_response ?? []),
                'owner_name' => $payment->owner ? PsychologistPages::profile($payment->owner)['name'] : 'Удалённый психолог',
                'group_title' => $payment->group?->title ?: 'Новая группа', 'owner_id' => $payment->owner_id, 'group_id' => $payment->group_id,
            ];
    }

    public static function detail(Payment $payment, bool $admin): array
    {
        $payment->load(['owner' => fn ($q) => $q->withTrashed(), 'group' => fn ($q) => $q->withTrashed()]);
        $data = GroupPages::layout('Оплата', $admin);
        $data['links'] += ['group' => route('psychologist.groups.show', $payment->group_id),
            'group-form' => route('psychologist.groups.edit', $payment->group_id),
            'admin-payments' => route('admin.payments.index'), 'admin-group' => route('admin.groups.show', $payment->group_id),
            'admin-user' => route('admin.psychologists.show', $payment->owner_id)];

        return array_merge($data, ['realPayments' => true, 'payment' => self::data($payment),
            'group' => ['title' => $payment->group?->title ?: 'Новая группа'],
            'user' => ['name' => $payment->owner ? PsychologistPages::profile($payment->owner)['name'] : 'Удалённый психолог'],
            'variant' => $payment->product_effect ?? 'normal']);
    }
}
