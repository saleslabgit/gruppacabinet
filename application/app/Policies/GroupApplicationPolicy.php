<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\GroupApplication;
use App\Models\User;

class GroupApplicationPolicy
{
    public function viewAny(User $actor): bool
    {
        return ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved;
    }

    public function view(User $actor, GroupApplication $application): bool
    {
        return $this->viewAny($actor) && ($actor->admin || $application->group?->owner_id === $actor->id);
    }

    public function process(User $actor, GroupApplication $application): bool
    {
        return ! $actor->admin && $this->view($actor, $application) && ! $application->group->trashed();
    }
}
