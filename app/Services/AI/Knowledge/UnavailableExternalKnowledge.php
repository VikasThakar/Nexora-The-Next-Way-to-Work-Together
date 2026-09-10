<?php

declare(strict_types=1);

namespace App\Services\AI\Knowledge;

use App\Services\AI\Exceptions\ExternalKnowledgeException;

/**
 * The external knowledge provider for a deployment that has not configured one.
 *
 * Which is every deployment of this application today, and that is the expected
 * state rather than a gap: Outside Project mode works without it. What this
 * class removes is the *live lookup* capability, so a question that needs
 * something newer than the model's training gets "I could not look that up"
 * instead of a confident guess.
 *
 * A real implementation rather than a null check at the call site, for the same
 * reason UnavailableVoiceProvider and UnavailableCodeChangeGenerator are ones:
 * every surface then has exactly one question to ask — isConfigured() — and
 * exactly one sentence to show, and the refusal cannot be forgotten at a new
 * call site because the container returned something that refuses.
 *
 * search() throws rather than returning an empty list, and the distinction is
 * the whole point. An empty list means "nothing matched", which a model will
 * quite reasonably report as "there is nothing about that" — a claim this
 * deployment is in no position to make.
 */
class UnavailableExternalKnowledge implements ExternalKnowledgeProviderInterface
{
    public function __construct(private readonly string $reason) {}

    public function name(): string
    {
        return 'unavailable';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return list<ExternalKnowledgeResult>
     */
    public function search(string $query, int $limit = 5): array
    {
        throw ExternalKnowledgeException::notConfigured($this->reason);
    }
}
