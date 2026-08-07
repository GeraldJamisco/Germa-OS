<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Settings;

/**
 * NORMAL / EMERGENCY / OPPORTUNITY. The spec says emergency mode engages when
 * cash or household survival becomes critical, so the OS decides this itself
 * from the runway rather than waiting to be told.
 */
final class ModeService
{
    public const NORMAL      = 'normal';
    public const EMERGENCY   = 'emergency';
    public const OPPORTUNITY = 'opportunity';

    public function __construct(private readonly FinanceService $finance) {}

    public function current(): string
    {
        $row = Database::first('SELECT mode FROM mode_periods WHERE exited_at IS NULL ORDER BY id DESC LIMIT 1');
        return $row === null ? self::NORMAL : (string) $row['mode'];
    }

    /** @return array{mode:string,reason:?string,since:?string} */
    public function currentPeriod(): array
    {
        $row = Database::first('SELECT mode, reason, entered_at FROM mode_periods WHERE exited_at IS NULL ORDER BY id DESC LIMIT 1');

        return [
            'mode'   => $row === null ? self::NORMAL : (string) $row['mode'],
            'reason' => $row['reason'] ?? null,
            'since'  => $row['entered_at'] ?? null,
        ];
    }

    /**
     * Re-evaluates the mode against current cash. Returns the mode in force
     * afterwards. Only moves in or out of emergency automatically opportunity
     * mode is always a deliberate choice.
     */
    public function evaluate(): string
    {
        $current = $this->current();

        if (!Settings::bool('auto_emergency_mode', true) || $current === self::OPPORTUNITY) {
            return $current;
        }

        $criticalDays = Settings::int('runway_critical_days', 7);
        $runway       = $this->finance->runwayDays();

        // Unknown runway is not evidence of an emergency.
        if ($runway === null) {
            return $current;
        }

        if ($runway < $criticalDays && $current !== self::EMERGENCY) {
            $this->switchTo(self::EMERGENCY, sprintf('Runway %d days, below the %d-day threshold', $runway, $criticalDays));
            return self::EMERGENCY;
        }

        // Leave emergency only with clear daylight (1.5x the threshold), so the
        // OS does not flap in and out on a single payment.
        if ($current === self::EMERGENCY && $runway >= (int) ceil($criticalDays * 1.5)) {
            $this->switchTo(self::NORMAL, sprintf('Runway recovered to %d days', $runway));
            return self::NORMAL;
        }

        return $current;
    }

    public function switchTo(string $mode, ?string $reason = null): void
    {
        if (!in_array($mode, [self::NORMAL, self::EMERGENCY, self::OPPORTUNITY], true)) {
            throw new \InvalidArgumentException("Unknown mode: $mode");
        }

        Database::transaction(static function () use ($mode, $reason): void {
            Database::run('UPDATE mode_periods SET exited_at = NOW() WHERE exited_at IS NULL');
            Database::insert('mode_periods', [
                'mode'       => $mode,
                'reason'     => $reason,
                'entered_at' => Clock::now()->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function isEmergency(): bool
    {
        return $this->current() === self::EMERGENCY;
    }

    public function label(?string $mode = null): string
    {
        return match ($mode ?? $this->current()) {
            self::EMERGENCY   => 'Emergency mode',
            self::OPPORTUNITY => 'Opportunity mode',
            default           => 'Normal mode',
        };
    }
}
