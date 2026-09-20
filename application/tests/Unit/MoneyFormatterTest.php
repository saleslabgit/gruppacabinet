<?php

namespace Tests\Unit;

use App\Support\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_formats_integer_minor_units(int $minorUnits, string $expected): void
    {
        $this->assertSame($expected, MoneyFormatter::format($minorUnits));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => [0, '0,00 BYN'],
            'non-whole' => [12345, '123,45 BYN'],
            'grouped' => [123456789, '1 234 567,89 BYN'],
            'negative' => [-99, '−0,99 BYN'],
        ];
    }
}
