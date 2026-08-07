<?php

declare(strict_types=1);

namespace Germa\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Germa\Support\Config;
use Throwable;

/**
 * Anthropic Messages API driver.
 *
 * Three design points worth stating, because they are easy to get wrong:
 *
 *  - The system role is byte-stable and carries a cache breakpoint, so the day's
 *    figures travel in the user turn. Interpolating the date into the system
 *    prompt would invalidate the cache on every single request.
 *  - The board brief uses structured outputs rather than asking for JSON in
 *    prose, so the response either validates or fails loudly.
 *  - A refusal comes back as HTTP 200 with stop_reason "refusal" and empty
 *    content. Reading content[0] without checking would break on it.
 */
final class ClaudeDriver implements Driver
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * Set once the API has told us this model will not accept `fallbacks`, so
     * the retry costs one request per process rather than one per call.
     */
    private static bool $fallbacksRejected = false;

    private ?Client $client = null;

    public function name(): string
    {
        return 'claude';
    }

    public function dailyBrief(Context $context): array
    {
        $instruction = <<<'TXT'
Write today's standing brief for Gerald.

`brief` is two or three sentences, no heading and no greeting: where the household actually stands, then what today has to achieve.

`do_not` is the single thing he should not do today, the spec's Daily Command Brief always ends with one. Phrase it as the action itself, not as an instruction: "Put more hours into FlowPesa", not "Do not put more hours into FlowPesa". The screen supplies the "do not". Keep it under twenty words, and make it something the position below actually argues against. Use null only if nothing in the data warrants one.

Today's recorded position follows as JSON. Treat every figure in it as fact.
TXT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'brief'  => ['type' => 'string'],
                'do_not' => ['type' => ['string', 'null']],
            ],
            'required'             => ['brief', 'do_not'],
            'additionalProperties' => false,
        ];

        $decoded = json_decode($this->text($instruction, $context, 'medium', $schema), true);

        if (!is_array($decoded) || !isset($decoded['brief'])) {
            throw new DriverException('Daily brief response did not match the expected schema.');
        }

        $doNot = $decoded['do_not'] ?? null;

        return [
            'brief'  => trim((string) $decoded['brief']),
            'do_not' => is_string($doNot) && trim($doNot) !== '' ? trim($doNot) : null,
        ];
    }

    public function boardBrief(Context $context): BoardOutput
    {
        $instruction = <<<'TXT'
Convene the board on Gerald's position for today and return one consolidated recommendation.

The recommendation is two or three sentences naming the single highest-value action available today, with a number or a name in it. The rationale is one or two sentences on the evidence behind it.

Then give a signal for each executive that had a view: "ok" if their area is sound, "warn" if it needs attention, "up" if it is improving, "down" if it is deteriorating, "neutral" if there is nothing to say. Use the role names CEO, CFO, CTO, Sales, Product, Operations, Research, Risk, Critic, Household. Include between three and six of them only the ones the position actually concerns. Each note is a short phrase, not a sentence.

Today's recorded position follows as JSON. Treat every figure in it as fact.
TXT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'recommendation' => ['type' => 'string'],
                'rationale'      => ['type' => 'string'],
                'signals'        => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'role'   => [
                                'type' => 'string',
                                'enum' => ['CEO', 'CFO', 'CTO', 'Sales', 'Product', 'Operations', 'Research', 'Risk', 'Critic', 'Household'],
                            ],
                            'signal' => ['type' => 'string', 'enum' => BoardOutput::SIGNALS],
                            'note'   => ['type' => 'string'],
                        ],
                        'required'             => ['role', 'signal', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['recommendation', 'rationale', 'signals'],
            'additionalProperties' => false,
        ];

        $raw     = $this->text($instruction, $context, 'high', $schema);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['recommendation'])) {
            throw new DriverException('Board response did not match the expected schema.');
        }

        $signals = [];
        foreach ((array) ($decoded['signals'] ?? []) as $signal) {
            if (!is_array($signal) || !isset($signal['role'], $signal['signal'])) {
                continue;
            }
            $signals[] = [
                'role'   => (string) $signal['role'],
                'signal' => in_array($signal['signal'], BoardOutput::SIGNALS, true) ? (string) $signal['signal'] : 'neutral',
                'note'   => isset($signal['note']) ? (string) $signal['note'] : null,
            ];
        }

        return new BoardOutput(
            trim((string) $decoded['recommendation']),
            isset($decoded['rationale']) ? trim((string) $decoded['rationale']) : null,
            $signals,
            $this->name(),
            $this->model(),
        );
    }

    public function dailyPriorities(Context $context): array
    {
        $instruction = <<<'TXT'
Set exactly three priorities for Gerald today, ordered so the first is the highest-value thing he can do.

Each is one concrete action with a number or a name in it "Follow up with the 12 leads that have gone quiet", not "do sales". Give a realistic estimate in minutes and the outcome that would mean it worked.

Weigh time-to-cash against probability. When runway is short, work that can be invoiced beats work that cannot, and a conversation already in the pipeline beats a new one. Do not propose freelance marketplaces.

Today's recorded position follows as JSON. Treat every figure in it as fact, and only propose work it supports.
TXT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'priorities' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'title'             => ['type' => 'string'],
                            'estimated_minutes' => ['type' => 'integer'],
                            'expected_outcome'  => ['type' => 'string'],
                        ],
                        'required'             => ['title', 'estimated_minutes', 'expected_outcome'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['priorities'],
            'additionalProperties' => false,
        ];

        $decoded = json_decode($this->text($instruction, $context, 'high', $schema), true);

        if (!is_array($decoded) || !is_array($decoded['priorities'] ?? null)) {
            throw new DriverException('Priority response did not match the expected schema.');
        }

        $priorities = [];
        foreach (array_slice($decoded['priorities'], 0, 3) as $item) {
            if (!is_array($item) || !isset($item['title'])) {
                continue;
            }
            $priorities[] = [
                'title'             => trim((string) $item['title']),
                'estimated_minutes' => max(5, (int) ($item['estimated_minutes'] ?? 60)),
                'expected_outcome'  => trim((string) ($item['expected_outcome'] ?? '')),
            ];
        }

        if ($priorities === []) {
            throw new DriverException('The model proposed no priorities.');
        }

        return $priorities;
    }

    public function answer(string $question, Context $context): string
    {
        $instruction = "Gerald asked: \"" . trim($question) . "\"\n\n"
            . "Answer it directly, using his recorded position below. Lead with the answer, then the reasoning, then the next action. "
            . "If the position does not contain what you need to answer properly, say what is missing rather than guessing.\n\n"
            // The answer lands in a narrow panel, so headings and nested
            // structure read badly. Bold run-in labels and flat bullets do not.
            . "This is displayed in a small panel on his dashboard. Use plain paragraphs and short bullet lines starting with \"- \". "
            . "No markdown headings, no tables, no nested lists. A bold run-in label at the start of a line is fine.\n\n"
            . "Treat every figure in the JSON as fact.";

        return $this->text($instruction, $context, (string) Config::get('ai.effort', 'medium'));
    }

    // ── Transport ───────────────────────────────────────────────────────────

    /**
     * One request, one text answer. Returns the concatenated text blocks.
     *
     * @param array<string,mixed>|null $schema Structured-output schema, if any.
     */
    private function text(string $instruction, Context $context, string $effort, ?array $schema = null): string
    {
        $outputConfig = ['effort' => $effort];
        if ($schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }

        $params = [
            'model'        => $this->model(),
            'maxTokens'    => (int) Config::get('ai.max_tokens', 8000),
            'system'       => SystemPrompt::blocks(),
            'thinking'     => ['type' => 'adaptive'],
            'outputConfig' => $outputConfig,
            'messages'     => [[
                'role'    => 'user',
                'content' => $instruction . "\n\n<position>\n" . $context->toJson() . "\n</position>",
            ]],
            'requestOptions' => ['timeout' => (float) Config::get('ai.timeout', 60)],
        ];

        try {
            $message = $this->send($params);
        } catch (RateLimitException $e) {
            throw new DriverException('Anthropic rate limit reached: ' . $e->getMessage(), 0, $e);
        } catch (APIConnectionException $e) {
            throw new DriverException('Could not reach the Anthropic API: ' . $e->getMessage(), 0, $e);
        } catch (APIStatusException $e) {
            throw new DriverException('Anthropic API error (' . ($e->type?->value ?? 'unknown') . '): ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new DriverException('AI request failed: ' . $e->getMessage(), 0, $e);
        }

        // A refused request is a 200 with empty or partial content check the
        // stop reason before touching content at all.
        if (($message->stopReason ?? null) === 'refusal') {
            throw new DriverException('The request was declined by Anthropic\'s safety classifiers.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new DriverException('The model returned no text (stop reason: ' . (string) ($message->stopReason ?? 'unknown') . ').');
        }

        return $text;
    }

    /** @param array<string,mixed> $params */
    private function send(array $params): object
    {
        $client = $this->client();

        // Fallbacks are a beta parameter, so they force the beta namespace.
        if ((bool) Config::get('ai.fallbacks', true) && !self::$fallbacksRejected) {
            $betaParams = array_merge($params, [
                'fallbacks' => 'default',
                'betas'     => [self::FALLBACK_BETA],
            ]);

            try {
                return $client->beta->messages->create(...$betaParams);
            } catch (Throwable $e) {
                // Not every model accepts server-side fallbacks, and the models
                // that do change over time. Rather than hard-coding a list that
                // will rot, notice the refusal once and drop the parameter for
                // the rest of the process. Silently degrading to the rules
                // engine, which is what happened before this: made a
                // configuration error look like the model having no opinion.
                if (!self::rejectsFallbacks($e)) {
                    throw $e;
                }

                self::$fallbacksRejected = true;
            }
        }

        return $client->messages->create(...$params);
    }

    /** Whether the API refused the request specifically over `fallbacks`. */
    private static function rejectsFallbacks(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'fallbacks') !== false;
    }

    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        if (!class_exists(Client::class)) {
            throw new DriverException('The Anthropic PHP SDK is not installed. Run: composer install');
        }

        $key = (string) Config::get('ai.api_key', '');
        if ($key === '') {
            throw new DriverException('No Anthropic API key configured. Set ai.api_key in config/config.local.php.');
        }

        return $this->client = new Client(apiKey: $key);
    }

    private function model(): string
    {
        return (string) Config::get('ai.model', 'claude-opus-5');
    }
}
