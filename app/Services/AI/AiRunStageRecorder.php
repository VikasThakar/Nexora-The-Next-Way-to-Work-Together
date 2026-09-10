<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRunStage;
use App\Models\AiRun;
use Throwable;

/**
 * Writes down what a run is doing, as it does it.
 *
 * A run takes minutes: a clone, a coding runtime, a test suite, a push. Without
 * this the ticket panel shows "Running" for all of it and somebody watching
 * cannot tell a stuck clone from a long test suite — which is exactly the
 * moment they need to.
 *
 * Two properties make it safe to call from inside the pipeline.
 *
 * It never fails the run
 * ----------------------
 * Every write is swallowed. Progress reporting must not be able to break the
 * work it is reporting on: a locked row or a full disk should cost the progress
 * line, not the pull request.
 *
 * It touches nothing but the stage
 * -------------------------------
 * The write is a targeted merge into `metadata` followed by saveQuietly(), so
 * it raises no model events, bumps no timestamp and cannot collide with the
 * status transitions ExecuteAiRunJob owns. Status is the lifecycle; this is
 * commentary on it — see App\Enums\AiRunStage for why they are separate.
 */
class AiRunStageRecorder
{
    /**
     * @param  array<string, mixed>  $extra  merged alongside the stage, for
     *                                       facts worth showing beside it (a
     *                                       branch name, a file count)
     */
    public function record(AiRun $run, AiRunStage $stage, array $extra = []): void
    {
        try {
            $run->metadata = array_merge((array) $run->metadata, $extra, [
                'stage' => $stage->value,
                'stage_at' => now()->toIso8601String(),
            ]);

            $run->saveQuietly();
        } catch (Throwable) {
            // Deliberately silent. See the class comment.
        }
    }
}
