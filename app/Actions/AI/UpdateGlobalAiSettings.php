<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Models\AiGlobalSettings;
use App\Models\User;
use App\Services\AI\AiConfigurationResolver;
use App\Support\AiModelCatalogue;

/**
 * Write the workspace's AI configuration.
 *
 * Thin, like UpdateBoardAiSettings, and for the same reason: the coercion lives
 * next to the values it protects rather than in the screen that happens to
 * submit them, so a value that reaches the row by another route — a console
 * command, a data migration, somebody with a MySQL client — is still read back
 * as something the application can run.
 *
 * Two things it does that are worth stating.
 *
 * It refuses a model the provider does not serve
 * ----------------------------------------------
 * Choosing OpenAI and a Claude model is not a configuration with a sensible
 * interpretation, and storing it would produce a vendor error on every
 * subsequent request. So the model is validated against the *submitted*
 * provider, and an unusable pair falls back to that provider's default rather
 * than being stored. The form prevents it too; this is what makes the rule true
 * rather than merely presented.
 *
 * It never touches a credential
 * -----------------------------
 * Keys are the vault's business. A single action that saved both the
 * configuration and a key would be an action whose parameters sometimes carry a
 * secret, and every caller of it would then need to be audited for logging. So
 * there are two, and this is the one that is safe to log.
 */
class UpdateGlobalAiSettings
{
    public function __construct(private readonly AiConfigurationResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?User $actor = null): AiGlobalSettings
    {
        $settings = AiGlobalSettings::current();

        if (array_key_exists('enabled', $attributes)) {
            $settings->enabled = (bool) $attributes['enabled'];
        }

        $provider = AiProvider::fromValue($attributes['provider'] ?? null) ?? $settings->provider;

        $settings->provider = $provider;

        if (array_key_exists('model', $attributes)) {
            $settings->model = $this->model($attributes['model'], $provider);
        } elseif (! AiModelCatalogue::serves($provider, $settings->model)) {
            /*
             * The provider changed and the stored model belongs to the old one.
             * Cleared rather than translated: there is no meaningful mapping
             * between two vendors' models, and null means "use the provider's
             * default", which is the right answer and is visibly an inherited
             * one on the screen.
             */
            $settings->model = null;
        }

        $mode = AiCapabilityMode::tryFrom((string) ($attributes['capability_mode'] ?? ''));

        if ($mode instanceof AiCapabilityMode) {
            $settings->capability_mode = $mode;
        }

        if (array_key_exists('session_token_limit', $attributes)) {
            $settings->session_token_limit = $this->limit($attributes['session_token_limit']);
        }

        if (array_key_exists('daily_user_token_limit', $attributes)) {
            $settings->daily_user_token_limit = $this->limit($attributes['daily_user_token_limit']);
        }

        $settings->updated_by_id = $actor?->getKey();

        $settings->save();

        // The request that saved the form must re-render from what was stored,
        // not from what it read on the way in — a cleared model or a clamped
        // limit has to be visible immediately.
        $this->resolver->flush();

        return $settings->refresh();
    }

    /**
     * A model this provider serves, or null for "use the default".
     *
     * An empty submission is a deliberate choice to inherit, and is stored as
     * null. Anything the catalogue does not recognise for this provider is
     * treated the same way, so a stale form or a crafted request degrades to a
     * working configuration rather than an unrunnable one.
     */
    private function model(mixed $value, AiProvider $provider): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && AiModelCatalogue::serves($provider, $value) ? $value : null;
    }

    /**
     * A ceiling in tokens: null to inherit the config default, zero for none.
     *
     * The two are different states and both are reachable from the form, which
     * is why this cannot collapse to an int. Negative values become zero
     * rather than null, because somebody typing -1 means "no limit" far more
     * often than they mean "ask the config file".
     */
    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
