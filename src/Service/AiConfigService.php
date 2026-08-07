<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Ai\ClaudeDriver;
use Germa\Ai\DriverException;
use Germa\Ai\SystemPrompt;
use Germa\Support\Clock;
use Germa\Support\Config;
use Germa\Support\Database;
use Germa\Support\Provenance;

/**
 * System → AI Configuration.
 *
 * This screen governs the one part of the OS that speaks with authority, so it is
 * built around a distinction the rest of the app learned the hard way: being
 * configured is not the same as working.
 *
 * For most of this project's life the AI layer looked correctly configured. A key
 * was set, the driver said claude, and every screen carried a chip claiming its
 * text was reasoned. It had never once succeeded. Every request was rejected
 * because the configured model did not accept the `fallbacks` parameter, the
 * rejection was caught, and the rules engine answered instead. Nothing said so.
 *
 * So configuration is reported here as three separate facts (a driver chosen, a
 * key present, an SDK installed) and then a fourth that outranks all of them:
 * whether a live call has actually ever come back. The first three are intentions.
 * Only the fourth is evidence.
 *
 * Two deliberate refusals. The API key is never rendered, not even partially,
 * because a page that displays a secret is a page that leaks one. And nothing on
 * this screen writes configuration: `ai.*` lives in PHP files rather than the
 * settings table, and a web form that rewrote them could quietly strip the
 * accountability rules out of the system prompt.
 */
final class AiConfigService
{
    public function __construct(
        private readonly BoardService $board = new BoardService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $reach = $this->reachability();

        return [
            'reach'        => $reach,
            'insight'      => $this->insight($reach),
            'key'          => $this->keyHygiene(),
            'capabilities' => $this->capabilities(),
            'request'      => $this->requestShape(),
            'evidence'     => $this->evidence(),
            'prompt'       => $this->prompt(),
            'guarantees'   => $this->guarantees(),
            'suggestions'  => [
                'Are you actually reasoning or just doing arithmetic?',
                'What would you tell me that the rules engine cannot?',
                'Explain your own limitations to me',
                'What do you not know about my situation?',
                'Call the board',
            ],
        ];
    }

    // ── Configured, versus working ──────────────────────────────────────────

    /**
     * The four facts, in ascending order of how much they prove.
     *
     * @return array<string,mixed>
     */
    private function reachability(): array
    {
        $driver = (string) Config::get('ai.driver', 'rules');
        $hasKey = trim((string) Config::get('ai.api_key', '')) !== '';
        $hasSdk = class_exists(\Anthropic\Client::class);

        // The only fact that is evidence rather than intention.
        $lastGood = Database::first(
            "SELECT created_at, model, latency_ms FROM commands
             WHERE driver = 'claude' AND (error IS NULL OR error = '')
             ORDER BY created_at DESC LIMIT 1"
        );

        $lastFail = Database::first(
            "SELECT created_at, error FROM commands
             WHERE error IS NOT NULL AND error != '' ORDER BY created_at DESC LIMIT 1"
        );

        $steps = [
            [
                'label'  => 'A driver is chosen',
                'value'  => $driver === 'claude' ? 'claude' : 'rules',
                'ok'     => $driver === 'claude',
                'note'   => $driver === 'claude'
                    ? 'The OS will attempt a model call where one is useful.'
                    : 'The OS runs on arithmetic only. Nothing will be labelled as reasoned, because nothing will be.',
            ],
            [
                'label'  => 'An API key is present',
                'value'  => $hasKey ? 'Set' : 'Not set',
                'ok'     => $hasKey,
                'note'   => $hasKey
                    ? 'Read from config, never displayed here.'
                    : 'Without one the driver falls back to rules on every call.',
            ],
            [
                'label'  => 'The SDK is installed',
                'value'  => $hasSdk ? 'Installed' : 'Missing',
                'ok'     => $hasSdk,
                'note'   => $hasSdk
                    ? 'The Anthropic PHP client is on the autoloader.'
                    : 'Run composer install. Until then every call fails before it leaves the machine.',
            ],
            [
                'label'  => 'A live call has succeeded',
                'value'  => $lastGood === null ? 'Never' : Clock::relative((string) $lastGood['created_at']),
                'ok'     => $lastGood !== null,
                'note'   => $lastGood === null
                    ? 'This is the only line that is evidence rather than intention. Until it is true, treat the three above as a plan.'
                    : sprintf(
                        'Answered by %s in %s.',
                        (string) ($lastGood['model'] ?? 'an unnamed model'),
                        $this->ms((int) ($lastGood['latency_ms'] ?? 0))
                    ),
            ],
        ];

        $working = $driver === 'claude' && $hasKey && $hasSdk && $lastGood !== null;
        $intent  = $driver === 'claude' && $hasKey && $hasSdk;

        return [
            'steps'     => $steps,
            'driver'    => $driver,
            'working'   => $working,
            'configured' => $intent,
            'last_fail' => $lastFail === null ? null : [
                'when'  => Clock::relative((string) $lastFail['created_at']),
                'error' => DriverException::readable((string) $lastFail['error'], 220),
            ],
            'headline'  => match (true) {
                $working  => 'A model is answering, and the log proves it rather than the configuration claiming it.',
                $intent   => 'Configured but unproven. Every setting is in place and no call has ever come back, which is exactly what a silent failure looks like. Run the test below.',
                $driver !== 'claude' => 'Running on arithmetic. Every figure and sentence in the OS is derived from your own records, and nothing is labelled as reasoned.',
                default   => 'Configured incompletely, so the OS is falling back to arithmetic on every call and saying so on each screen.',
            },
            'tone'      => match (true) {
                $working             => 'good',
                $driver !== 'claude' => 'quiet',
                default              => 'bad',
            },
        ];
    }

