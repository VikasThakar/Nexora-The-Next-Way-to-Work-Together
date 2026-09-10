<?php

declare(strict_types=1);

namespace App\Services\AI\Knowledge;

use App\Services\AI\Exceptions\ExternalKnowledgeException;

/**
 * The whole of the application's dependency on an outside knowledge service.
 *
 * One operation, and a deliberately small one. This exists because the Outside
 * Project feature needs a place for external lookup to *go*, and inventing a
 * search infrastructure for a deployment that has not asked for one would be
 * building the wrong half of the feature. So this is the seam: a deployment
 * that wants live external lookup writes one class implementing this interface
 * and names it in `ai.knowledge.driver`, and nothing else in the application
 * changes — not the prompt, not the chat service, not the tool loop, not the
 * checkbox.
 *
 * Not configured is an answer, not a failure
 * ------------------------------------------
 * The same shape as App\Services\AI\Voice\VoiceProviderInterface and
 * App\Services\AI\CodeGeneration\CodeChangeGeneratorInterface, for the same
 * reason: isConfigured() and unavailableReason() let the capability be
 * unavailable *out loud*. The container always returns something that
 * implements this — UnavailableExternalKnowledge when no driver is named — so
 * no call site has a null check to forget, and a typo in a deployment variable
 * cannot silently turn the refusal off.
 *
 * What this is NOT
 * ----------------
 * It is not how Outside Project mode works. Checking the box already changes
 * the answer, because the assistant's own general knowledge stops being
 * off-limits and the prompt stops declining general questions. This interface
 * is the optional extra: live lookup, for a deployment that wants answers about
 * things that happened after a model was trained. An unconfigured deployment
 * has a fully working Outside Project mode with no live search in it, and says
 * so when asked for something it would need one for.
 *
 * Rules for an implementation
 * ---------------------------
 *   1. Return results, never markup. A result is a title, a URL and a snippet
 *      of text. Anything a provider returns that looks like HTML, a script or a
 *      chart configuration is to be discarded rather than passed on — the
 *      assistant's rich output is built from validated specs
 *      (App\Support\RichResponse) and an external service is not a source of
 *      those.
 *   2. Bound the output. `$limit` is a maximum number of results and each
 *      snippet should be short. This material is going into a context window
 *      alongside a project's own data.
 *   3. Throw ExternalKnowledgeException rather than returning something empty
 *      on failure. An empty result set means "nothing matched", which is a
 *      different sentence from "the lookup did not happen", and the assistant
 *      needs to be able to say the right one.
 *   4. Never send workspace content to the service. The query is a search
 *      phrase; a caller that passes a ticket description to an outside vendor
 *      has turned a knowledge feature into a data-exfiltration one. See
 *      App\Services\AI\Tools\SearchExternalKnowledgeTool, which is the only
 *      caller and bounds the query for exactly this reason.
 */
interface ExternalKnowledgeProviderInterface
{
    /**
     * A short name for diagnostics and the audit trail, e.g. "tavily".
     */
    public function name(): string;

    /**
     * Can this deployment look something up outside the workspace right now?
     */
    public function isConfigured(): bool;

    /**
     * Why not, in words that name the remedy — or null when it can.
     */
    public function unavailableReason(): ?string;

    /**
     * Look something up.
     *
     * @param  string  $query  a search phrase, never workspace content
     * @param  int  $limit  the most results to return
     * @return list<ExternalKnowledgeResult>
     *
     * @throws ExternalKnowledgeException when unconfigured or unreachable
     */
    public function search(string $query, int $limit = 5): array;
}
