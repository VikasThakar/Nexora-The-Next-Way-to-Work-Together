<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\Data\AiCompletion;

/**
 * The result of one question that may have taken several provider calls.
 *
 * `completion` is the merged answer: the prose from every round joined, the
 * token counts added up, and the tool calls from the final round — which is
 * where a proposal appears if the model made one. Merging is what lets
 * everything downstream (the usage ledger, the stored turn, the proposal
 * extraction) stay unchanged and unaware that a loop happened.
 *
 * `invocations` is what was actually looked up, in order, for the answer's
 * provenance line. It carries the tool name, the subject and whether it worked
 * — not the material, which belongs in the answer and in nothing else.
 */
final readonly class AiToolRun
{
    /**
     * @param  list<array{tool: string, target: ?string, success: bool}>  $invocations
     */
    public function __construct(
        public AiCompletion $completion,
        public int $rounds = 0,
        public array $invocations = [],
    ) {}

    public function usedTools(): bool
    {
        return $this->invocations !== [];
    }

    /**
     * How many lookups actually returned something.
     */
    public function successfulInvocations(): int
    {
        return count(array_filter($this->invocations, static fn (array $row): bool => $row['success']));
    }
}