    // ── The key, without showing it ─────────────────────────────────────────

    /**
     * A page that displays a secret is a page that leaks one, so the key is only
     * ever described. The check that matters is where it lives: this project has
     * had a live key pasted into a committed file twice, and nothing in the app
     * would have noticed.
     *
     * @return array<string,mixed>
     */
    private function keyHygiene(): array
    {
        $active = trim((string) Config::get('ai.api_key', ''));

        // Read the committed file as text rather than executing it. config.php
        // merges config.local.php itself, so requiring it returns the local key
        // and this check would accuse a correctly configured project of leaking.
        $committedFile = GERMA_ROOT . '/config/config.php';
        $keyInCommitted = false;

        if (is_file($committedFile)) {
            $source = (string) file_get_contents($committedFile);

            // A placeholder is an empty string or an obvious stand-in. Anything
            // else on that line is a real value sitting in a committed file.
            if (preg_match("/'api_key'\s*=>\s*'([^']*)'/", $source, $m) === 1) {
                $literal = trim($m[1]);
                $keyInCommitted = $literal !== '' && !str_contains(strtolower($literal), 'your');
            }
        }

        $localFile = GERMA_ROOT . '/config/config.local.php';
        $hasLocal  = is_file($localFile);

        $gitignored = false;
        $ignoreFile = GERMA_ROOT . '/.gitignore';

        if (is_file($ignoreFile)) {
            $gitignored = str_contains((string) file_get_contents($ignoreFile), 'config.local.php');
        }

        return [
            'present'    => $active !== '',
            'length'     => strlen($active),
            'in_committed' => $keyInCommitted,
            'has_local'  => $hasLocal,
            'gitignored' => $gitignored,
            'checks'     => [
                [
                    'label' => 'A key is available to the driver',
                    'ok'    => $active !== '',
                    'note'  => $active === ''
                        ? 'No key. The driver will fall back to rules.'
                        : sprintf('%d characters, read from config and never rendered.', strlen($active)),
                ],
                [
                    'label' => 'The committed config holds no key',
                    'ok'    => !$keyInCommitted,
                    'note'  => $keyInCommitted
                        ? 'A key is sitting in config/config.php, which is committed. Move it to config.local.php and rotate the exposed one.'
                        : 'config/config.php carries a placeholder, which is what makes it safe to commit.',
                ],
                [
                    'label' => 'A local override file exists',
                    'ok'    => $hasLocal,
                    'note'  => $hasLocal
                        ? 'config/config.local.php is where secrets belong.'
                        : 'Create config/config.local.php for the key rather than editing the committed file.',
                ],
                [
                    'label' => 'That file is ignored by git',
                    'ok'    => $gitignored,
                    'note'  => $gitignored
                        ? '.gitignore names config.local.php, so the key cannot be committed by accident.'
                        : '.gitignore does not name config.local.php. Add it before the next commit.',
                ],
            ],
        ];
    }

