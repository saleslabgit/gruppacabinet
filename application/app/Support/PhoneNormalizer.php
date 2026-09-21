<?php

namespace App\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    public function normalizeForStorage(string $phone): string
    {
        $compact = preg_replace('/[\s().-]+/u', '', trim($phone));
        if (str_starts_with($compact ?? '', '00')) {
            $compact = '+'.substr($compact, 2);
        }
        if (! preg_match('/^\+[1-9][0-9]{6,14}$/D', $compact ?? '')) {
            throw new InvalidArgumentException('An explicit international phone number (+ or 00 prefix) is required.');
        }

        return $compact;
    }

    public function digitsForSearch(string $phone): string
    {
        if (! preg_match('/^\+?[0-9\s().-]+$/uD', trim($phone))) {
            return '';
        }
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }
}
