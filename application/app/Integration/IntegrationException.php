<?php

namespace App\Integration;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($errorCode);
    }
}
