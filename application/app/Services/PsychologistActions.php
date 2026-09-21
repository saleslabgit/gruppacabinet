<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PsychologistActions
{
    public function __construct(
        private UserStatusTransitionService $transitions,
        private SessionInvalidator $sessions,
        private AuditService $audit,
    ) {}

    public function run(User $psychologist, User $actor, string $action, ?bool $free = null): void
    {
        DB::transaction(function () use ($psychologist, $actor, $action, $free): void {
            $user = User::query()->lockForUpdate()->findOrFail($psychologist->id);
            Gate::forUser($actor)->authorize('manage', $user);
            $metadata = [];
            if (in_array($action, ['approved', 'rejected'], true)) {
                $target = UserStatus::from($action);
                if (! $user->status->canTransitionTo($target)) {
                    throw ValidationException::withMessages(['action' => 'Этот переход статуса недоступен. Обновите страницу.']);
                }
                $metadata = ['old_status' => $user->status->value, 'new_status' => $target->value];
                $this->transitions->transition($user, $target);
            } elseif (in_array($action, ['enabled', 'disabled'], true)) {
                $disabled = $action === 'disabled';
                if ($user->disabled === $disabled) {
                    throw ValidationException::withMessages(['action' => 'Доступ уже находится в выбранном состоянии.']);
                }
                $metadata = ['old_disabled' => $user->disabled, 'new_disabled' => $disabled];
                $user->update(['disabled' => $disabled]);
            } elseif ($action === 'tariff_changed') {
                if ($free === null || $user->free === $free) {
                    throw ValidationException::withMessages(['action' => 'Тариф уже находится в выбранном состоянии.']);
                }
                $metadata = ['old_free' => $user->free, 'new_free' => $free];
                $user->update(['free' => $free]);
            } elseif ($action !== 'deleted') {
                throw new \InvalidArgumentException('Unknown psychologist action.');
            }
            if (in_array($action, ['disabled', 'rejected', 'deleted'], true)) {
                $this->sessions->invalidate($user);
            }
            if ($action === 'deleted') {
                $user->delete();
            }
            $this->audit->record('user', $user->id, 'user.'.$action, $metadata, $actor);
        });
    }
}
