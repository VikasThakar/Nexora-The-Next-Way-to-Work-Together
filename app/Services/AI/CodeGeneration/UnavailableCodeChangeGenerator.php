<?php

declare(strict_types=1);

namespace App\Services\AI\CodeGeneration;

use App\Models\AiRun;
use App\Models\Ticket;
use App\Services\AI\Exceptions\CodeGenerationException;

/**
 * The default: apply mode is architecturally complete but has no coding runtime.
 *
 * This class exists so that the honest answer is the *default* answer. An apply
 * run on an unconfigured deployment stops here, in a second, before a clone,
 * and produces an internal note that names exactly what to configure. It does
 * not open an empty pull request, does not post an analysis dressed up as a
 * change, and does not silently downgrade itself to suggest mode — a run that
 * quietly did something other than what was asked is worse than one that
 * refused.
 *
 * The whole of what is missing is one binary and one environment variable. See
 * ClaudeCodeGenerator for the wiring.
 */
class UnavailableCodeChangeGenerator implements CodeChangeGeneratorInterface
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return <<<'TEXT'
        Apply mode is not configured on this deployment, so no code was changed and no pull
        request was opened. The rest of the pipeline — isolated checkout, branch, validation,
        commit, push, pull request — is in place and waiting on one step: the runtime that
        actually edits files.

        To enable it on the queue worker service:
          AI_CODE_DRIVER=claude_code
          AI_CLAUDE_CODE_BINARY=claude          (or leave the default and put it on PATH)
          ANTHROPIC_API_KEY=…                   (the worker's own copy)
          AI_REPOSITORY_CLONE_ENABLED=true
          GITHUB_TOKEN=…                        (contents: write, pull_requests: write)

        Until then, use suggest mode: it analyses the ticket and the repository and posts an
        internal note without touching any code.
        TEXT;
    }

    public function generate(AiRun $run, Ticket $ticket, string $checkoutPath, string $brief): CodeChangeResult
    {
        throw CodeGenerationException::notConfigured((string) $this->unavailableReason());
    }
}