    // ── What runs on a model, and what does not ─────────────────────────────

    /**
     * The Driver contract has four capabilities and every one of them has a rules
     * implementation. That is the whole reason the OS works without a key, and the
     * reason a chip can be trusted: a capability answered by arithmetic is never
     * labelled as reasoned.
     *
     * @return list<array<string,mixed>>
     */
    private function capabilities(): array
    {
        $today = Clock::todayString();

        $brief = Database::first(
            'SELECT generator, model FROM daily_briefs WHERE for_date = :d',
            ['d' => $today]
        );

        $boardBrief = Database::first(
            'SELECT generator, model FROM board_briefs WHERE for_date = :d',
            ['d' => $today]
        );

        $aiTasks = (int) Database::value(
            "SELECT COUNT(*) FROM tasks WHERE for_date = :d AND origin = 'ai' AND priority_rank IS NOT NULL",
            ['d' => $today],
            0
        );

        $answers = Database::first(
            "SELECT
               COALESCE(SUM(driver = 'claude'), 0) AS model,
               COUNT(*) AS total
             FROM commands"
        );

        return [
            [
                'label'   => 'Daily Command Brief',
                'produces' => 'The eight-part brief on Today, including the one thing not to do',
                'rules'   => 'Assembled from the cash position, the income target and the first open priority',
                'state'   => $brief === null ? 'none' : ((string) $brief['generator'] === 'claude' ? 'model' : 'rules'),
                'detail'  => $brief === null
                    ? 'Not generated today yet.'
                    : ((string) $brief['generator'] === 'claude'
                        ? 'Today\'s came from ' . (string) ($brief['model'] ?? 'a model') . '.'
                        : 'Today\'s is the rules stand-in, and Today says so on the chip.'),
            ],
            [
                'label'   => 'Boardroom position',
                'produces' => 'The one recommendation plus a signal per advisor',
                'rules'   => 'Five roles that can be derived arithmetically, out of the ten in the prompt',
                'state'   => $boardBrief === null ? 'none' : ((string) $boardBrief['generator'] === 'claude' ? 'model' : 'rules'),
                'detail'  => $boardBrief === null
                    ? 'Not generated today yet.'
                    : ((string) $boardBrief['generator'] === 'claude'
                        ? 'Today\'s came from ' . (string) ($boardBrief['model'] ?? 'a model') . ', which is why more than five advisors can speak.'
                        : 'Today\'s is arithmetic, so only the derivable roles have a position.'),
            ],
            [
                'label'   => 'Daily priorities',
                'produces' => 'Three ranked tasks proposed for an empty day',
                'rules'   => 'Stale leads first, then a milestone on an earning project, then recording yesterday',
                // A task records that the OS proposed it, not which engine did.
                // Claiming "model" from origin='ai' would be exactly the mislabel
                // this screen promises never to make, so it says unknown instead.
                'state'   => $aiTasks > 0 ? 'unknown' : 'none',
                'detail'  => $aiTasks > 0
                    ? sprintf(
                        '%d proposed for today, and left alone if you rank your own. The tasks table records that the OS proposed them but not which engine did, so this one cannot be attributed either way.',
                        $aiTasks
                    )
                    : 'Nothing proposed today. These are only generated for a day with no priorities already set.',
            ],
            [
                'label'   => 'Answers to your questions',
                'produces' => 'Everything the dock and the boardroom composer return',
                'rules'   => 'A restatement of the position and the standing do-not, clearly labelled as not reasoned',
                'state'   => (int) ($answers['total'] ?? 0) === 0
                    ? 'none'
                    : ((int) ($answers['model'] ?? 0) > 0 ? 'model' : 'rules'),
                'detail'  => (int) ($answers['total'] ?? 0) === 0
                    ? 'Nothing asked yet.'
                    : sprintf(
                        '%d of %d answered by a model. Every one is kept in the boardroom minutes with which engine replied.',
                        (int) ($answers['model'] ?? 0),
                        (int) ($answers['total'] ?? 0)
                    ),
            ],
        ];
    }

