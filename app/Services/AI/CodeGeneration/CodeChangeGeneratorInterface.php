<?php

declare(strict_types=1);

namespace App\Services\AI\CodeGeneration;

use App\Models\AiRun;
use App\Models\Ticket;
use App\Services\AI\Exceptions\CodeGenerationException;

/**
 * The one step of apply mode that needs an agentic coding runtime.
 *
 * Everything else in apply mode is ordinary code this application owns: an
 * isolated directory, a clone, a branch, a validation command, a commit, a push,
 * a pull request. Only this step — reading a repository and editing files until
 * a ticket is done — needs a tool that can loop over a filesystem, which is a
 * separate executable rather than a single HTTP request.
 *
 * Putting it behind an interface has a specific consequence: the rest of apply
 * mode is complete, testable and reviewable now, and swapping in a runtime is a
 * binding change rather than a rewrite. The default implementation refuses
 * loudly (see UnavailableCodeChangeGenerator) instead of returning a fabricated
 * success, so nothing ever opens a pull request that only looks like work.
 */
interface CodeChangeGeneratorInterface
{
    /**
     * Modify the working tree at $checkoutPath to implement the ticket.
     *
     * The implementation must not commit, branch, push or merge: it edits files
     * and returns. The workspace owns everything that makes a change permanent,
     * which is what keeps "never push to main, never merge" enforceable in one
     * reviewable place.
     *
     * @param  string  $brief  the system-level instructions, from PromptLibrary
     * @return CodeChangeResult what it did, for the internal note
     *
     * @throws CodeGenerationException when no change could be produced
     */
    public function generate(AiRun $run, Ticket $ticket, string $checkoutPath, string $brief): CodeChangeResult;

    /**
     * Is this runtime usable on this worker right now?
     *
     * Checked before a clone, so an unconfigured deployment fails in a second
     * with a useful message rather than after pulling down a repository.
     */
    public function isAvailable(): bool;

    /**
     * What is missing, when it is not available.
     *
     * Written to be read by whoever has to fix it: name the environment
     * variables and the binary, not the internal class.
     */
    public function unavailableReason(): ?string;

    public function name(): string;
}
