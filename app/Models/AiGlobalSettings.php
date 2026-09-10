<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The workspace's AI configuration. One row, always present.
 *
 * The product has no workspace entity — access is granted per board, and
 * everything that looks like a workspace setting is either a board setting or a
 * line in a config file. This is the first genuine exception, and it earns it:
 * which provider, which model, and how far the AI is trusted are questions with
 * one answer per deployment, and answering them per board was the thing the
 * client asked us to stop doing.
 *
 * current() is the only way in
 * ---------------------------
 * It reads the row, creating it from the configured defaults if it is missing,
 * and memoises it for the request. So there is no state in which the
 * application has no answer, and no code path that has to handle a null row.
 * The memo is per-request rather than per-process: this is read on nearly every
 * AI-touching render, and a worker that ran for an hour on a stale copy would
 * silently ignore an administrator turning the AI off.
 *
 * Nothing secret lives here
 * -------------------------
 * There is no key column, no ciphertext and no accessor that could return one.
 * Credentials are AiCredential rows, read only through
 * App\Services\AI\AiCredentialVault. That separation is what lets this model be
 * handed to a Blade view without a second thought.
 *
 * A nullable column means "inherit from config/ai.php". The chain is
 * config → this row → board override, and every step may decline to have an
 * opinion; App\Support\AiConfiguration is where it is resolved.
 */
class AiGlobalSettings extends Model
{
    protected $table = 'ai_settings';

    /**
     * Written only by App\Actions\AI\UpdateGlobalAiSettings, which validates
     * every value first. Empty rather than a list, matching the other AI
     * models: nothing here should be assignable from a request payload.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider' => AiProvider::class,
            'capability_mode' => AiCapabilityMode::class,
            'session_token_limit' => 'integer',
            'daily_user_token_limit' => 'integer',
        ];
    }

    /**
     * The workspace's settings, created on first use.
     *
     * Memoised in the container rather than in a static property, so it is
     * scoped to the request and cannot leak between tests.
     */
    public static function current(): self
    {
        return app()->has('ai.global-settings')
            ? app('ai.global-settings')
            : tap(self::loadOrCreate(), static fn (self $settings) => app()->instance('ai.global-settings', $settings));
    }

    /**
     * Drop the memo, after a write.
     *
     * Called by UpdateGlobalAiSettings so the same request that saves the form
     * re-renders from what was actually stored rather than from what it read on
     * the way in.
     */
    public static function forgetCached(): void
    {
        app()->forgetInstance('ai.global-settings');
    }

    private static function loadOrCreate(): self
    {
        $settings = self::query()->orderBy('id')->first();

        if ($settings instanceof self) {
            return $settings;
        }

        /*
         * No row: a deployment whose migration inserted one and then had it
         * deleted, or a test that truncated the table. Created from the
         * configured defaults rather than from hard-coded ones, so "what does
         * an unconfigured workspace do?" has exactly one answer and it lives in
         * config/ai.php.
         */
        $settings = new self;

        $settings->enabled = true;
        $settings->provider = AiProvider::default();
        $settings->model = null;
        $settings->capability_mode = AiCapabilityMode::default();
        $settings->session_token_limit = null;
        $settings->daily_user_token_limit = null;

        $settings->save();

        return $settings;
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    // ---------------------------------------------------------------------
    // Resolved values
    // ---------------------------------------------------------------------

    /**
     * The default model for the whole workspace.
     *
     * Falls through to config when the row has no opinion, and is NOT coerced
     * against the catalogue here — that is AiConfiguration's job, because the
     * coercion depends on which provider is in effect and a board may have
     * changed it.
     */
    public function defaultModel(): string
    {
        $model = is_string($this->model) ? trim($this->model) : '';

        return $model !== '' ? $model : (string) config('ai.model.default');
    }

    /**
     * Tokens one session may spend, or zero for no limit.
     *
     * Clamped at nought: a negative limit in the column would otherwise read as
     * "every session is already over budget", which is a worse failure than
     * ignoring the value.
     */
    public function sessionTokenLimit(): int
    {
        return max(0, (int) ($this->session_token_limit ?? config('ai.limits.session_tokens', 0)));
    }

    /**
     * Tokens one person may spend in a day, across every session.
     */
    public function dailyUserTokenLimit(): int
    {
        return max(0, (int) ($this->daily_user_token_limit ?? config('ai.limits.daily_user_tokens', 0)));
    }

    /**
     * Is AI switched on at all?
     *
     * Both switches must agree. config('ai.enabled') is the deployment's
     * answer and cannot be overridden from a screen; this row is the
     * workspace's, and can. Neither can force the other on.
     */
    public function isEnabled(): bool
    {
        return (bool) config('ai.enabled') && $this->enabled;
    }
}
