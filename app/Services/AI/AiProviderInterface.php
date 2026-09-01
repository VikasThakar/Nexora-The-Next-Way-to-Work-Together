<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Exceptions\AiProviderException;

/**
 * The whole of the application's dependency on a language model provider.
 *
 * One method, two value objects. Ticket analysis, the workspace chat and
 * anything a later phase adds depend on this interface and never on a vendor
 * SDK, for three reasons that all turned out to matter:
 *
 *   - tests bind a fake and never reach the network. There is no HTTP client to
 *     intercept and no environment variable to remember to unset, so a test
 *     cannot accidentally spend money;
 *   - a second provider, or a per-board choice of provider, is a new class
 *     rather than a change to every caller;
 *   - the credential lives behind here. Nothing above this line has a reason to
 *     know an API key exists, so nothing above this line can leak one.
 *
 * Implementations must not swallow failures. A run that could not talk to the
 * provider has to fail loudly so it can be recorded as failed and written up as
 * an internal note — a silent empty answer would be indistinguishable from the
 * model having nothing to say.
 */
interface AiProviderInterface
{
    /**
     * Send one prompt and return the answer.
     *
     * @throws AiProviderException when the provider is unreachable, refuses the
     *                             credential, rate limits, or answers with
     *                             something that cannot be interpreted
     */
    public function complete(AiPrompt $prompt): AiCompletion;

    /**
     * Is this provider usable right now?
     *
     * Checked before a run is queued so a missing credential is reported once,
     * against the run, instead of surfacing as a stack trace per attempt.
     */
    public function isConfigured(): bool;

    /**
     * A short name for the run's diagnostics, e.g. "anthropic".
     */
    public function name(): string;
}
