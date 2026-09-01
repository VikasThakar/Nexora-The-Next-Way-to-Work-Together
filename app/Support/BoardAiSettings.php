<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiRunMode;
use App\Models\Board;
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
 */
final readonly class BoardAiSettings
{
    private function __construct(
        public bool $autoRunEnabled,
        public AiRunMode $autoRunMode,
        public string $model,
        public ?string $customSystemPrompt,
        public ?string $projectContext,
        public ?string $primaryRepository,
        public int $dailyAutoRunCap,
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
        );
    }

    /**
     * The stored shape, for writing back through App\Actions\Boards\UpdateBoard.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'auto_run_enabled' => $this->autoRunEnabled,
            'auto_run_mode' => $this->autoRunMode->value,
            'model' => $this->model,
            'custom_system_prompt' => $this->customSystemPrompt,
            'project_context' => $this->projectContext,
            'primary_repository' => $this->primaryRepository,
            'daily_auto_run_cap' => $this->dailyAutoRunCap,
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
     * A board can be configured perfectly and still have nothing to talk to.
     */
    public static function providerConfigured(): bool
    {
        return (bool) config('ai.enabled')
            && filled(config('ai.anthropic.api_key'));
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
        $allowed = array_keys((array) config('ai.model.allowed', []));
        $default = (string) config('ai.model.default');

        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && in_array($value, $allowed, true) ? $value : $default;
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
