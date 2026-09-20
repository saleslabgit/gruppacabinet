<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Group;
use App\Models\GroupStatusHistory;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class GroupStatusTransitionService
{
    public function transition(
        Group $group,
        GroupStatus $target,
        ?User $actor = null,
        string $actorType = 'system',
        ?string $comment = null,
    ): Group {
        if (! in_array($actorType, ['user', 'system'], true)) {
            throw new DomainException('Group status actor type must be user or system.');
        }

        if ($actorType === 'user' && $actor === null) {
            throw new DomainException('A user actor is required for a user transition.');
        }

        return DB::transaction(function () use ($group, $target, $actor, $actorType, $comment): Group {
            /** @var Group $lockedGroup */
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->getKey());
            $current = $lockedGroup->status;

            if (! $current->canTransitionTo($target)) {
                throw InvalidStatusTransition::between($current, $target);
            }

            $lockedGroup->status = $target;
            $lockedGroup->save();

            GroupStatusHistory::query()->create([
                'group_id' => $lockedGroup->getKey(),
                'from_status' => $current,
                'to_status' => $target,
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actorType,
                'comment' => $comment,
            ]);

            return $lockedGroup;
        });
    }
}
