<?php

declare(strict_types=1);

namespace Germa\Ai;

/**
 * The factual snapshot handed to the AI. Deliberately small and pre-digested:
 * the model reasons about Gerald's position, it does not get handed the
 * database. Everything in here is already true the model's job is judgement,
 * not arithmetic.
 */
final class Context implements \JsonSerializable
{
    /**
     * @param array<string,mixed>            $finance
     * @param array<string,mixed>            $pipeline
     * @param list<array<string,mixed>>      $projects
     * @param list<array<string,mixed>>      $priorities
     * @param list<array<string,mixed>>      $alerts
     * @param list<array<string,mixed>>      $goals
     * @param list<array<string,mixed>>      $opportunities
     * @param array<string,mixed>            $score
     */
    public function __construct(
        public readonly string $date,
        public readonly string $mode,
        public readonly array $finance,
        public readonly array $pipeline,
        public readonly array $projects,
        public readonly array $priorities,
        public readonly array $alerts,
        public readonly array $goals,
        public readonly array $opportunities,
        public readonly array $score,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'date'          => $this->date,
            'mode'          => $this->mode,
            'finance'       => $this->finance,
            'pipeline'      => $this->pipeline,
            'projects'      => $this->projects,
            'priorities'    => $this->priorities,
            'open_alerts'   => $this->alerts,
            'goals'         => $this->goals,
            'opportunities' => $this->opportunities,
            'today_score'   => $this->score,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
