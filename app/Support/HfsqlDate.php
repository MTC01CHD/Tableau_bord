<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Convertit le format de date HFSQL `/Date(1727784798000)/` en Carbon ou string.
 */
class HfsqlDate
{
    public static function parse(?string $hfsqlDate): ?CarbonImmutable
    {
        if (!$hfsqlDate) return null;
        if (!preg_match('#/Date\((-?\d+)\)/#', $hfsqlDate, $m)) return null;
        $ms = (int) $m[1];
        if ($ms <= 0) return null;
        return CarbonImmutable::createFromTimestampMs($ms);
    }

    public static function format(?string $hfsqlDate, string $fmt = 'd/m/Y'): string
    {
        $c = self::parse($hfsqlDate);
        return $c ? $c->format($fmt) : '—';
    }
}
