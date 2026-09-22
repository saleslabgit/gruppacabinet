<?php

namespace App\Payments;

use App\Enums\GroupStatus;
use App\Enums\PaymentStatus;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Services\GroupLifecycleService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentAttempts
{
    public function __construct(private SettingService $settings, private Webpay $provider) {}

    public function createPlacement(Group $group): Payment
    {
        return $this->create($group, 'placement');
    }

    private function create(Group $group, string $type): Payment
    {
        $amount = $type === 'placement' ? $this->settings->placementPriceMinorUnits() : $this->settings->extensionPriceMinorUnits();
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['payment' => 'Стоимость оплаты не настроена. Обратитесь к администратору.']);
        }
        $this->configured();

        return Payment::create(['owner_id' => $group->owner_id, 'group_id' => $group->id, 'type' => $type,
            'order_number' => 'GP-'.bin2hex(random_bytes(16)), 'amount' => $amount, 'currency' => 'BYN', 'status' => PaymentStatus::Created,
            'extension_days' => $type === 'extension' ? $group->placement_days : null,
            'extension_expires_at' => $type === 'extension' ? $group->expires_at : null,
            'extension_status' => $type === 'extension' ? $group->status->value : null]);
    }

    private function configured(): void
    {
        try {
            $this->provider->configuration();
        } catch (ProviderException) {
            throw ValidationException::withMessages(['payment' => 'Оплата WEBPAY не настроена. Обратитесь к администратору.']);
        }
    }

    public function extend(Group $group, User $actor): Group|Payment
    {
        return DB::transaction(function () use ($group, $actor): Group|Payment {
            $owner = User::query()->lockForUpdate()->findOrFail($actor->id);
            $locked = Group::query()->where('owner_id', $owner->id)->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($owner)->authorize('extend', $locked);
            // Continue the existing attempt even if the tariff changed after its creation.
            $existing = $locked->payments()->where('type', 'extension')->whereIn('status', ['created', 'pending'])->first();
            if ($existing) {
                return $existing;
            }
            if ($owner->free) {
                return app(GroupLifecycleService::class)->extend($locked, $owner);
            }
            $this->eligibleExtension($locked);

            return $this->create($locked, 'extension');
        }, 3);
    }

    public function eligibleExtension(Group $group): void
    {
        $now = now()->utc();
        $valid = $group->expires_at && ! $group->disabled && in_array($group->status, [GroupStatus::Active, GroupStatus::Expired], true);
        if ($valid && $group->status === GroupStatus::Active) {
            $valid = $group->expires_at->gt($now) && $group->placement_days > 0
                && $group->expires_at->copy()->addDays($group->placement_days)->timestamp <= 2147483647;
        } elseif ($valid) {
            $valid = $now->lte($group->expires_at->copy()->addDays($this->settings->expiredExtensionWindowDays()));
        }
        if (! $valid) {
            throw ValidationException::withMessages(['extension' => 'Продление недоступно: проверьте статус и срок размещения.']);
        }
    }

    public function retry(Payment $payment, User $actor): Group|Payment
    {
        if ($payment->type === 'extension') {
            return DB::transaction(function () use ($payment, $actor): Group|Payment {
                $owner = User::query()->lockForUpdate()->findOrFail($actor->id);
                $group = Group::query()->where('owner_id', $owner->id)->lockForUpdate()->findOrFail($payment->group_id);
                abort_unless(in_array($payment->fresh()->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true), 409);
                $latest = $group->payments()->where('type', 'extension')->latest('id')->firstOrFail();
                if ($latest->id !== $payment->id) {
                    return $latest;
                }

                return $this->extend($group, $owner);
            }, 3);
        }

        return DB::transaction(function () use ($payment, $actor): Payment {
            $locked = Group::query()->where('owner_id', $actor->id)->lockForUpdate()->findOrFail($payment->group_id);
            abort_unless(! $locked->disabled && $locked->status === GroupStatus::AwaitingPayment && ! $locked->free, 409);
            abort_unless(in_array($payment->fresh()->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true), 409);
            $latest = $locked->payments()->where('type', 'placement')->latest('id')->firstOrFail();
            if ($latest->id !== $payment->id) {
                return $latest;
            }

            return $this->create($locked, 'placement');
        }, 3);
    }

    public function start(Payment $payment): array
    {
        $this->configured();

        return DB::transaction(function () use ($payment): array {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $group = Group::query()->lockForUpdate()->findOrFail($locked->group_id);
            abort_unless(! $group->disabled && in_array($locked->status, [PaymentStatus::Created, PaymentStatus::Pending], true), 409);
            if ($locked->status === PaymentStatus::Created) {
                if ($locked->type === 'extension') {
                    $this->eligibleExtension($group);
                }
                $locked->update(['status' => PaymentStatus::Pending, 'started_at' => now()->utc()]);
            }

            return $this->provider->form($locked);
        }, 3);
    }
}
