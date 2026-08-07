<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * The UI brief is explicit: the dashboard must never blur the line between a
 * fact Gerald entered, a number the system derived, and something the AI made
 * up. Every value that reaches the view carries one of these, and the view
 * renders a differently-styled chip per case.
 */
enum Provenance: string
{
    /** A fact Gerald typed in. Trustworthy as of its recorded date. */
    case Entered = 'entered';

    /** Derived deterministically from entered data. Reproducible. */
    case Calculated = 'calculated';

    /** Produced by a language model. Advisory, never authoritative. */
    case Ai = 'ai';

    /** Derived using an assumption (a rate, a probability, a forecast). */
    case Estimated = 'estimated';

    /** No data. Shown as an empty state, never as a zero. */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Entered     => 'Entered',
            self::Calculated  => 'Calculated',
            self::Ai          => 'AI',
            self::Estimated   => 'Estimated',
            self::Unavailable => 'No data',
        };
    }

    /** CSS modifier used by .chip in germa.css */
    public function modifier(): string
    {
        return 'chip--' . $this->value;
    }
}
