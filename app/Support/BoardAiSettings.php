<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Enums\AiRunMode;
use App\Models\Board;
use App\Services\AI\AiConfigurationResolver;
use Illuminate\Support\Arr;

/**
 * The AI configuration of one board, resolved.
 *
 * Stored under `boards.settings.ai` rather than in columns, matching how every
 * other board option already works: a new AI setting needs no migration and no
 * backfill, and a board that predates a setting simply falls back to the
 * application default in config/ai.php.
 *
 * Reading is centralised here so nothing else in the codebase ever reaches into
 * that JSON. Two consequences matter:
 *
 *   - defaults are applied in exactly one place, so "what happens on a board
 *     nobody configured?" has one answer;
 *   - values are coerced and clamped on the way out as well as validated on the
 *     way in, so a hand-edited row cannot make the runner do something the
 *     settings form would have refused. A cap of -1 or "banana" resolves to the
 *     configured default, not to "unlimited".
 *
 * Repositories are deliberately NOT here: they are rows in `board_repositories`
 * because other rows point at them. `primaryRepository` holds only a *name*, a
 * hint the selector uses to break a tie.
 *
 * Overrides, since AI became a global feature
 * -------------------------------------------
 * Most of what used to be decided here is now decided once for the workspace
 * (`ai_settings`) and only *departed from* per board. So the fields added for
 * that are all nullable, and null means "inherit" rather than "off":
 * `providerOverride`, `capabilityMode`, `sessionTokenLimit`, and the board's own
 * `credentials`. App\Services\AI\AiConfigurationResolver walks the chain;
 * nothing here knows what the workspace decided, which is why this class can
 * still be constructed from an array with no database behind it.
 *
 * The model is one setting read two ways
 * --------------------------------------
 * `modelOverride` is the raw stored choice, nullable, and is what the resolver
 * reads. `model` is the same setting coerced to something usable — the board's
 * choice if it made one, otherwise the deployment default — and is what a
 * caller that just wants a model string reads. Same stored key, no second
 * source of truth, and no caller has to remember which fallback applies.
 *
 * No plaintext credential lives on this object
 * --------------------------------------------
 * A board's keys stay in the JSON as ciphertext, and this class exposes only
 * whether one is present and its last four characters. That is a deliberate
 * departure from BoardSlackSettings, which does decrypt its webhook URL into a
 * public property: this object is handed to Blade views by the settings
 * directory, and a provider key is worth more than a channel webhook. The
 * decryption lives in App\Services\AI\AiCredentialVault, which no view can
 * reach.
 */
