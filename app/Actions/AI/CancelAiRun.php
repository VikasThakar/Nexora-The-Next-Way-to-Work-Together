<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Enums\AiRunStatus;
use App\Models\AiRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a run that has not started yet.
 *
 * Only from `queued`, and expressed as a conditional UPDATE for the same reason
 * the job's claim is: a worker may be picking the run up in the same
 * millisecond, and reading the status then writing it would leave a window where
 * a run is both cancelled and executing. The database decides which happened
 * first.
 *
 * A cancelled run writes no note and no ticket event. Nothing happened, and a
 * timeline entry for a thing somebody thought better of is noise.
 *
 * Cancelling does not remove the queued job — that is not possible on a database
 * queue without scanning it. The job runs, finds it cannot claim a run that is
 * no longer `queued`, and returns. That is why the claim is authoritative and
 * this is merely the request.
 */
class CancelAiRun
{
    public function handle(AiRun $run, ?User $actor = null): bool
    {
        $cancelled = DB::table('ai_runs')
            ->where('id', $run->getKey())
            ->where('status', AiRunStatus::Queued->value)
            ->update([
                'status' => AiRunStatus::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($cancelled !== 1) {
            return false;
        }

        $run->refresh();

        $run->metadata = array_merge((array) $run->metadata, [
            'cancelled_by_id' => $actor?->getKey(),
            'cancelled_at' => now()->toIso8601String(),
        ]);
        $run->saveQuietly();

        return true;
    }
}
