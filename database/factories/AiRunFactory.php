<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiRun>
 *
 * Runs are normally created by App\Actions\AI\CreateAiRun, and tests that care
 * about the rules should go through it. This factory exists for the tests that
 * need *history* — a board that already used its cap, a run to render — where
 * driving the real pipeline twenty times would be slow and beside the point.
 */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'ticket_id' => Ticket::factory(),

            // Taken from the ticket so the denormalised column cannot disagree
            // with its parent — a fixture where they differ would test a state
            // the application never produces.
            'board_id' => fn (array $attributes): int => (int) Ticket::query()
                ->whereKey($attributes['ticket_id'])
                ->value('board_id'),

            'trigger_source' => AiRunTrigger::Manual,
            'triggered_by_id' => null,
            'mode' => AiRunMode::Suggest,
            'status' => AiRunStatus::Queued,
            'model' => 'claude-opus-5',
            'board_repository_id' => null,
            'repository' => null,
            'repository_strategy' => null,
            'result_comment_id' => null,
            'branch_name' => null,
            'pull_request_url' => null,
            'tokens_input' => null,
            'tokens_output' => null,
            'estimated_cost' => null,
            'duration_ms' => null,
            'error_message' => null,
            'metadata' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forTicket(Ticket $ticket): static
    {
        return $this->state(fn (array $attributes): array => [
            'ticket_id' => $ticket->getKey(),
            'board_id' => $ticket->board_id,
        ]);
    }

    public function automatic(): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger_source' => AiRunTrigger::Automatic,
        ]);
    }

    public function manual(?User $actor = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger_source' => AiRunTrigger::Manual,
            'triggered_by_id' => $actor?->getKey(),
        ]);
    }

    public function apply(): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => AiRunMode::Apply]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiRunStatus::Completed,
            'tokens_input' => 4000,
            'tokens_output' => 900,
            'estimated_cost' => '0.042500',
            'duration_ms' => 8200,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Something went wrong.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiRunStatus::Failed,
            'error_message' => $reason,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiRunStatus::Running,
            'started_at' => now(),
        ]);
    }
}
