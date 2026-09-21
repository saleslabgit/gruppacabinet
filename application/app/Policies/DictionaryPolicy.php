<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

class DictionaryPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->admin && ! $actor->disabled && ! $actor->trashed() && $actor->status === UserStatus::Approved;
    }
}
