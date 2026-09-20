<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Created => $target === self::Pending,
            self::Pending => in_array($target, [self::Succeeded, self::Failed, self::Cancelled], true),
            self::Succeeded => $target === self::Refunded,
            self::Failed, self::Cancelled, self::Refunded => false,
        };
    }
}