    // ── The request the OS actually sends ───────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function requestShape(): array
    {
        $fallbacks = (bool) Config::get('ai.fallbacks', true);

        return [
            [
                'label' => 'Model',
                'value' => (string) Config::get('ai.model', 'claude-opus-5'),
                'note'  => 'Set in config. Changing it changes cost and capability, and not every model accepts every parameter below.',
                'where' => 'ai.model',
            ],
            [
                'label' => 'Thinking',
                'value' => 'Adaptive',
                'note'  => 'The model decides how much to think per request rather than being given a fixed budget. Not configurable here, because a fixed budget on a current model is rejected outright.',
                'where' => null,
            ],
            [
                'label' => 'Effort',
                'value' => (string) Config::get('ai.effort', 'medium'),
                'note'  => 'How hard the model works on the answer. Higher costs more and takes longer.',
                'where' => 'ai.effort',
            ],
            [
                'label' => 'Token ceiling',
                'value' => number_format((int) Config::get('ai.max_tokens', 8000)),
                'note'  => 'Caps thinking and answer together. Too low and a brief truncates mid-sentence rather than failing loudly.',
                'where' => 'ai.max_tokens',
            ],
            [
                'label' => 'Timeout',
                'value' => (int) Config::get('ai.timeout', 60) . ' seconds',
                'note'  => 'A page render never waits on this: the screen paints from what is stored and upgrades itself afterwards.',
                'where' => 'ai.timeout',
            ],
            [
                'label' => 'Server-side fallbacks',
                'value' => $fallbacks ? 'Requested' : 'Off',
                'note'  => $fallbacks
                    ? 'Asks Anthropic to retry a refused request on a fallback model. Not every model accepts the parameter, so the driver notices a refusal once and drops it for the rest of the process rather than failing every call.'
                    : 'Off, so a refused request fails rather than being retried elsewhere.',
                'where' => 'ai.fallbacks',
            ],
            [
                'label' => 'Structured outputs',
                'value' => 'On for the board position',
                'note'  => 'The board brief is requested against a schema, so the response either validates or fails loudly instead of arriving as prose that needs parsing.',
                'where' => null,
            ],
            [
                'label' => 'Prompt caching',
                'value' => 'On, system role',
                'note'  => 'The system prompt is byte-stable and carries a cache breakpoint. The day\'s figures travel in the user turn: interpolating the date into the system role would invalidate the cache on every single request.',
                'where' => null,
            ],
        ];
    }

    // ── What the log says actually happened ─────────────────────────────────

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        $totals = Database::first(
            "SELECT
               COUNT(*) AS total,
               COALESCE(SUM(driver = 'claude'), 0) AS model,
               COALESCE(SUM(error IS NOT NULL AND error != ''), 0) AS failed,
               COALESCE(ROUND(AVG(CASE WHEN driver = 'claude' THEN latency_ms END)), 0) AS avg_model,
               COALESCE(MAX(latency_ms), 0) AS slowest
             FROM commands"
        );

        $failures = Database::all(
            "SELECT error, COUNT(*) AS n, MAX(created_at) AS last
             FROM commands WHERE error IS NOT NULL AND error != ''
             GROUP BY error ORDER BY n DESC LIMIT 4"
        );

        $rows = [];

        foreach ($failures as $failure) {
            $rows[] = [
                'error' => DriverException::readable((string) $failure['error'], 200),
                'count' => (int) $failure['n'],
                'last'  => Clock::relative((string) $failure['last']),
            ];
        }

