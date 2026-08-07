<?php

declare(strict_types=1);

namespace Germa\Ai;

/**
 * What the board produced for a given day: one consolidated recommendation and
 * a signal per participating executive. The spec is explicit that Gerald should
 * get one answer, not ten so the per-role detail is reduced to a signal and a
 * one-line note rather than ten separate opinions.
 */
final class BoardOutput
{
    /** @param list<array{role:string,signal:string,note:?string}> $signals */
    public function __construct(
        public readonly string $recommendation,
        public readonly ?string $rationale,
        public readonly array $signals,
        public readonly string $generator,
        public readonly ?string $model = null,
    ) {}

    public const SIGNALS = ['ok', 'warn', 'up', 'down', 'neutral'];

    /** The glyph the dashboard shows under each role. */
    public static function glyph(string $signal): string
    {
        return match ($signal) {
            'ok'   => '✓',
            'warn' => '⚠',
            'up'   => '↑',
            'down' => '↓',
            default => '·',
        };
    }

    /** Colour token for the dark boardroom card. */
    public static function colour(string $signal): string
    {
        return match ($signal) {
            'ok'    => 'var(--signal-good)',
            'warn'  => 'var(--signal-warn)',
            'up'    => 'var(--signal-up)',
            'down'  => 'var(--signal-bad)',
            default => 'var(--ink-faint)',
        };
    }
}
