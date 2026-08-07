<?php

declare(strict_types=1);

namespace Germa\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of "now". Everything date-dependent goes through here so the
 * daily/weekly/monthly cycles can be tested against a fixed date.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function freeze(DateTimeImmutable|string $at): void
    {
        self::$frozen = $at instanceof DateTimeImmutable
            ? $at
            : new DateTimeImmutable($at, self::zone());
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone((string) Config::get('app.timezone', 'Africa/Kampala'));
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::zone());
    }

    public static function today(): DateTimeImmutable
    {
        return self::now()->setTime(0, 0);
    }

    public static function todayString(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function daysUntil(string $date): int
    {
        $target = new DateTimeImmutable($date, self::zone());
        return (int) self::today()->diff($target->setTime(0, 0))->format('%r%a');
    }

    /** Working days (Mon–Sat Gerald works Saturdays) left in the current month, today included. */
    public static function workingDaysLeftInMonth(): int
    {
        $cursor = self::today();
        $end    = $cursor->modify('last day of this month');
        $count  = 0;

        while ($cursor <= $end) {
            if ((int) $cursor->format('N') !== 7) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return max($count, 1);
    }

    public static function workingDaysInMonth(): int
    {
        $cursor = self::today()->modify('first day of this month');
        $end    = $cursor->modify('last day of this month');
        $count  = 0;

        while ($cursor <= $end) {
            if ((int) $cursor->format('N') !== 7) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return max($count, 1);
    }

    public static function monthStart(): string
    {
        return self::today()->modify('first day of this month')->format('Y-m-d');
    }

    public static function monthEnd(): string
    {
        return self::today()->modify('last day of this month')->format('Y-m-d');
    }

    public static function daysAgo(int $days): string
    {
        return self::today()->modify("-$days days")->format('Y-m-d');
    }

    /** "Wednesday, 5 August 2026 · 07:12 EAT" */
    /**
     * A timestamp phrased against now, with sub-day granularity: "4 min ago",
     * "2 hours ago", "Yesterday 14:20". The boardroom minutes need to
     * distinguish a question asked this morning from one asked last week.
     *
     * Deliberately not shared with the day-level phrasings in ClientService,
     * ExpenseService and FinanceService: those differ in casing and in whether
     * they handle future dates, and collapsing them would force a wrong choice
     * in at least one place.
     */
    public static function relative(string $datetime): string
    {
        $stamp   = new DateTimeImmutable($datetime, self::zone());
        $seconds = self::now()->getTimestamp() - $stamp->getTimestamp();

        if ($seconds < 0) {
            return $stamp->format('j M H:i');
        }
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);

            return $minutes . ' min ago';
        }
        if ($seconds < 86400) {
            $hours = (int) floor($seconds / 3600);

            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }

        $days = (int) self::today()->diff($stamp->setTime(0, 0))->format('%a');

        if ($days === 1) {
            return 'Yesterday ' . $stamp->format('H:i');
        }
        if ($days < 7) {
            return $days . ' days ago';
        }

        return $stamp->format('j M');
    }

    public static function headerStamp(): string
    {
        $now = self::now();
        return $now->format('l, j F Y') . ' · ' . $now->format('H:i') . ' ' . $now->format('T');
    }

    public static function greeting(): string
    {
        $hour = (int) self::now()->format('G');
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };
    }
}
