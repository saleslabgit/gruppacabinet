<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GroupLifecycleService
{
    public function __construct(private GroupStatusTransitionService $transitions, private SettingService $settings) {}

    public function expireDueGroups(): int
    {
        $expired = 0;
        Group::query()->where('status', GroupStatus::Active)->where('expires_at', '<=', now()->utc())
            ->select('id')->chunkById(200, function ($groups) use (&$expired): void {
                foreach ($groups as $group) {
                    $expired += (int) $this->expireOne($group->id);
                }
            });

        return $expired;
    }

    public function expireOne(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $group = Group::query()->lockForUpdate()->find($id);
            if (! $group || $group->status !== GroupStatus::Active || ! $group->expires_at || $group->expires_at->gt(now()->utc())) {
                return false;
            }
            $this->transitions->transition($group, GroupStatus::Expired);

            return true;
        });
    }

    public function extend(Group $group, User $actor): Group
    {
        return DB::transaction(function () use ($group, $actor): Group {
            $owner = User::query()->lockForUpdate()->findOrFail($actor->id);
            $locked = Group::query()->where('owner_id', $owner->id)->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($owner)->authorize('extend', $locked);
            if (! $owner->free) {
                throw ValidationException::withMessages(['extension' => 'Платное продление станет доступно после подключения оплаты.']);
            }
            $now = now()->utc();
            if (! $locked->expires_at) {
                throw ValidationException::withMessages(['extension' => 'Дата окончания размещения не задана. Обратитесь к администратору.']);
            }
            if ($locked->status === GroupStatus::Active) {
                if ($locked->expires_at->lte($now)) {
                    throw ValidationException::withMessages(['extension' => 'Срок размещения истёк. Дождитесь обновления статуса и обновите страницу.']);
                }
                if ($locked->placement_days === null || $locked->placement_days < 1) {
                    throw ValidationException::withMessages(['extension' => 'Срок размещения не задан. Обратитесь к администратору.']);
                }
                $expiry = $locked->expires_at->copy()->addDays($locked->placement_days);
                if ($expiry->timestamp > 2147483647) {
                    throw ValidationException::withMessages(['extension' => 'Новая дата окончания превышает допустимый срок. Обратитесь к администратору.']);
                }
                $locked->update(['expires_at' => $expiry, 'expiry_warning_sent_at' => null]);

                return $locked;
            }
            if ($now->gt($locked->expires_at->copy()->addDays($this->settings->expiredExtensionWindowDays()))) {
                throw ValidationException::withMessages(['extension' => 'Срок продления закончился. Создайте новую группу.']);
            }

            return $this->transitions->transition($locked, GroupStatus::Approved);
        });
    }

    public function presentationContext(): array
    {
        return ['now' => now()->utc(), 'warning_days' => $this->settings->expiryWarningDays(),
            'window_days' => $this->settings->expiredExtensionWindowDays()];
    }

    public function presentation(Group $group, ?array $context = null): array
    {
        $data = ['remaining_days' => null, 'warning' => false, 'expiry_due' => false,
            'extension_deadline' => null, 'outside_window' => false, 'can_extend' => false,
            'current_owner_free' => $group->relationLoaded('owner') && $group->owner ? $group->owner->free : false];
        if (! in_array($group->status, [GroupStatus::Active, GroupStatus::Expired], true)) {
            return $data;
        }
        $context ??= $this->presentationContext();
        if ($group->expires_at) {
            if ($group->status === GroupStatus::Active) {
                $seconds = $group->expires_at->timestamp - $context['now']->getTimestamp();
                $data['remaining_days'] = max(0, (int) ceil($seconds / 86400));
                $data['expiry_due'] = $seconds <= 0;
                $data['warning'] = $seconds > 0 && $seconds <= $context['warning_days'] * 86400;
            } else {
                $data['extension_deadline'] = $group->expires_at->copy()->addDays($context['window_days']);
                $data['outside_window'] = $context['now']->gt($data['extension_deadline']);
            }
            $data['can_extend'] = ! $group->disabled && $data['current_owner_free'] && ! $data['outside_window']
                && ! $data['expiry_due'] && ($group->status === GroupStatus::Expired || ($group->placement_days > 0
                    && $group->expires_at->copy()->addDays($group->placement_days)->timestamp <= 2147483647));
        }

        return $data;
    }
}
