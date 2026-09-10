<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\AI\Exceptions\ExternalKnowledgeException;
use App\Services\AI\Knowledge\ExternalKnowledgeProviderInterface;
use App\Services\AI\Knowledge\ExternalKnowledgeResult;

/**
 * A configured external knowledge provider, for tests.
 *
 * No deployment ships one, so without this class the only reachable state is
 * "unavailable" — and the interesting assertions are all about the other state:
 * that the tool appears when a conversation is allowed outside, that it does
 * not appear when it is not, and that what it returns reaches the model
 * labelled as external.
 *
 * It records every query, which is what lets a test prove the thing that
 * matters most about this seam: that no workspace content is sent to it. An
 * outside service that received a ticket description would have turned a
 * knowledge feature into a data-exfiltration one, and the only way to check
 * that is to look at what was actually asked.
 */
class FakeExternalKnowledge implements ExternalKnowledgeProviderInterface
{
    /** @var list<array{query: string, limit: int}> */
    public array $searches = [];

    public bool $configured = true;

    public ?ExternalKnowledgeException $throws = null;

    /**
     * Raw values, as a vendor would return them — deliberately not
     * ExternalKnowledgeResult objects, so a test can push a hostile title or a
     * javascript: URL through the normalising constructor.
     *
     * @var list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    public array $results = [
        ['Laravel — The PHP Framework', 'https://laravel.com/docs', 'Laravel is a web application framework with expressive syntax.'],
    ];

    public function name(): string
    {
        return 'fake-knowledge';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function unavailableReason(): ?string
    {
        return $this->configured ? null : 'The fake provider is switched off for this test.';
    }

    /**
     * @return list<ExternalKnowledgeResult>
     */
    public function search(string $query, int $limit = 5): array
    {
        $this->searches[] = ['query' => $query, 'limit' => $limit];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        $built = [];

        foreach (array_slice($this->results, 0, $limit) as [$title, $url, $snippet]) {
            $result = ExternalKnowledgeResult::from($title, $url, $snippet);

            if ($result instanceof ExternalKnowledgeResult) {
                $built[] = $result;
            }
        }

        return $built;
    }

    /**
     * The queries this provider was asked, in order.
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return array_map(
            static fn (array $search): string => $search['query'],
            $this->searches,
        );
    }
}
