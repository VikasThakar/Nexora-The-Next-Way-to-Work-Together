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
    ) {}

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
