<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Models\Board;

/**
 * The AI configuration that actually applies, and where each part of it came
 * from.
 *
 * There are three layers, and they are read in this order:
 *
 *     config/ai.php          the deployment's answer
 *     ai_settings            the workspace's answer, set by an administrator
 *     boards.settings.ai     one board's departure from it
 *
 * Every field resolves independently down that chain, which is what makes
 * partial overrides work: a board that wants a different model but the
 * workspace's provider, credential and capability mode says exactly that, and
 * nothing else has to be repeated. It is also why `sources` exists — a screen
 * that wants to render "Inherited from global" needs to know the difference
 * between a board that chose the same value and a board that chose nothing, and
 * comparing values cannot tell them apart.
 *
 * What is NOT here
 * ----------------
 * The credential. This object is handed to Blade views, and a value object that
 * carried a key would eventually be dumped into one. Keys are resolved
 * separately, at the moment of the call, by App\Services\AI\AiCredentialVault;
 * `credentialSource` below records *where* the key would come from, which is
 * the part a settings screen legitimately needs to show.
 *
 * Built only by App\Services\AI\AiConfigurationResolver. The constructor is
 * private so nothing can assemble a configuration that skipped a layer.
 */
final readonly class AiConfiguration
{
    public const SOURCE_CONFIG = 'config';

    public const SOURCE_GLOBAL = 'global';

    public const SOURCE_BOARD = 'board';

    /**
     * @param  array<string, string>  $sources  field name => one of the SOURCE_* constants
     */
    private function __construct(
        public bool $enabled,
        public AiProvider $provider,
        public ?string $model,
        public AiCapabilityMode $mode,
        public int $sessionTokenLimit,
        public int $dailyUserTokenLimit,
        public ?Board $board,
        public string $credentialSource,
        public array $sources,
    ) {}

    /**
     * @param  array<string, string>  $sources
     */
    public static function make(
        bool $enabled,
        AiProvider $provider,
        ?string $model,
        AiCapabilityMode $mode,
        int $sessionTokenLimit,
        int $dailyUserTokenLimit,
        ?Board $board,
        string $credentialSource,
        array $sources,
    ): self {
        return new self(
            enabled: $enabled,
            provider: $provider,
            model: $model,
            mode: $mode,
            sessionTokenLimit: max(0, $sessionTokenLimit),
            dailyUserTokenLimit: max(0, $dailyUserTokenLimit),
            board: $board,
            credentialSource: $credentialSource,
            sources: $sources,
        );
    }

    /**
     * The model to send, coerced to something the provider serves.
     *
     * Never null in practice for a configured provider: the resolver has
     * already applied AiModelCatalogue::defaultFor(). Null survives only for a
     * provider whose model list a deployment has not filled in, and in that
     * state isUsable() is false and no request is made.
     */
    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * The catalogue entry for the effective model, if the catalogue knows it.
     */
    public function modelInfo(): ?AiModel
    {
        return AiModelCatalogue::find($this->model);
    }

    /**
     * What a person calls the effective model.
     */
    public function modelLabel(): string
    {
        return $this->modelInfo()?->label ?? (string) ($this->model ?? 'not configured');
    }

    /**
     * Can a request actually be made with this configuration?
     *
     * Three things have to be true, and they fail for genuinely different
     * reasons: AI is switched on, a model is resolvable, and a credential is
     * reachable from somewhere. The resolver fills in `credentialSource` as
     * 'none' when it is not, which is the only state that is about a secret and
     * the only one this object records about one.
     */
    public function isUsable(): bool
    {
        return $this->enabled
            && $this->model !== null
            && $this->credentialSource !== 'none';
    }

    /**
     * Where did this field's value come from?
     */
    public function sourceOf(string $field): string
    {
        return $this->sources[$field] ?? self::SOURCE_CONFIG;
    }

    /**
     * Is this field inherited rather than chosen by the board in question?
     *
     * What the board settings screen renders as "Inherited from global". True
     * for a workspace-level configuration too, where everything is inherited
     * from the deployment by definition.
     */
    public function isInherited(string $field): bool
    {
        return $this->sourceOf($field) !== self::SOURCE_BOARD;
    }

    /**
     * A short phrase naming the origin, for a hint under a form control.
     */
    public function sourceLabel(string $field): string
    {
        return match ($this->sourceOf($field)) {
            self::SOURCE_BOARD => 'Set on this board',
            self::SOURCE_GLOBAL => 'Inherited from global AI settings',
            default => 'Inherited from the deployment default',
        };
    }

    /**
     * Does this board depart from the workspace configuration in any way?
     *
     * Used to decide whether a board's settings screen shows an "overridden"
     * marker at all, so the common case — a board that inherits everything —
     * reads as quiet rather than as a form full of "inherited" labels.
     */
    public function hasBoardOverrides(): bool
    {
        return in_array(self::SOURCE_BOARD, $this->sources, true);
    }
}
