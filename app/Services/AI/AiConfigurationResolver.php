<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Models\AiGlobalSettings;
use App\Models\Board;
use App\Support\AiConfiguration;
use App\Support\AiModelCatalogue;
use App\Support\BoardAiSettings;

/**
 * Walks the inheritance chain and says what actually applies.
 *
 *     config/ai.php  →  ai_settings  →  boards.settings.ai
 *
 * Every field is resolved independently. That is the whole design, and it is
 * what makes the client's example work: a board that names a different model
 * gets the workspace's provider, the workspace's key and the workspace's
 * capability mode, and a board that names a different mode keeps the
 * workspace's model. Copying a configuration down a level would have been
 * simpler to write and would have duplicated a secret to change a dropdown.
 *
 * Coercion, not trust
 * -------------------
 * Two rules run after the chain, and both exist because a stored setting
 * outlives the thing it names:
 *
 *   a model the effective provider does not serve is discarded in favour of
 *   that provider's default — so switching the workspace from Anthropic to
 *   OpenAI does not leave every board pointed at a Claude id;
 *
 *   a provider the catalogue has no models for resolves to a null model, and a
 *   null model makes the configuration unusable. That is the honest state for a
 *   provider a deployment has named but not filled in, and it surfaces as "not
 *   configured" on the screen rather than as a 404 from a vendor.
 *
 * Where the capability mode is *enforced* is AiCapabilityGuard. This class only
 * decides what it is.
 *
 * No credential passes through here
 * ---------------------------------
 * The resolved AiConfiguration records where a key would come from and never
 * what it is. Callers that need to send one ask AiCredentialVault at the moment
 * of the call.
 */
class AiConfigurationResolver
{
    public function __construct(private readonly AiCredentialVault $vault) {}

    /**
     * What applies for a board, or for the workspace when no board is in scope.
     *
     * Resolved on every call rather than memoised, deliberately. The two
     * genuinely expensive parts are cached one level down — the settings row by
     * AiGlobalSettings::current(), the credential row by the vault — and both
     * of those are database reads that cannot change within a request.
     * Everything this method adds on top is a JSON decode and a handful of
     * comparisons.
     *
     * A memo here would also be wrong rather than merely unnecessary: it would
     * capture `config('ai.enabled')` and the environment credential at the
     * moment of the first call, so anything that changed configuration
     * mid-request — a command, a test, a future settings screen — would go on
     * seeing the old answer. Caching a decision that depends on live
     * configuration is how a switch stops switching.
     */
    public function forBoard(?Board $board = null): AiConfiguration
    {
        return $this->resolve($board);
    }

    /**
     * What applies workspace-wide, with no board's overrides.
     */
    public function global(): AiConfiguration
    {
        return $this->forBoard(null);
    }

    /**
     * Can a request actually be made in this context?
     *
     * The one question most of the UI asks. Replaces the old
     * `filled(config('ai.anthropic.api_key'))` check everywhere, because a key
     * can now live in three places and "switched off by an administrator" is
     * now a state.
     */
    public function isUsable(?Board $board = null): bool
    {
        return $this->forBoard($board)->isUsable();
    }

    /**
     * The model a request in this context should name.
     */
    public function modelFor(?Board $board = null): ?string
    {
        return $this->forBoard($board)->model;
    }

    /**
     * The provider a request in this context should go to.
     */
    public function providerFor(?Board $board = null): AiProvider
    {
        return $this->forBoard($board)->provider;
    }

    /**
     * How much the AI is trusted in this context.
     */
    public function modeFor(?Board $board = null): AiCapabilityMode
    {
        return $this->forBoard($board)->mode;
    }

    /**
     * Drop the memo.
     *
     * Called after a settings write, so the request that saved the form
     * re-renders from what was stored rather than from what it read on arrival.
     */
    public function flush(): void
    {
        AiGlobalSettings::forgetCached();

        $this->vault->flush();
    }

    // -----------------------------------------------------------------