        $total  = (int) ($totals['total'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);

        return [
            'total'    => $total,
            'model'    => (int) ($totals['model'] ?? 0),
            'failed'   => $failed,
            'avg'      => $this->ms((int) ($totals['avg_model'] ?? 0)),
            'slowest'  => $this->ms((int) ($totals['slowest'] ?? 0)),
            'failures' => $rows,
            'verdict'  => match (true) {
                $total === 0      => 'No call has been made, so there is nothing to measure. Configuration proves nothing on its own.',
                $failed === $total => 'Every call has failed. The reasons are below, and the OS answered from arithmetic each time rather than showing you an error.',
                $failed > 0       => sprintf(
                    '%d of %d calls failed. A failure is not visible on the other screens: it silently becomes a rules answer with a rules chip.',
                    $failed,
                    $total
                ),
                default           => sprintf('%d calls, none failed.', $total),
            },
            'tone'     => match (true) {
                $total === 0 => 'quiet',
                $failed > 0  => 'warn',
                default      => 'good',
            },
        ];
    }

    // ── The prompt, read only ───────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function prompt(): array
    {
        $blocks = SystemPrompt::blocks();
        $text   = '';

        foreach ($blocks as $block) {
            $text .= (string) ($block['text'] ?? '');
        }

        return [
            'text'   => $text,
            'chars'  => strlen($text),
            'cached' => true,
            'why_readonly' => 'Read only on purpose, for two reasons. It is byte-stable so it can carry a cache '
                . 'breakpoint, and any edit would invalidate that cache on the next request. More importantly, the '
                . 'accountability rules are in here: do not praise automatically, say so when time is being wasted, '
                . 'say so when sales is being avoided by coding. A settings form that could quietly remove those '
                . 'would turn the board into something that agrees with you.',
        ];
    }

    /**
     * The commitments the AI layer makes, which are the only reason a chip means
     * anything. Stated as claims that can be checked rather than reassurance.
     *
     * @return list<array{claim:string,how:string}>
     */
    private function guarantees(): array
    {
        return [
            [
                'claim' => 'Rules output is never labelled as reasoned',
                'how'   => 'Every stored brief records which engine produced it, and the chip is derived from that column rather than from whether a key is configured.',
            ],
            [
                'claim' => 'A page render never waits on a model',
                'how'   => 'Screens paint from what is stored for today. If a model is configured but has not answered yet, the page says so and upgrades itself in the background.',
            ],
            [
                'claim' => 'A failed call degrades rather than breaks',
                'how'   => 'Any driver failure falls back to the rules engine and is logged with its reason. A dashboard that loads without a paragraph beats one that returns a 500.',
            ],
            [
                'claim' => 'The model never invents a figure',
                'how'   => 'Numbers come from the ledger and are passed to the model as context. It is asked to reason about them, not to produce them, and every figure on screen carries a provenance chip that says which.',
            ],
            [
                'claim' => 'Every question is kept',
                'how'   => 'Prompt, answer, engine, model, latency and any error go into the commands table and are shown in the boardroom minutes.',
            ],
            [
                'claim' => 'The key is never rendered',
                'how'   => 'This screen reports its presence and length. No part of the app prints it, including error output.',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $reach
     * @return array{text:string,provenance:string}
     */
    private function insight(array $reach): array
    {
        $parts = [$reach['headline']];

        if ($reach['last_fail'] !== null && !$reach['working']) {
            $parts[] = sprintf('The most recent failure, %s: %s', $reach['last_fail']['when'], $reach['last_fail']['error']);
        }

        $parts[] = 'Nothing on this screen writes configuration. Those values live in PHP files, and a web form that '
            . 'could rewrite them could also quietly remove the rules that make the board argue with you.';

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    private function ms(int $ms): string
    {
        if ($ms <= 0) {
            return 'n/a';
        }

        return $ms < 1000 ? $ms . 'ms' : round($ms / 1000, 1) . 's';
    }
}
