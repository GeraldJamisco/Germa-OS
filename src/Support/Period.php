<?php

declare(strict_types=1);

namespace Germa\Support;

use DateTimeImmutable;

/**
 * The week / month / year selector that Revenue, Active Projects and Project
 * Portfolio all carry in their page head.
 *
 * Three screens had begun to grow their own copy of this arithmetic. A window
 * that disagrees between screens is the same class of bug as a figure that
 * disagrees between screens, so it lives in one place.
 */
final class Period
{
    public const OPTIONS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    /** Anything unrecognised, including a hand-edited query string, is a month. */
    public static function normalise(mixed $period): string
    {
        $period = is_string($period) ? $period : '';

        return array_key_exists($period, self::OPTIONS) ? $period : 'month';
    }

    /**
     * The window runs to today rather than to the end of the period: a figure
     * for a week that has not happened yet would read as a shortfall.
     *
     * @return array{0:string,1:string}
     */
    public static function window(string $period): array
    {
        $today = Clock::today();

        return match (self::normalise($period)) {
            'week'  => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'year'  => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            default => [Clock::monthStart(), $today->format('Y-m-d')],
        };
    }

    /** Days in the window so far, for turning a total into a rate. */
    public static function daysElapsed(string $period): int
    {
        [$from, $to] = self::window($period);
        $start = new DateTimeImmutable($from, Clock::zone());
        $end   = new DateTimeImmutable($to, Clock::zone());

        return max(1, (int) $start->diff($end)->days + 1);
    }

    public static function rangeLabel(string $period): string
    {
        [$from, $to] = self::window($period);
        $start = new DateTimeImmutable($from, Clock::zone());
        $end   = new DateTimeImmutable($to, Clock::zone());

        return match (self::normalise($period)) {
            'week'  => $start->format('j M') . ' – ' . $end->format('j M'),
            'year'  => $start->format('Y'),
            default => $start->format('F Y'),
        };
    }

    /** "this week" / "this month" / "this year", for use inside a sentence. */
    public static function phrase(string $period): string
    {
        return 'this ' . strtolower(self::OPTIONS[self::normalise($period)]);
    }

    /** @return list<array{key:string,label:string,active:bool}> */
    public static function options(string $active): array
    {
        $active  = self::normalise($active);
        $options = [];

        foreach (self::OPTIONS as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label, 'active' => $key === $active];
        }

        return $options;
    }
}
