<?php

namespace App\Support;

use InvalidArgumentException;

final class BynAmount
{
    public static function parse(string $value): int
    {
        if (! preg_match('/\A([0-9]+)(?:[.,]([0-9]{1,2}))?\z/', $value, $parts)) {
            throw new InvalidArgumentException('Введите неотрицательную сумму с не более чем двумя знаками после запятой.');
        }
        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('Сумма превышает допустимый целочисленный диапазон.');
        }

        return (int) $digits;
    }
}
