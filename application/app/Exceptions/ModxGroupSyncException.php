<?php

namespace App\Exceptions;

use RuntimeException;

class ModxGroupSyncException extends RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly bool $retryable = false)
    {
        parent::__construct('MODX group synchronization: '.$safeCode);
    }
}
