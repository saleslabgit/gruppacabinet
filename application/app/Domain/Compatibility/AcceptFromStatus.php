<?php

namespace App\Domain\Compatibility;

use App\Enums\GroupStatus;
use App\Enums\UserStatus;

final class AcceptFromStatus
{
    public static function forUser(UserStatus $status): bool
    {
        return $status === UserStatus::Approved;
    }

    public static function forGroup(GroupStatus $status): bool
    {
        return in_array($status, [GroupStatus::Approved, GroupStatus::Active, GroupStatus::Expired], true);
    }
}
