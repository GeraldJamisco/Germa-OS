<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Ai\DriverException;
use Germa\Support\Clock;
use Germa\Support\Config;
use Germa\Support\Database;
use Germa\Support\Provenance;

/**
 * Intelligence → AI Boardroom.
 *
 * The dashboard shows the board's one answer, because the spec is explicit that
 * Gerald should get one answer rather than ten. This screen is where that answer
 * becomes accountable: who dissented, what was actually asked, what came back,
 * and which past decisions are now due for review.
 *
 * Two rules shape it. First, no model call happens on the render path, the
 * screen shows what is stored and lets the client upgrade it, exactly as the
 * dashboard does. Second, the screen never pretends a model spoke when the rules
 * engine did: who is talking is itself a provenance question, and the loudest
 * chip on the page answers it.
 */
final class BoardroomService
{
    /**
     * The signals that constitute an objection. The spec gives the Risk Director
     * and the Critic standing permission to say no, so a screen that averages
     * dissent away would defeat the point of having them.
     */
    private const CONCERNS = ['warn', 'down'];

    private readonly ModeService $mode;

    public function __construct(
        private readonly DashboardService $dashboard = new DashboardService(),
        private readonly BoardService $board = new BoardService(),
        ?ModeService $mode = null,
    ) {
        $this->mode = $mode ?? new ModeService(new FinanceService());
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $mode    = $this->mode->current();
        $context = $this->dashboard->context($mode);

        // Stored only. A boardroom that takes 27 seconds to open is a boardroom
        // nobody walks into.
        $brief = $this->board->boardBrief($context);
        $live  = $this->board->isLive();

        $roster = $this->roster($brief['signals']);

        return [
            'speaker'     => $this->speaker($brief, $live),
            'mode'        => $mode,
            'mode_label'  => $this->mode->label($mode),
            'mode_reason' => $this->mode->currentPeriod()['reason'] ?? null,
            'position'    => [
                'recommendation' => $brief['recommendation'],
                'rationale'      => $brief['rationale'],
                'generated_at'   => $brief['generated_at'],
                'model'          => $brief['model'],
            ],
            'roster'      => $roster,
            'seats'       => count($brief['signals']),
            'roster_note' => $this->rosterNote(count($brief['signals']), $brief['generator']),
            'dissent'     => count($roster['concerned']),
            'prompts'     => $this->prompts($mode),
            'minutes'     => $this->minutes(),
            'reviews'     => $this->reviews(),
            'ai_pending'  => $live && $brief['generator'] !== 'claude',
            'suggestions' => [
                'What should I do right now?',
                'Challenge my thinking on this',
                'What am I avoiding?',
                'What is the one thing that matters today?',
                'Call the board',
            ],
        ];
    }

    // ── Who is actually talking ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $brief
     * @return array{label:string,detail:string,provenance:string,live:bool}
     */
    private function speaker(array $brief, bool $live): array
    {
        if ($brief['generator'] === 'claude') {
            return [
                'label'      => 'Board in session',
                'detail'     => sprintf(
                    '%s answered at %s',
                    (string) ($brief['model'] ?? Config::get('ai.model')),
                    $brief['generated_at']
                ),
                'provenance' => Provenance::Ai->value,
                'live'       => true,
            ];
        }

        if ($live) {
            return [
                'label'      => 'Reconvening',
                'detail'     => 'A model is configured but has not answered today yet. This is the rules position until it does',
                'provenance' => Provenance::Calculated->value,
                'live'       => true,
            ];
        }

        return [
            'label'      => 'Rules engine',
            'detail'     => 'No model configured, so the board is arithmetic on your own figures. Nothing here is a model opinion',
            'provenance' => Provenance::Calculated->value,
            'live'       => false,
        ];
    }

    // ── The roster ──────────────────────────────────────────────────────────

    /**
     * Advisors split by whether they are objecting. A flat list of ten roles
     * reads as decoration; two groups read as an argument, which is what a board
     * actually is.
     *
     * @param list<array<string,mixed>> $signals
     * @return array{concerned:list<array<string,mixed>>,clear:list<array<string,mixed>>}
     */
    private function roster(array $signals): array
    {
        $concerned = [];
        $clear     = [];

        foreach ($signals as $signal) {
            $row = [
                'role'   => (string) $signal['role'],
                'signal' => (string) $signal['signal'],
                'note'   => $signal['note'] ?? null,
                'glyph'  => (string) $signal['glyph'],
                'colour' => (string) $signal['colour'],
                // Every advisor is worth asking directly, and a question that
                // names the role gets a sharper answer than one that does not.
                'ask'    => sprintf('%s: what is your position today, and what would change it?', $signal['role']),
            ];

            if (in_array($row['signal'], self::CONCERNS, true)) {
                $concerned[] = $row;
            } else {
                $clear[] = $row;
            }
        }

        return ['concerned' => $concerned, 'clear' => $clear];
    }

    /**
     * How many seats were actually filled, and why that number is what it is.
     * The rules board models five roles; the full ten only convene when a model
     * answers. Printing "10 executives" under five signals would be a lie the
     * page tells about itself.
     */
    private function rosterNote(int $seats, string $generator): string
    {
        if ($generator === 'claude') {
            // The model picks which advisors have something worth saying, so
            // this is not "all ten weighed in" and must not read as though
            // it is.
            return sprintf('%d advisor%s had something to say', $seats, $seats === 1 ? '' : 's');
        }

        return sprintf(
            '%d position%s: the rules board covers the roles that can be derived '
            . 'arithmetically. The remaining advisors need a model to speak.',
            $seats,
            $seats === 1 ? '' : 's'
        );
    }

