<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\User;
use App\Payments\PaymentAttempts;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GroupWorkflow
{
    public const FIELDS = ['title', 'description', 'schedule', 'format_id', 'meeting_duration_minutes', 'participant_capacity', 'gender_id', 'meeting_price'];

    public function __construct(private GroupStatusTransitionService $transitions, private SettingService $settings) {}

    public function create(User $owner, User $actor, array $data = []): Group
    {
        return DB::transaction(function () use ($owner, $actor, $data): Group {
            Gate::forUser($actor)->authorize('create', Group::class);
            $owner = User::query()->lockForUpdate()->findOrFail($owner->id);
            abort_unless(! $owner->admin && ! $owner->disabled && $owner->status->value === 'approved', 403);
            abort_unless($actor->admin || $actor->id === $owner->id, 403);
            $group = Group::query()->create(Arr::only($data, self::FIELDS) + ['owner_id' => $owner->id]);
            if (! $owner->free) {
                $group->update(['status' => GroupStatus::AwaitingPayment]);
                app(PaymentAttempts::class)->createPlacement($group);
            }
            $group->statusHistory()->create(['from_status' => null, 'to_status' => $group->status, 'actor_id' => $actor->id, 'actor_type' => 'user']);

            return $group;
        });
    }

    public function save(Group $group, User $actor, array $data, bool $submit = false): Group
    {
        return DB::transaction(function () use ($group, $actor, $data, $submit): Group {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize($submit ? 'submit' : 'update', $locked);
            $locked->update(Arr::only($data, self::FIELDS));

            return $submit ? $this->transitions->transition($locked, GroupStatus::Moderation, $actor, 'user') : $locked;
        });
    }

    public function moderate(Group $group, User $actor, GroupStatus $target, ?string $comment = null): Group
    {
        return DB::transaction(function () use ($group, $actor, $target, $comment): Group {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('moderate', $locked);
            abort_unless(in_array($target, [GroupStatus::Approved, GroupStatus::Revision, GroupStatus::Rejected], true), 422);
            if ($target !== GroupStatus::Approved) {
                $field = $target === GroupStatus::Revision ? 'moderator_comment' : 'rejection_reason';
                $comment = trim($comment ?? '');
                if (mb_strlen($comment) < 10 || mb_strlen($comment) > 16000) {
                    throw ValidationException::withMessages([$field => 'Введите от 10 до 16000 символов.']);
                }
                $locked->update([$field => $comment]);
            }

            return $this->transitions->transition($locked, $target, $actor, 'user', $comment);
        });
    }

    public function activate(Group $group, User $actor): Group
    {
        return DB::transaction(function () use ($group, $actor): Group {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('activate', $locked);
            $published = now()->utc();
            $days = $this->settings->placementDurationDays();
            $locked->update(['published_at' => $published, 'placement_days' => $days,
                'expires_at' => $published->copy()->addDays($days), 'expiry_warning_sent_at' => null]);

            return $this->transitions->transition($locked, GroupStatus::Active, $actor, 'user');
        });
    }

    public function delete(Group $group, User $actor): void
    {
        DB::transaction(function () use ($group, $actor): void {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('delete', $locked);
            $locked->delete();
        });
    }
}
