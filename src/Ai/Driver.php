<?php

declare(strict_types=1);

namespace Germa\Ai;

/**
 * Two implementations: RulesDriver (deterministic, offline) and ClaudeDriver
 * (Anthropic Messages API). Everything downstream records which one produced a
 * given piece of text, because the UI must never present a rule-derived
 * sentence and a model-generated one as the same kind of thing.
 */
interface Driver
{
    public function name(): string;

    /**
     * The day's standing brief: the status paragraph, plus the one thing the
     * spec insists every Daily Command Brief ends with: what NOT to do.
     *
     * @return array{brief:string,do_not:?string}
     */
    public function dailyBrief(Context $context): array;

    /** The boardroom recommendation plus per-executive signals. */
    public function boardBrief(Context $context): BoardOutput;

    /**
     * Exactly three priorities for the day, highest-value first.
     *
     * @return list<array{title:string,estimated_minutes:int,expected_outcome:string}>
     */
    public function dailyPriorities(Context $context): array;

    /** A free-text question from the command dock. */
    public function answer(string $question, Context $context): string;
}
