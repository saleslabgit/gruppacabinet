<?php

namespace App\Support;

class PhoneNormalizer
{
    public function digitsForSearch(string $phone): string
    {
        if (! preg_match('/^\+?[0-9\s().-]+$/uD', trim($phone))) {
            return '';
        }
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }
}
