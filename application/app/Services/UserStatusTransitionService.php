<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\User;

class UserStatusTransitionService
{
    public function transition(User $user, UserStatus $target): User
    {
        $current = $user->status;

        if (! $current->canTransitionTo($target)) {
            throw InvalidStatusTransition::between($current, $target);
        }

        $user->status = $target;
        $user->save();

        return $user;
    }
}
