<?php

namespace App\Policies;

use App\Enums\GroupStatus;
use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function create(User $actor): bool
    {
        return ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved;
    }

    public function viewAny(User $actor): bool
    {
        return $this->create($actor) && $actor->admin;
    }

    public function view(User $actor, Group $group): bool
    {
        return $this->create($actor) && ! $group->trashed() && ($actor->admin || $group->owner_id === $actor->id);
    }

    public function update(User $actor, Group $group): bool
    {
        return $this->view($actor, $group) && ($actor->admin || (! $group->disabled && in_array($group->status, [GroupStatus::Draft, GroupStatus::Revision], true)));
    }

    public function submit(User $actor, Group $group): bool
    {
        return ! $actor->admin && $this->update($actor, $group);
    }

    public function moderate(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->status === GroupStatus::Moderation;
    }

    public function activate(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->status === GroupStatus::Approved;
    }

    public function delete(User $actor, Group $group): bool
    {
        if (! $this->view($actor, $group)) {
            return false;
        }
        $eligible = $actor->admin
            ? $group->status === GroupStatus::Draft && $group->created_at->lte(now()->subDays(config('groups.abandoned_draft_days')))
            : ! $group->disabled && in_array($group->status, [GroupStatus::Draft, GroupStatus::Rejected], true);

        return $eligible && ! $group->payments()->withTrashed()->where('status', 'succeeded')->whereNull('refunded_at')->exists();
    }
}
