<?php

declare(strict_types=1);

namespace App\Services\AI\CodeGeneration;

/**
 * What the coding runtime says it did.
 *
 * Advisory, all of it. The workspace does not trust this to decide whether
 * anything changed — that comes from `git status` — because a runtime that
 * reports a confident summary having edited nothing is a real failure mode, and
 * it is the one that would otherwise produce an empty pull request.
 *
 * Token counts are nullable for the same reason they are on AiCompletion: a
 * runtime that does not report usage stores null rather than a guess.
 */
final readonly class CodeChangeResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $summary,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $model = null,
        public array $metadata = [],
    ) {}
}
