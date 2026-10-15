<?php

namespace App\ValueObject;

final class Price
{
    public static function toPrice(int $priceSun): string
    {
        return number_format($priceSun / 1_000_000, 6, '.', '');
    }

    public static function toSun(string $price): int
    {
        $cleanPrice = preg_replace('/[^\d.]/', '', $price) ?: 0;

        return round(1_000_000 * (float) $cleanPrice);
    }
}
