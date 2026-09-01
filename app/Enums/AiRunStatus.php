<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an AI run has got to.
 *
 * The lifecycle is deliberately linear and one-way:
 *
 *     Queued ──► Running ──► Completed
 *        │           │
 *        │           └────► Failed
 *        └────────────────► Cancelled
 *
 * `Queued` is the only state a row is created in, and the transition to
 * `Running` is performed as a conditional UPDATE (see App\Jobs\ExecuteAiRunJob)
 * so two workers handed the same job cannot both execute it.
 *
 * A run that has reached a terminal state is never reopened; a re-run is a new
 * row, so the history of what the model said and what it cost stays intact.
 */
enum AiRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Badge colour, matching the x-ui.badge variants.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Running => 'brand',
            self::Completed => 'emerald',
            self::Failed => 'rose',
            self::Cancelled => 'amber',
        };
    }

    /**
     * Has this run finished, one way or another?
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }

    public function isActive(): bool
    {
        return ! $this->isFinished();
    }

    /**
     * May a run in this state still be cancelled by a human?
     */
    public function isCancellable(): bool
    {
        return $this === self::Queued;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The states that count towards a daily cap.
     *
     * A failed run still cost tokens and still hit the provider, so it counts.
     * A cancelled one never started, so it does not.
     *
     * @return array<int, string>
     */
    public static function billableValues(): array
    {
        return [
            self::Queued->value,
            self::Running->value,
            self::Completed->value,
            self::Failed->value,
        ];
    }
}
