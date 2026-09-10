<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiProvider;
use App\Models\Board;
use App\Support\AiConfiguration;

/**
 * Hands out a provider adapter configured for one context.
 *
 * Before AI became a global feature there was one provider and one credential,
 * so the container binding was enough: `AiProviderInterface` resolved to
 * ClaudeService and ClaudeService read the environment. Neither of those
 * assumptions holds now — the vendor is a setting, and the key can belong to
 * the workspace or to a single board — so something has to decide which
 * adapter, holding which credential, answers a given request. This is that
 * something, and it is the only place that decides it.
 *
 * The container binding still wins
 * --------------------------------
 * If the container holds an implementation that is not one of the built-in
 * adapters, it is returned unchanged, whatever context was asked for. That is
 * not a loophole: it is how tests substitute Tests\Support\FakeAiProvider and
 * how Tests\Support\UnreachableAiProvider makes an unfaked test fail loudly
 * instead of reaching the network. Routing around a deliberate binding would
 * quietly re-enable both.
 *
 * The check is on the concrete class rather than on a flag, because the
 * question really is "has somebody substituted the provider?" and the honest
 * way to ask it is to look.
 *
 * Never a singleton
 * -----------------
 * A new adapter per resolution. A provider instance holds a credential and, in
 * ClaudeService's case, a lazily built HTTP client bound to it; one cached
 * instance would eventually send one board's key on another board's request.
 * The cost is constructing a small object, and the client inside it is still
 * built lazily and reused for the length of that instance.
 */
class AiProviderRegistry
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly AiCredentialVault $vault,
    ) {}

    /**
     * The provider for a board, or workspace-wide when none is given.
     *
     * The ordinary entry point: it resolves the configuration, reads the
     * credential that applies, and returns an adapter that will use both.
     */
    public function forBoard(?Board $board = null): AiProviderInterface
    {
        return $this->forConfiguration($this->configuration->forBoard($board));
    }

    /**
     * The provider for an already-resolved configuration.
     *
     * Used where the caller has resolved the configuration for its own reasons
     * — to record the model on a session, say — so the adapter and the record
     * cannot disagree about which vendor answered.
     */
    public function forConfiguration(AiConfiguration $configuration): AiProviderInterface
    {
        return $this->make(
            $configuration->provider,
            $this->vault->keyFor($configuration->provider, $configuration->board),
        );
    }

    /**
     * An adapter for one provider, with an explicit credential.
     *
     * The credential is passed in rather than looked up here, so this method
     * has no reason to know about boards and the vault has no reason to know
     * about adapters.
     */
    public function make(AiProvider $provider, ?string $apiKey = null): AiProviderInterface
    {
        $substituted = $this->substituted();

        if ($substituted instanceof AiProviderInterface) {
            return $substituted;
        }

        return match ($provider) {
            AiProvider::Anthropic => new ClaudeService($apiKey),
            AiProvider::OpenAi => new OpenAiService($apiKey),
        };
    }

    /**
     * Whatever the container holds, if it is not one of ours.
     *
     * See the class comment. Resolving the binding is safe and cheap: both
     * built-in adapters construct nothing until they are asked to send
     * something.
     */
    private function substituted(): ?AiProviderInterface
    {
        $bound = app(AiProviderInterface::class);

        if ($bound instanceof ClaudeService || $bound instanceof OpenAiService) {
            return null;
        }

        return $bound;
    }
}
