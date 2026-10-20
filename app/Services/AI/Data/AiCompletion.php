<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * What a provider answered.
 *
 * Token counts are nullable and mean exactly what they say. A provider that
 * does not report usage produces null, and null travels all the way to the
 * `ai_runs` row and to the screen, where it renders as "not reported". Nothing
 * substitutes a plausible number: an invented figure in a cost report is worse
 * than a blank one, because somebody will add it up.
 *
 * `model` is the model the provider says actually served the request, which is
 * not necessarily the one that was asked for, and it is what the cost is
 * calculated from.
 *
 * `inputTokens` is every token the model read, cached or not. Anthropic reports
 * the cached part separately and leaves it out of its own `input_tokens`; the
 * adapter adds it back, so the figure means the same thing it always has —
 * before caching existed it was all of them — and the same thing OpenAI's
 * `prompt_tokens` already means. `cacheReadTokens` and `cacheWriteTokens` are
 * the parts of it served from, and written to, the provider's cache. They are
 * what make the cost honest: a cached token costs a tenth of a fresh one.
 *
 * `contextTokens` is how much of the model's window the conversation now fills:
 * the last request's input plus what it wrote back. For one request that
 * follows from the two counts; App\Services\AI\Tools\AiToolRunner sets it
 * explicitly when several rounds were added together, because the window is
 * filled by the last round, not by the sum of all of them.
 */
final readonly class AiCompletion
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $text,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $model = null,
        public ?string $stopReason = null,
        public array $toolCalls = [],
        public array $metadata = [],
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $contextTokens = null,
    ) {}

    /**
     * How much of the model's window this exchange leaves filled, or null when
     * the provider reported nothing to tell.
     */
    public function contextSize(): ?int
    {
        if ($this->contextTokens !== null) {
            return $this->contextTokens;
        }

        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return (int) $this->inputTokens + (int) $this->outputTokens;
    }

    public function hasText(): bool
    {
        return trim($this->text) !== '';
    }

    public function firstToolCall(): ?AiToolCall
    {
        return $this->toolCalls[0] ?? null;
    }

    /**
     * Did the provider decline the request outright?
     *
     * Worth distinguishing from an error: a refusal is a considered answer, and
     * the internal note should say so rather than reporting a fault.
     */
    public function wasRefused(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
