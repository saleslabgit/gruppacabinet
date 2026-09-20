<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class DateTimeFormatter
{
    public const DISPLAY_TIMEZONE = 'Europe/Minsk';

    public static function format(DateTimeInterface $value, string $format = 'd.m.Y H:i'): string
    {
        return CarbonImmutable::instance($value)
            ->setTimezone(self::DISPLAY_TIMEZONE)
            ->format($format);
    }
}
