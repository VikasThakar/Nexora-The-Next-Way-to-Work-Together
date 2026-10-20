<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Models\AiToolInvocation;
use App\Models\Board;

/**
 * What one write tool call came to: the text the AI app reads, and what the
 * audit row records about it.
 */
final readonly class WriteOutcome
{
    private function __construct(
        public string $outcome,
        public string $text,
        public ?string $target = null,
        public ?Board $board = null,
        public ?string $note = null,
    ) {}

    public static function ok(string $text, ?string $target, ?Board $board, ?string $note = null): self
    {
        return new self(AiToolInvocation::OUTCOME_OK, $text, $target, $board, $note);
    }

    /**
     * Nothing was changed: the person has to confirm first.
     */
    public static function awaiting(string $text, ?string $target, ?Board $board): self
    {
        return new self(AiToolInvocation::OUTCOME_AWAITING, $text, $target, $board, 'Asked the person to confirm.');
    }

    public static function refused(string $text, ?string $target = null, ?Board $board = null): self
    {
        return new self(AiToolInvocation::OUTCOME_REFUSED, $text, $target, $board);
    }

    public static function invalid(string $text): self
    {
        return new self(AiToolInvocation::OUTCOME_INVALID, $text);
    }

    public static function notFound(string $text, ?string $target = null): self
    {
        return new self(AiToolInvocation::OUTCOME_NOT_FOUND, $text, $target);
    }

    public static function failed(string $text, ?string $target = null, ?Board $board = null, ?string $note = null): self
    {
        return new self(AiToolInvocation::OUTCOME_ERROR, $text, $target, $board, $note);
    }

    /**
     * Did the call do what it was for? A request to confirm counts: it is the
     * correct answer to a first call, not a failure.
     */
    public function succeeded(): bool
    {
        return in_array($this->outcome, [AiToolInvocation::OUTCOME_OK, AiToolInvocation::OUTCOME_AWAITING], true);
    }

    public function changedSomething(): bool
    {
        return $this->outcome === AiToolInvocation::OUTCOME_OK;
    }

    public function withText(string $text, ?string $note = null): self
    {
        return new self($this->outcome, $text, $this->target, $this->board, $note ?? $this->note);
    }
}
