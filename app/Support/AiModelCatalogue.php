<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiProvider;

/**
 * Every model this deployment will send a request to.
 *
 * config('ai.models') is the data; this is the only class that reads it. That
 * matters because the same three questions are asked from four places — the
 * global settings form, the board override form, the model picker in the
 * assistant, and the resolver that decides what a request actually uses — and a
 * fourth copy of "is this model allowed?" is a fourth chance to disagree.
 *
 * The coercion rule
 * -----------------
 * A stored model id is never trusted, on read or on write. A board configured
 * for a model that has since been retired, or for a model belonging to a
 * provider the workspace has since switched away from, resolves to that
 * provider's default rather than being sent as-is. So the failure mode of a
 * stale setting is "a working model answered" rather than "every request 400s",
 * which is the same choice BoardAiSettings already makes for its own fields.
 *
 * All static, no state. The catalogue is configuration: it cannot change
 * within a request, and there is nothing to memoise that config() has not
 * memoised already.
 */
final class AiModelCatalogue
{
    /**
     * The whole catalogue, keyed by model id, in configured order.
     *
     * @return array<string, AiModel>
     */
    public static function all(): array
    {
        $models = [];

        foreach ((array) config('ai.models', []) as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $model = AiModel::fromConfig((string) $id, $entry);

            if ($model instanceof AiModel) {
                $models[$model->id] = $model;
            }
        }

        return $models;
    }

    /**
     * The models one provider serves.
     *
     * @return array<string, AiModel>
     */
    public static function forProvider(AiProvider $provider): array
    {
        return array_filter(
            self::all(),
            static fn (AiModel $model): bool => $model->provider === $provider
        );
    }

    /**
     * Look one up, or null.
     */
    public static function find(?string $id): ?AiModel
    {
        $id = trim((string) $id);

        return $id === '' ? null : (self::all()[$id] ?? null);
    }

    /**
     * Does this provider serve this model?
     */
    public static function serves(AiProvider $provider, ?string $id): bool
    {
        return self::find($id)?->provider === $provider;
    }

    /**
     * Which provider serves a model, or null if the catalogue does not know it.
     *
     * Used by the settings forms to keep the two dropdowns honest: choosing a
     * model is also choosing a provider, and a form that let them disagree
     * would be a form that saves a configuration nothing can run.
     */
    public static function providerFor(?string $id): ?AiProvider
    {
        return self::find($id)?->provider;
    }

    /**
     * The model to use for a provider when the stored choice is unusable.
     *
     * config('ai.model.default') wins when that provider serves it — a
     * deployment naming a default means it — and otherwise the first model the
     * catalogue lists for the provider. Null only when the catalogue offers
     * that provider nothing at all, which is the honest answer for a provider
     * whose model list a deployment has not filled in: the resolver reports it
     * as unconfigured rather than inventing an id.
     */
    public static function defaultFor(AiProvider $provider): ?string
    {
        $configured = (string) config('ai.model.default');

        if (self::serves($provider, $configured)) {
            return $configured;
        }

        $first = array_key_first(self::forProvider($provider));

        return $first === null ? null : (string) $first;
    }

    /**
     * Every id in the catalogue, for a validation rule.
     *
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * The ids one provider serves, for a validation rule on a form where the
     * provider is already known.
     *
     * @return array<int, string>
     */
    public static function idsFor(AiProvider $provider): array
    {
        return array_keys(self::forProvider($provider));
    }

    /**
     * Does the catalogue offer this provider anything?
     */
    public static function hasModelsFor(AiProvider $provider): bool
    {
        return self::forProvider($provider) !== [];
    }
}
