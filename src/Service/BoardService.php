<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Ai\BoardOutput;
use Germa\Ai\ClaudeDriver;
use Germa\Ai\Context;
use Germa\Ai\Driver;
use Germa\Ai\DriverException;
use Germa\Ai\RulesDriver;
use Germa\Support\Clock;
use Germa\Support\Config;
use Germa\Support\Database;

/**
 * Owns everything the AI produces.
 *
 * Both the daily brief and the board recommendation are generated once per day
 * and stored a dashboard refresh must not cost an API call. Regeneration is
 * explicit, so Gerald controls when he spends money on tokens.
 *
 * When the Claude driver fails for any reason, the rules driver answers instead
 * and the row records which one did. The dashboard renders that distinction.
 */
final class BoardService
{
    private ?Driver $primary = null;
    private ?RulesDriver $fallback = null;

    /**
     * @param bool $live Allow a model call. False on page render: a dashboard
     *                   must paint immediately, so an un-generated brief falls
     *                   back to rules and is upgraded afterwards by the client.
     *
     * @return array{brief:string, generator:string, model:?string, generated_at:string}
     */
    public function dailyBrief(Context $context, bool $regenerate = false, bool $live = false): array
    {
        $date = $context->date;

        if (!$regenerate) {
            $row = Database::first('SELECT * FROM daily_briefs WHERE for_date = :d', ['d' => $date]);
            if ($row !== null) {
                return [
                    'brief'        => (string) $row['body'],
                    'do_not'       => $row['do_not'] ?? null,
                    'generator'    => (string) $row['generator'],
                    'model'        => $row['model'] ?? null,
                    'generated_at' => (string) $row['generated_at'],
                ];
            }
        }

        [$driver, $result] = $this->attempt(
            static fn (Driver $d): array => $d->dailyBrief($context),
            $ignored,
            $live
        );

        $model = $driver instanceof ClaudeDriver ? (string) Config::get('ai.model') : null;

        Database::run(
            'INSERT INTO daily_briefs (for_date, body, do_not, mode, generator, model, generated_at)
             VALUES (:d, :body, :do_not, :mode, :generator, :model, NOW())
             ON DUPLICATE KEY UPDATE
               body = VALUES(body), do_not = VALUES(do_not), mode = VALUES(mode),
               generator = VALUES(generator), model = VALUES(model),
               generated_at = NOW()',
            [
                'd'         => $date,
                'body'      => $result['brief'],
                'do_not'    => $result['do_not'],
                'mode'      => $context->mode,
                'generator' => $driver->name(),
                'model'     => $model,
            ]
        );

        return [
            'brief'        => $result['brief'],
            'do_not'       => $result['do_not'],
            'generator'    => $driver->name(),
            'model'        => $model,
            'generated_at' => Clock::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{
     *   recommendation:string, rationale:?string, generator:string, model:?string,
     *   generated_at:string, signals:list<array{role:string,signal:string,note:?string,glyph:string,colour:string}>
     * }
     */
    public function boardBrief(Context $context, bool $regenerate = false, bool $live = false): array
    {
        $date = $context->date;

        if (!$regenerate) {
            $stored = $this->storedBoardBrief($date);
            if ($stored !== null) {
                return $stored;
            }
        }

        // BoardOutput already carries its own generator and model.
        [, $output] = $this->attempt(
            static fn (Driver $d): BoardOutput => $d->boardBrief($context),
            $ignored,
            $live
        );

        $this->persistBoardBrief($date, $output);

        return $this->present($output, Clock::now()->format('Y-m-d H:i:s'));
    }

    /**
     * Makes sure today has three ranked priorities.
     *
     * The spec wants these proposed automatically rather than typed in, so an
     * empty day gets filled the first time the dashboard is opened. Anything
     * Gerald has already ranked himself is left alone this only ever fills a
     * blank day, so it can never overwrite his own plan.
     *
     * @return bool Whether it generated anything.
     */
    public function ensurePriorities(Context $context, bool $live = false): bool
    {
        $tasks = new TaskService();

        if ($tasks->topPriorities($context->date) !== []) {
            return false;
        }

        [$driver, $proposed] = $this->attempt(
            static fn(Driver $d): array => $d->dailyPriorities($context),
            $ignored,
            $live
        );

        foreach (array_values($proposed) as $index => $item) {
            $tasks->create([
                'title'             => $item['title'],
                'priority_rank'     => $index + 1,
                'estimated_minutes' => $item['estimated_minutes'],
                'expected_outcome'  => $item['expected_outcome'] === '' ? null : $item['expected_outcome'],
                'for_date'          => $context->date,
                'origin'            => 'ai',
            ]);
        }

        return $driver instanceof ClaudeDriver;
    }

    /** Clears today's AI-proposed priorities so they can be regenerated. */
    public function clearProposedPriorities(string $date): void
    {
        Database::run(
            "DELETE FROM tasks
             WHERE for_date = :d AND origin = 'ai' AND status = 'open' AND priority_rank IS NOT NULL",
            ['d' => $date]
        );
    }

    /**
     * A free-text question from the command dock. Always live the whole point
     * is that Gerald asked it just now and always logged.
     *
     * @return array{answer:string, generator:string, latency_ms:int}
     */
    public function answer(string $question, Context $context): array
    {
        $question = trim($question);

        if ($question === '') {
            throw new \InvalidArgumentException('Ask something first.');
        }

        $started = microtime(true);
        $error   = null;

        try {
            // A typed question is always worth a live call that is the point.
            [$driver, $text] = $this->attempt(
                static fn(Driver $d): string => $d->answer($question, $context),
                $error,
                true
            );
        } catch (\Throwable $e) {
            Database::insert('commands', [
                'prompt'     => $question,
                'response'   => null,
                'mode'       => $context->mode,
                'driver'     => 'rules',
                'error'      => DriverException::readable($e->getMessage()),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        Database::insert('commands', [
            'prompt'     => $question,
            'response'   => $text,
            'mode'       => $context->mode,
            'driver'     => $driver->name(),
            'model'      => $driver instanceof ClaudeDriver ? (string) Config::get('ai.model') : null,
            'latency_ms' => $latency,
            'error'      => $error,
        ]);

        return ['answer' => $text, 'generator' => $driver->name(), 'latency_ms' => $latency];
    }

    /** Whether a live model is actually reachable-in-principle (key + SDK present). */
    public function isLive(): bool
    {
        return $this->driver() instanceof ClaudeDriver;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Runs the callback against the configured driver, falling back to rules on
     * any driver failure. Returns [driver that answered, result].
     *
     * @template T
     * @param callable(Driver):T $call
     * @param bool $live When false, the rules driver answers without any
     *                   network round trip regardless of configuration.
     * @return array{0:Driver,1:T}
     */
    private function attempt(callable $call, ?string &$error = null, bool $live = true): array
    {
        $driver = $live ? $this->driver() : $this->rules();

        try {
            return [$driver, $call($driver)];
        } catch (DriverException $e) {
            if ($driver instanceof RulesDriver) {
                throw $e;
            }
            // Record why the live call failed, then answer anyway.
            $error    = DriverException::readable($e->getMessage());
            $fallback = $this->rules();

            return [$fallback, $call($fallback)];
        }
    }

    private function driver(): Driver
    {
        if ($this->primary instanceof Driver) {
            return $this->primary;
        }

        $configured = (string) Config::get('ai.driver', 'rules');
        $key        = (string) Config::get('ai.api_key', '');

        if ($configured === 'claude' && $key !== '' && class_exists(\Anthropic\Client::class)) {
            return $this->primary = new ClaudeDriver();
        }

        return $this->primary = $this->rules();
    }

    private function rules(): RulesDriver
    {
        return $this->fallback ??= new RulesDriver();
    }

    /** @return array<string,mixed>|null */
    private function storedBoardBrief(string $date): ?array
    {
        $row = Database::first('SELECT * FROM board_briefs WHERE for_date = :d', ['d' => $date]);

        if ($row === null) {
            return null;
        }

        $signals = Database::all(
            'SELECT role, `signal`, note FROM board_signals WHERE board_brief_id = :id ORDER BY sort_order, id',
            ['id' => (int) $row['id']]
        );

        return [
            'recommendation' => (string) $row['recommendation'],
            'rationale'      => $row['rationale'] ?? null,
            'generator'      => (string) $row['generator'],
            'model'          => $row['model'] ?? null,
            'generated_at'   => (string) $row['generated_at'],
            'signals'        => array_map(static fn(array $s): array => [
                'role'   => (string) $s['role'],
                'signal' => (string) $s['signal'],
                'note'   => $s['note'] ?? null,
                'glyph'  => BoardOutput::glyph((string) $s['signal']),
                'colour' => BoardOutput::colour((string) $s['signal']),
            ], $signals),
        ];
    }

    private function persistBoardBrief(string $date, BoardOutput $output): void
    {
        Database::transaction(static function () use ($date, $output): void {
            Database::run(
                'INSERT INTO board_briefs (for_date, recommendation, rationale, generator, model, generated_at)
                 VALUES (:d, :rec, :rat, :gen, :model, NOW())
                 ON DUPLICATE KEY UPDATE
                   recommendation = VALUES(recommendation), rationale = VALUES(rationale),
                   generator = VALUES(generator), model = VALUES(model), generated_at = NOW()',
                [
                    'd'     => $date,
                    'rec'   => $output->recommendation,
                    'rat'   => $output->rationale,
                    'gen'   => $output->generator,
                    'model' => $output->model,
                ]
            );

            $id = (int) Database::value('SELECT id FROM board_briefs WHERE for_date = :d', ['d' => $date], 0);

            Database::run('DELETE FROM board_signals WHERE board_brief_id = :id', ['id' => $id]);

            foreach (array_values($output->signals) as $order => $signal) {
                Database::insert('board_signals', [
                    'board_brief_id' => $id,
                    'role'           => $signal['role'],
                    'signal'         => $signal['signal'],
                    'note'           => $signal['note'],
                    'sort_order'     => $order,
                ]);
            }
        });
    }

    /** @return array<string,mixed> */
    private function present(BoardOutput $output, string $generatedAt): array
    {
        return [
            'recommendation' => $output->recommendation,
            'rationale'      => $output->rationale,
            'generator'      => $output->generator,
            'model'          => $output->model,
            'generated_at'   => $generatedAt,
            'signals'        => array_map(static fn(array $s): array => [
                'role'   => $s['role'],
                'signal' => $s['signal'],
                'note'   => $s['note'] ?? null,
                'glyph'  => BoardOutput::glyph($s['signal']),
                'colour' => BoardOutput::colour($s['signal']),
            ], $output->signals),
        ];
    }
}
