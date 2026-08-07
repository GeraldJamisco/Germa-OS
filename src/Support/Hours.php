<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * Hours are the second currency in this system: projects consume them whether
 * or not they return money, and the Critic's standing objection is measured in
 * them. They get the same treatment as Money, one formatter, so "12h" never
 * appears as "12.0h" on one screen and "12h" on another.
 */
final class Hours
{
    /** Whole hours read as whole hours; halves keep their decimal. */
    public static function format(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1), '0'), '.') . 'h';
    }

    /** For places where zero should say so rather than showing "0h". */
    public static function formatOrNone(float $hours): string
    {
        return $hours === 0.0 ? 'None' : self::format($hours);
    }
}