    /**
     * The standing questions. They follow the spec's decision hierarchy
     * survival first, then obligations, then income, then growth, so the
     * defaults on offer are the questions worth asking in that order.
     *
     * @return list<array{label:string,question:string,note:string}>
     */
    private function prompts(string $mode): array
    {
        $prompts = [
            [
                'label'    => 'Survival',
                'question' => 'Can I survive the next 30 days, and what has to be true for that?',
                'note'     => 'First question in the hierarchy',
            ],
            [
                'label'    => 'Obligation',
                'question' => 'What do I owe, to whom, and by when?',
                'note'     => 'Before anything optional',
            ],
            [
                'label'    => 'Income',
                'question' => 'What is the fastest honest route to money this week?',
                'note'     => 'Speed over size',
            ],
            [
                'label'    => 'Avoidance',
                'question' => 'What am I avoiding, and what is it costing me?',
                'note'     => "The Critic's question",
            ],
        ];

        if ($mode === 'emergency') {
            // In emergency mode the growth question is not just lower priority,
            // it is the wrong question, so it is not offered.
            array_unshift($prompts, [
                'label'    => 'Emergency',
                'question' => 'I am in emergency mode. What is the single action for the next 24 hours?',
                'note'     => 'One action, not a plan',
            ]);

            return $prompts;
        }

        $prompts[] = [
            'label'    => 'Growth',
            'question' => 'What should I build or pursue next, and what should I drop to afford it?',
            'note'     => 'Only once the above are covered',
        ];

        return $prompts;
    }

    // ── Minutes ─────────────────────────────────────────────────────────────

    /**
     * Every question ever put to the OS is already logged. Showing it turns the
     * boardroom into a record rather than a chat window, and the latency and
     * driver columns keep the model honest about how it answered.
     *
     * @return list<array<string,mixed>>
     */
    private function minutes(int $limit = 8): array
    {
        $rows = Database::all(
            'SELECT prompt, response, mode, driver, model, latency_ms, error, created_at
             FROM commands ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
        );

        $minutes = [];

        foreach ($rows as $row) {
            $failed = $row['error'] !== null && $row['error'] !== '';

            $minutes[] = [
                'prompt'   => (string) $row['prompt'],
                'answer'   => $failed ? null : $this->excerpt((string) ($row['response'] ?? '')),
                'error'    => $failed ? DriverException::readable((string) $row['error'], 200) : null,
                'mode'     => (string) $row['mode'],
                'driver'   => (string) $row['driver'],
                'model'    => $row['model'] ?? null,
                // A rules answer is instant and says so; a model answer that
                // took nine seconds should admit that too.
                'timing'   => $row['latency_ms'] === null
                    ? null
                    : $this->timing((int) $row['latency_ms']),
                'when'     => Clock::relative((string) $row['created_at']),
                'provenance' => $failed
                    ? Provenance::Unavailable->value
                    : ($row['driver'] === 'claude' ? Provenance::Ai->value : Provenance::Calculated->value),
            ];
        }

        return $minutes;
    }

    /**
     * Decisions whose review date has arrived. The spec requires every decision
     * to carry one, and the board is the right place to be reminded: a decision
     * never looked at again is indistinguishable from a guess.
     *
     * @return list<array<string,mixed>>
     */
    private function reviews(): array
    {
        $rows = Database::all(
            "SELECT id, title, recommendation, next_action, metric, status, decided_on, review_on
             FROM decisions
             WHERE status IN ('open', 'decided') AND review_on IS NOT NULL AND review_on <= :today
             ORDER BY review_on",
            ['today' => Clock::todayString()]
        );

        $reviews = [];

        foreach ($rows as $row) {
            $overdue = Clock::daysUntil((string) $row['review_on']);

            $reviews[] = [
                'id'      => (int) $row['id'],
                'title'   => (string) $row['title'],
                'metric'  => $row['metric'] ?? null,
                'action'  => $row['next_action'] ?? null,
                'status'  => (string) $row['status'],
                'due'     => $overdue === 0
                    ? 'Due today'
                    : sprintf('%d day%s overdue', abs($overdue), abs($overdue) === 1 ? '' : 's'),
                // Two components read this, and they name their urgency levels
                // differently, so the service says which is which rather than
                // leaving a ternary in the template to guess.
                'row_tone'  => $overdue === 0 ? 'high' : 'critical',
                'pill_tone' => $overdue === 0 ? 'warn' : 'high',
                'ask'     => sprintf('Review the decision "%s". Did it work, and what does the evidence say?', $row['title']),
            ];
        }

        return $reviews;
    }

    // ── Formatting ──────────────────────────────────────────────────────────

    private function excerpt(string $text, int $max = 240): string
    {
        // The model answers in markdown. The minutes are plain text, so the
        // emphasis markers would otherwise show up as literal asterisks.
        $text = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s*/m', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }


    private function timing(int $ms): string
    {
        return $ms < 1000 ? $ms . 'ms' : round($ms / 1000, 1) . 's';
    }
}
