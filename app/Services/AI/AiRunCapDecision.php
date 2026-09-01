<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The answer to "may this board start another run today?".
 *
 * A value object rather than a bool, because callers need three different things
 * out of one question: whether to proceed, what to tell a person, and what to
 * record. Returning a bool would push the message-building back out to every
 * call site, and then two of them would word it differently.
 *
 * `bypassed` is distinct from `allowed` on purpose: it means an administrator
 * went past a cap, which is worth recording on the run even though the outcome
 * is the same.
 */
final readonly class AiRunCapDecision
{
    private function __construct(
        public bool $allowed,
        public int $used,
        public int $limit,
        public bool $bypassed,
        public ?string $reason,
    ) {}

    public static function allowed(int $used, int $limit): self
    {
        return new self(true, $used, $limit, false, null);
    }

    public static function bypassed(int $used, int $limit): self
    {
        return new self(true, $used, $limit, true, null);
    }

    public static function blocked(int $used, int $limit, string $reason): self
    {
        return new self(false, $used, $limit, false, $reason);
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * For `ai_runs.metadata`, so a run records the state of the cap when it was
     * created rather than when somebody later goes looking.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'bypassed' => $this->bypassed,
        ];
    }
}
