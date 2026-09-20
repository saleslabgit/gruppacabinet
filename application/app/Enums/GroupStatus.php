<?php

namespace App\Enums;

enum GroupStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Draft = 'draft';
    case Moderation = 'moderation';
    case Revision = 'revision';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Active = 'active';
    case Expired = 'expired';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::AwaitingPayment => $target === self::Draft,
            self::Draft => $target === self::Moderation,
            self::Moderation => in_array($target, [self::Approved, self::Revision, self::Rejected], true),
            self::Revision => $target === self::Moderation,
            self::Approved => $target === self::Active,
            self::Active => $target === self::Expired,
            self::Expired => $target === self::Approved,
            self::Rejected => false,
        };
    }
}
