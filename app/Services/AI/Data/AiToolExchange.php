<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * One completed round of tool use: what the model asked for, and what it got.
 *
 * A round rather than a single call, because a model may ask for three
 * lookups at once and both providers require the answers to arrive together —
 * one assistant turn holding every call, then one user turn holding every
 * result. Modelling a round as the unit is what keeps that pairing impossible
 * to get wrong.
 *
 * Why this exists at all
 * ----------------------
 * AiPrompt's `messages` are a role and a string, and every existing caller and
 * test depends on that. Tool use needs content blocks, which would have meant
 * widening that type everywhere. Carrying the rounds beside the messages
 * instead means the adapters append two well-formed turns of their own, the
 * plain-text shape is untouched, and a prompt with no tool use serialises
 * exactly as it did before this existed.
 *
 * `text` is any prose the model wrote alongside its tool calls ("Let me check
 * that ticket"). It is preserved because dropping it makes the transcript the
 * provider sees inconsistent with what it actually said, and models notice.
 */
final readonly class AiToolExchange
{
    /**
     * @param  list<AiToolCall>  $calls
     * @param  list<AiToolResult>  $results  one per call, matched by tool-use id
     */
    public function __construct(
        public array $calls,
        public array $results,
        public string $text = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->calls === [] || $this->results === [];
    }
}
