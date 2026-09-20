<?php

namespace Tests\Unit;

use App\Support\DateTimeFormatter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DateTimeFormatterTest extends TestCase
{
    public function test_it_converts_utc_time_to_minsk_for_display(): void
    {
        $utcTime = new DateTimeImmutable('2026-01-15 10:30:00 UTC');

        $this->assertSame('15.01.2026 13:30', DateTimeFormatter::format($utcTime));
    }
}
