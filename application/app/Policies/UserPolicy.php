<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->admin && ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved;
    }

    public function manage(User $actor, User $psychologist): bool
    {
        return $this->viewAny($actor) && ! $psychologist->admin && ! $psychologist->trashed();
    }
}
