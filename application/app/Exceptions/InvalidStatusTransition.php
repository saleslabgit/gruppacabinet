<?php

namespace App\Exceptions;

use BackedEnum;
use DomainException;

class InvalidStatusTransition extends DomainException
{
    public static function between(BackedEnum $from, BackedEnum $to): self
    {
        return new self(sprintf(
            'Transition from %s to %s is not allowed.',
            (string) $from->value,
            (string) $to->value,
        ));
    }
}
