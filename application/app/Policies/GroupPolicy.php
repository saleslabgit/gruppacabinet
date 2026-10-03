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
        return $this->create($actor) && ! $group->trashed() && ($actor->admin || ($group->owner_id === $actor->id && $group->psychologist_deleted_at === null));
    }

    public function update(User $actor, Group $group): bool
    {
        return $this->view($actor, $group) && ($actor->admin || (! $group->disabled && in_array($group->status, [GroupStatus::Draft, GroupStatus::Revision], true)));
    }

    public function submit(User $actor, Group $group): bool
    {
        return ! $actor->admin && $this->update($actor, $group);
    }

    public function extend(User $actor, Group $group): bool
    {
        return ! $actor->admin && $this->view($actor, $group) && ! $group->disabled
            && in_array($group->status, [GroupStatus::Active, GroupStatus::Expired], true);
    }

    public function moderate(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->status === GroupStatus::Moderation;
    }

    public function syncModx(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group)
            && ($group->status !== GroupStatus::Paused || $group->public_site_resource_id !== null)
            && in_array($group->status, [GroupStatus::Approved, GroupStatus::Active, GroupStatus::Paused, GroupStatus::Expired], true);
    }

    public function activate(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->status === GroupStatus::Approved
            && $group->renewalHistoryId() === null;
    }

    public function withdraw(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->psychologist_deleted_at === null
            && $group->status === GroupStatus::Active && ! $group->disabled && $group->public_site_resource_id > 0;
    }

    public function restorePlacement(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->psychologist_deleted_at === null
            && $group->status === GroupStatus::Active && $group->disabled && $group->public_site_resource_id > 0
            && $group->expires_at !== null && $group->expires_at->isFuture();
    }

    public function retryRenewal(User $actor, Group $group): bool
    {
        return $actor->admin && $this->view($actor, $group) && $group->psychologist_deleted_at === null
            && ! $group->disabled && $group->public_site_resource_id > 0 && $group->renewalHistoryId() !== null;
    }

    public function pause(User $actor, Group $group): bool
    {
        return ! $actor->admin && $this->view($actor, $group) && ! $group->disabled
            && $group->status === GroupStatus::Active && $group->public_site_resource_id !== null
            && $group->expires_at !== null && $group->expires_at->isFuture();
    }

    public function resume(User $actor, Group $group): bool
    {
        return ! $actor->admin && $this->view($actor, $group) && ! $group->disabled
            && $group->status === GroupStatus::Paused && $group->public_site_resource_id !== null
            && $group->paused_at !== null && $group->expires_at !== null && $group->expires_at->isFuture();
    }

    public function delete(User $actor, Group $group): bool
    {
        return $this->view($actor, $group);
    }
}
