<?php

namespace App\Support;

final class MoneyFormatter
{
    public static function format(int $minorUnits, string $currency = 'BYN'): string
    {
        $negative = $minorUnits < 0;
        $digits = ltrim((string) $minorUnits, '-');
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -2);
        $fraction = substr($digits, -2);
        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);

        return sprintf(
            '%s%s,%s %s',
            $negative ? '−' : '',
            $groupedWhole,
            $fraction,
            $currency,
        );
    }
}