    private function resolve(?Board $board): AiConfiguration
    {
        $global = AiGlobalSettings::current();
        $overrides = $board instanceof Board ? BoardAiSettings::forBoard($board) : null;

        $sources = [];

        // Provider. A board may point at a different vendor entirely — a
        // project whose customer insists on one.
        $provider = $global->provider;
        $sources['provider'] = $this->globalOrConfig($global->getAttribute('provider') !== null);

        if ($overrides?->providerOverride instanceof AiProvider) {
            $provider = $overrides->providerOverride;
            $sources['provider'] = AiConfiguration::SOURCE_BOARD;
        }

        // Model, then coerced against the provider that will serve it.
        [$model, $modelSource] = $this->resolveModel($global, $overrides, $provider);
        $sources['model'] = $modelSource;

        // Capability mode.
        $mode = $global->capability_mode;
        $sources['mode'] = AiConfiguration::SOURCE_GLOBAL;

        if ($overrides?->capabilityMode instanceof AiCapabilityMode) {
            $mode = $overrides->capabilityMode;
            $sources['mode'] = AiConfiguration::SOURCE_BOARD;
        }

        // Session ceiling. Zero means unlimited at every level, so a board can
        // legitimately lift the workspace's limit as well as tighten it — which
        // is why this is not a min() of the two.
        $sessionLimit = $global->sessionTokenLimit();
        $sources['session_token_limit'] = $global->session_token_limit === null
            ? AiConfiguration::SOURCE_CONFIG
            : AiConfiguration::SOURCE_GLOBAL;

        if ($overrides?->sessionTokenLimit !== null) {
            $sessionLimit = $overrides->sessionTokenLimit;
            $sources['session_token_limit'] = AiConfiguration::SOURCE_BOARD;
        }

        $credentialSource = $this->vault->sourceFor($provider, $board);
        $sources['credential'] = match ($credentialSource) {
            'board' => AiConfiguration::SOURCE_BOARD,
            'global' => AiConfiguration::SOURCE_GLOBAL,
            default => AiConfiguration::SOURCE_CONFIG,
        };

        return AiConfiguration::make(
            enabled: $global->isEnabled(),
            provider: $provider,
            model: $model,
            mode: $mode,
            sessionTokenLimit: $sessionLimit,
            // Deliberately not overridable per board: a per-person daily
            // ceiling that a board could raise would not be a ceiling.
            dailyUserTokenLimit: $global->dailyUserTokenLimit(),
            board: $board,
            credentialSource: $credentialSource,
            sources: $sources,
        );
    }

    /**
     * The effective model, and where it came from.
     *
     * The interesting case is the last one. A board or a workspace can name a
     * model that the effective provider does not serve — because the provider
     * changed underneath it, or because a model was retired from the catalogue
     * — and sending it would produce a vendor error on every request. So it
     * falls back to the provider's own default, and the source is reported as
     * whatever level supplied that default rather than as the board's choice:
     * the screen should not claim a board chose a model it is not using.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveModel(
        AiGlobalSettings $global,
        ?BoardAiSettings $overrides,
        AiProvider $provider,
    ): array {
        if ($overrides?->modelOverride !== null
            && AiModelCatalogue::serves($provider, $overrides->modelOverride)) {
            return [$overrides->modelOverride, AiConfiguration::SOURCE_BOARD];
        }

        $globalModel = $global->defaultModel();

        if (AiModelCatalogue::serves($provider, $globalModel)) {
            return [
                $globalModel,
                $global->model === null
                    ? AiConfiguration::SOURCE_CONFIG
                    : AiConfiguration::SOURCE_GLOBAL,
            ];
        }

        // Null when the catalogue offers this provider nothing. See the class
        // comment: that is an honest "not configured", not a reason to guess.
        return [AiModelCatalogue::defaultFor($provider), AiConfiguration::SOURCE_CONFIG];
    }

    /**
     * The provider column is never null, so "did the workspace choose it?"
     * cannot be answered from the value alone. It is reported as a global
     * decision whenever the row exists, which it always does — the honest
     * simplification, and the settings screen is where that row is edited
     * anyway.
     */
    private function globalOrConfig(bool $hasGlobalValue): string
    {
        return $hasGlobalValue ? AiConfiguration::SOURCE_GLOBAL : AiConfiguration::SOURCE_CONFIG;
    }
}
