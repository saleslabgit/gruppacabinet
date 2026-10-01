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
    public const FIELDS = ['title', 'description', 'full_description_html', 'meeting_days', 'start_time', 'frequency', 'city', 'group_type_id', 'format_id', 'meeting_duration_minutes', 'participant_capacity', 'gender_id', 'meeting_price'];

    public function __construct(private GroupStatusTransitionService $transitions, private SettingService $settings) {}

    public function create(User $owner, User $actor, array $data = []): Group
    {
        return app(GroupCovers::class)->persist($data['cover'] ?? null, function (?array $cover) use ($owner, $actor, $data): array {
            Gate::forUser($actor)->authorize('create', Group::class);
            $owner = User::query()->lockForUpdate()->findOrFail($owner->id);
            abort_unless(! $owner->admin && ! $owner->disabled && $owner->status->value === 'approved', 403);
            abort_unless($actor->admin || $actor->id === $owner->id, 403);
            $data = app(GroupContent::class)->prepare($data, null, (bool) $actor->admin);
            $group = Group::query()->create(Arr::only($data, self::FIELDS) + ($cover ?? []) + ['owner_id' => $owner->id]);
            $this->syncRelations($group, $data);
            if ($actor->admin) {
                app(GroupModxReadiness::class)->validate($group);
            }
            if (! $owner->free) {
                $group->update(['status' => GroupStatus::AwaitingPayment]);
                app(PaymentAttempts::class)->createPlacement($group);
            }
            $group->statusHistory()->create(['from_status' => null, 'to_status' => $group->status, 'actor_id' => $actor->id, 'actor_type' => 'user']);

            return [$group, null];
        });
    }

    public function save(Group $group, User $actor, array $data, bool $submit = false): Group
    {
        return app(GroupCovers::class)->persist($data['cover'] ?? null, function (?array $cover) use ($group, $actor, $data, $submit): array {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize($submit ? 'submit' : 'update', $locked);
            $data = app(GroupContent::class)->prepare($data, $locked, $submit);
            $oldCover = $locked->cover_path;
            $locked->fill(Arr::only($data, self::FIELDS) + ($cover ?? []));
            if ($actor->admin) {
                $before = [];
                foreach (['published_at', 'expires_at'] as $field) {
                    $before[$field] = $locked->$field?->copy()->utc()->toIso8601String();
                }
                $locked->fill(Arr::only($data, ['published_at', 'expires_at']));
                if ($locked->published_at !== null && $locked->expires_at !== null && $locked->expires_at->lte($locked->published_at)) {
                    throw ValidationException::withMessages(['expires_at' => 'Дата окончания должна быть позже даты публикации.']);
                }
                if ($locked->isDirty('expires_at')) {
                    $locked->expiry_warning_sent_at = null;
                }
                if ($locked->isDirty(['published_at', 'expires_at'])) {
                    app(AuditService::class)->record('group', $locked->id, 'group_placement_dates_changed', [
                        'old_published_at' => $before['published_at'],
                        'new_published_at' => $locked->published_at?->copy()->utc()->toIso8601String(),
                        'old_expires_at' => $before['expires_at'],
                        'new_expires_at' => $locked->expires_at?->copy()->utc()->toIso8601String(),
                    ], $actor);
                }
            }
            $contentChanged = $locked->isDirty([...self::FIELDS, 'cover_path']);
            $locked->save();

            $contentChanged = $this->syncRelations($locked, $data) || $contentChanged;
            if ($submit) {
                app(GroupModxReadiness::class)->validate($locked);
            }
            if ($actor->admin && $contentChanged && ($locked->public_site_resource_id !== null || $locked->status === GroupStatus::Approved)) {
                app(GroupModxSyncScheduler::class)->schedule($locked);
            }
            $saved = $submit ? $this->transitions->transition($locked, GroupStatus::Moderation, $actor, 'user') : $locked;

            return [$saved, $oldCover];
        });
    }

    private function syncRelations(Group $group, array $data): bool
    {
        $changed = false;
        foreach (['approach_ids' => 'approaches', 'tag_ids' => 'tags'] as $field => $relation) {
            if (array_key_exists($field, $data)) {
                $changes = $group->$relation()->sync($data[$field]);
                $changed = $changed || $changes['attached'] !== [] || $changes['detached'] !== [];
                $group->unsetRelation($relation);
            }
        }

        return $changed;
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

            if ($target === GroupStatus::Approved) {
                app(GroupModxReadiness::class)->validate($locked);
            }
            $saved = $this->transitions->transition($locked, $target, $actor, 'user', $comment);
            if ($target === GroupStatus::Approved) {
                app(GroupModxSyncScheduler::class)->schedule($saved);
            }

            return $saved;
        });
    }

    public function syncModx(Group $group, User $actor): void
    {
        DB::transaction(function () use ($group, $actor): void {
            $locked = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('syncModx', $locked);
            app(GroupModxReadiness::class)->validate($locked);
            app(GroupModxSyncScheduler::class)->schedule($locked);
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
            if (! $actor->admin && $locked->status === GroupStatus::Rejected) {
                $locked->update(['psychologist_deleted_at' => now()->utc()]);

                return;
            }
            $locked->delete();
            if ($actor->admin) {
                app(AuditService::class)->record('group', $locked->id, 'group_deleted', ['status' => $locked->status->value], $actor);
            }
        });
    }
}
