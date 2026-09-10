<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Enums\AiUsagePurpose;
use App\Models\AiSession;
use App\Models\AiUsageRecord;
use App\Models\Board;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageRecord>
 *
 * Usage rows are written by App\Services\AI\AiUsageRecorder after a provider
 * answers. This factory is for tests that need a day's worth of them — the
 * per-person daily ceiling, a usage report over a date range — where driving
 * the real recorder would mean faking a provider response per row.
 *
 * The default is a row that *did* report usage, because that is the ordinary
 * case and because the interesting alternative deserves to be explicit:
 * unreported() is a named state, so a test that exercises the "not reported"
 * path says so.
 */
class AiUsageRecordFactory extends Factory
{
    protected $model = AiUsageRecord::class;

    public function definition(): array
    {
        return [
            'ai_session_id' => AiSession::factory(),
            'ai_run_id' => null,

            // Taken from the session, so the denormalised columns cannot
            // disagree with their parent — a fixture where they differ would
            // test a state the application never produces.
            'user_id' => fn (array $attributes): ?int => AiSession::query()
                ->whereKey($attributes['ai_session_id'])
                ->value('user_id'),

            'board_id' => fn (array $attributes): ?int => AiSession::query()
                ->whereKey($attributes['ai_session_id'])
                ->value('board_id'),

            'purpose' => AiUsagePurpose::Chat,
            'provider' => AiProvider::Anthropic,
            'model' => 'claude-opus-5',
            'tokens_input' => 1200,
            'tokens_output' => 350,
            'usage_reported' => true,
            'estimated_cost' => '0.014750',
            'duration_ms' => 1800,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function forSession(AiSession $session): static
    {
        return $this->state(fn (): array => [
            'ai_session_id' => $session->getKey(),
            'user_id' => $session->user_id,
            'board_id' => $session->board_id,
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function onBoard(Board $board): static
    {
        return $this->state(fn (): array => ['board_id' => $board->getKey()]);
    }

    public function tokens(?int $input, ?int $output): static
    {
        return $this->state(fn (): array => [
            'tokens_input' => $input,
            'tokens_output' => $output,
            'usage_reported' => $input !== null || $output !== null,
        ]);
    }

    /**
     * A provider that told us nothing about what it spent.
     *
     * Null counts and `usage_reported` false, which is the pair that means
     * "unknown" rather than "free".
     */
    public function unreported(): static
    {
        return $this->state(fn (): array => [
            'tokens_input' => null,
            'tokens_output' => null,
            'usage_reported' => false,
            'estimated_cost' => null,
        ]);
    }
}