final readonly class BoardAiSettings
{
    /**
     * @param  array<string, array<string, mixed>>  $rawCredentials  provider => stored credential record, secret still encrypted
     */
    private function __construct(
        public bool $autoRunEnabled,
        public AiRunMode $autoRunMode,
        public string $model,
        public ?string $customSystemPrompt,
        public ?string $projectContext,
        public ?string $primaryRepository,
        public int $dailyAutoRunCap,
        public ?AiProvider $providerOverride,
        public ?string $modelOverride,
        public ?AiCapabilityMode $capabilityMode,
        public ?int $sessionTokenLimit,
        private array $rawCredentials,
    ) {}

    public static function forBoard(Board $board): self
    {
        return self::fromArray(self::rawFor($board));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $defaults = (array) config('ai.board_defaults', []);

        $mode = self::mode($raw['auto_run_mode'] ?? ($defaults['auto_run_mode'] ?? null));

        return new self(
            autoRunEnabled: (bool) ($raw['auto_run_enabled'] ?? ($defaults['auto_run_enabled'] ?? false)),
            autoRunMode: $mode,
            model: self::model($raw['model'] ?? ($defaults['model'] ?? null)),
            customSystemPrompt: self::text($raw['custom_system_prompt'] ?? ($defaults['custom_system_prompt'] ?? null)),
            projectContext: self::text($raw['project_context'] ?? ($defaults['project_context'] ?? null)),
            primaryRepository: self::text($raw['primary_repository'] ?? ($defaults['primary_repository'] ?? null)),
            dailyAutoRunCap: self::cap($raw['daily_auto_run_cap'] ?? ($defaults['daily_auto_run_cap'] ?? null)),
            providerOverride: AiProvider::fromValue($raw['provider_override'] ?? ($defaults['provider_override'] ?? null)),
            modelOverride: self::modelOverride($raw['model'] ?? ($defaults['model'] ?? null)),
            capabilityMode: self::capabilityMode($raw['capability_mode'] ?? ($defaults['capability_mode'] ?? null)),
            sessionTokenLimit: self::optionalLimit($raw['session_token_limit'] ?? ($defaults['session_token_limit'] ?? null)),
            rawCredentials: self::credentials($raw['credentials'] ?? ($defaults['credentials'] ?? [])),
        );
    }

    /**
     * The stored shape, for writing back through App\Actions\Boards\UpdateBoard.
     *
     * `model` is written from `modelOverride` rather than from `model`, so a
     * board that inherits keeps inheriting across a save. Writing the coerced
     * value would silently pin the board to whatever the deployment default
     * happened to be on the day somebody pressed Save, which is the bug this
     * pair of properties exists to avoid.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'auto_run_enabled' => $this->autoRunEnabled,
            'auto_run_mode' => $this->autoRunMode->value,
            'model' => $this->modelOverride,
            'custom_system_prompt' => $this->customSystemPrompt,
            'project_context' => $this->projectContext,
            'primary_repository' => $this->primaryRepository,
            'daily_auto_run_cap' => $this->dailyAutoRunCap,
            'provider_override' => $this->providerOverride?->value,
            'capability_mode' => $this->capabilityMode?->value,
            'session_token_limit' => $this->sessionTokenLimit,
            'credentials' => $this->rawCredentials,
        ];
    }

    /**
     * The mode an automatic run would actually use, or null for "do nothing".
     *
     * Both switches have to agree. Two settings rather than one because turning
     * automation off for a fortnight should not lose the mode a board spent
     * time choosing — and because a code path that reads only the mode still
     * cannot start a run on a board where automation is off.
     */
    public function automaticMode(): ?AiRunMode
    {
        if (! $this->autoRunEnabled) {
            return null;
        }

        return $this->autoRunMode->startsARun() ? $this->autoRunMode : null;
    }

    /**
     * Whether AI is usable at all on this deployment.
     *
     * Kept as a static helper because half the UI asks this question without a
     * board in hand. It now answers it through the resolver, so a key stored in
     * the database counts and a workspace that has switched AI off reads as
     * unconfigured — which is what every caller already meant by the question.
     */
    public static function providerConfigured(): bool
    {
        return app(AiConfigurationResolver::class)->isUsable(null);
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    /**
     * Does this board hold its own key for a provider?
     */
    public function hasCredentialFor(AiProvider $provider): bool
    {
        return trim((string) Arr::get($this->rawCredentials, $provider->value.'.secret')) !== '';
    }

    /**
     * The last four characters of this board's key, for the masked display.
     *
     * Not a secret: four characters cannot authenticate anything.
     */
    public function credentialLastFour(AiProvider $provider): ?string
    {
        $lastFour = trim((string) Arr::get($this->rawCredentials, $provider->value.'.last_four'));

        return $lastFour === '' ? null : $lastFour;
    }

    /**
     * The stored ciphertext for a provider, for AiCredentialVault only.
     *
     * Returns the encrypted value, never a plaintext key. The vault is the only
     * class that calls Crypt on it; this method exists so the vault does not
     * have to reach into `boards.settings` and duplicate the shape.
     */
    public function encryptedCredential(AiProvider $provider): ?string
    {
        $secret = trim((string) Arr::get($this->rawCredentials, $provider->value.'.secret'));

        return $secret === '' ? null : $secret;
    }

    /**
     * The stored credential records with one provider replaced or removed.
     *
     * Takes ciphertext, because the caller that has a plaintext key is the
     * vault and the vault encrypts before it gets here. Returns the whole map
     * for UpdateBoard to store, so a board with two providers configured does
     * not lose one when the other is rotated.
     *
     * @return array<string, array<string, mixed>>
     */
    public function withCredential(AiProvider $provider, ?string $encrypted, ?string $lastFour): array
    {
        $credentials = $this->rawCredentials;

        if ($encrypted === null) {
            unset($credentials[$provider->value]);

            return $credentials;
        }

        $credentials[$provider->value] = [
            'secret' => $encrypted,
            'last_four' => $lastFour,
            'rotated_at' => now()->toIso8601String(),
        ];

        return $credentials;
    }

    /**
     * Which providers this board holds a key for.
     *
     * @return array<int, AiProvider>
     */
    public function credentialProviders(): array
    {
        return array_values(array_filter(
            AiProvider::cases(),
            fn (AiProvider $provider): bool => $this->hasCredentialFor($provider)
        ));
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function rawFor(Board $board): array
    {
        $raw = Arr::get($board->settings ?? [], 'ai');

        return is_array($raw) ? $raw : [];
    }

    private static function mode(mixed $value): AiRunMode
    {
        if ($value instanceof AiRunMode) {
            return $value;
        }

        return AiRunMode::tryFrom((string) $value) ?? AiRunMode::default();
    }

    /**
     * Only a model this deployment allows. Anything else falls back to the
     * default, so a stale board setting after a model is retired degrades to a
     * working model rather than a 400 from the provider on every run.
     */
    private static function model(mixed $value): string
    {
        return self::modelOverride($value) ?? (string) config('ai.model.default');
    }

    /**
     * The board's model choice, or null when it has not made one.
     *
     * The same validation as model() and deliberately not the same fallback:
     * this is the value the inheritance chain reads, so "nothing chosen" has to
     * survive as null rather than becoming the deployment default here.
     */
    private static function modelOverride(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && AiModelCatalogue::find($value) !== null ? $value : null;
    }

    private static function capabilityMode(mixed $value): ?AiCapabilityMode
    {
        if ($value instanceof AiCapabilityMode) {
            return $value;
        }

        return is_string($value) ? AiCapabilityMode::tryFrom($value) : null;
    }

    /**
     * A nullable ceiling: null inherits, zero means unlimited.
     *
     * Both states are meaningful and they are not the same, which is why this
     * cannot collapse to an int with a magic value.
     */
    private static function optionalLimit(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /**
     * The stored credential records, shaped and stripped of anything unexpected.
     *
     * Keyed by provider, and a key for a provider this application has no
     * adapter for is dropped: a credential nothing can send is a credential
     * sitting in a database for no reason.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function credentials(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $credentials = [];

        foreach (AiProvider::cases() as $provider) {
            $record = $value[$provider->value] ?? null;

            if (! is_array($record)) {
                continue;
            }

            $secret = trim((string) ($record['secret'] ?? ''));

            if ($secret === '') {
                continue;
            }

            $lastFour = trim((string) ($record['last_four'] ?? ''));

            $credentials[$provider->value] = [
                'secret' => $secret,
                'last_four' => $lastFour === '' ? null : mb_substr($lastFour, 0, 8),
                'rotated_at' => is_string($record['rotated_at'] ?? null) ? $record['rotated_at'] : null,
            ];
        }

        return $credentials;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * At least zero, at most the deployment ceiling.
     *
     * Zero is meaningful and allowed: "automation configured, but paused for
     * today" without losing the rest of the settings.
     */
    private static function cap(mixed $value): int
    {
        $ceiling = max(0, (int) config('ai.caps.max_daily_auto_runs', 500));
        $fallback = min($ceiling, max(0, (int) config('ai.caps.daily_auto_runs', 20)));

        if (! is_numeric($value)) {
            return $fallback;
        }

        return max(0, min($ceiling, (int) $value));
    }
}
