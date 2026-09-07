<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AI\HandleAiRunFailure;
use App\Enums\AiRunStatus;
use App\Models\AiRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fails runs that are `running` with nothing left to run them.
 *
 * A run is claimed by flipping `queued` → `running` in a single conditional
 * UPDATE, and only the claiming job may finish it. That is what makes duplicate
 * execution impossible — and it is also why a worker that dies mid-run strands
 * one for ever:
 *
 *   - claim() accepts only `queued`, so a fresh job cannot take it over;
 *   - reclaim() accepts only the job's OWN retry, which no longer exists;
 *   - nothing else looks at the row.
 *
 * The ticket then shows "Running" indefinitely and the UI refuses to start
 * another run, so a person has to notice and clear it by hand. That is not a
 * rare edge: an apply run takes minutes, and every deploy restarts the worker.
 * A container restart landing inside that window is ordinary, not exceptional.
 *
 * Why the threshold is not the whole test
 * ---------------------------------------
 * Age alone would reap a slow but perfectly healthy run. So a row is only
 * failed when it is BOTH older than the threshold AND has no job on the queue
 * that could still be executing it. On the database queue driver that second
 * condition is checkable; on any other driver it is not, so the command says so
 * and falls back to age alone, which is what --force is for.
 *
 * The default threshold is derived from the job timeout rather than guessed: a
 * run that has outlived the queue's own timeout plus a margin is not slow, it
 * is gone.
 */
class ReapStalledAiRuns extends Command
{
    protected $signature = 'ai:reap-stalled
        {--minutes= : Age in minutes past which a running run is considered stalled}
        {--force : Reap on age alone, without checking the queue for a live job}
        {--dry-run : Report what would be failed without changing anything}';

    protected $description = 'Fail AI runs left in `running` by a worker that died mid-run';

    public function handle(HandleAiRunFailure $handleFailure): int
    {
        $minutes = (int) ($this->option('minutes') ?? $this->defaultThreshold());
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $cutoff = now()->subMinutes(max(1, $minutes));

        $candidates = AiRun::query()
            ->where('status', AiRunStatus::Running->value)
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info(sprintf('No AI runs have been running for more than %d minutes.', $minutes));

            return self::SUCCESS;
        }

        $queueCheckable = $this->queueIsCheckable();

        if (! $queueCheckable && ! $force) {
            $this->warn(
                'The queue connection is not the database driver, so a live job cannot be ruled out. '
                .'Re-run with --force to reap on age alone.'
            );

            return self::FAILURE;
        }

        $reaped = 0;

        foreach ($candidates as $run) {
            if ($queueCheckable && ! $force && $this->hasPendingJob($run)) {
                $this->line(sprintf('Run %d still has a job on the queue; leaving it alone.', $run->getKey()));

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would fail run %d (started %s).',
                    $run->getKey(),
                    $run->started_at?->diffForHumans() ?? 'unknown',
                ));

                $reaped++;

                continue;
            }

            // Through the normal failure path, so the run is written up the same
            // way any other failure is: an internal note naming what happened,
            // rather than a status that silently changed.
            $handleFailure->handle($run, $this->reason());

            $reaped++;
        }

        $this->info(sprintf(
            '%s %d stalled %s.',
            $dryRun ? 'Would fail' : 'Failed',
            $reaped,
            $reaped === 1 ? 'run' : 'runs',
        ));

        return self::SUCCESS;
    }

    /**
     * The job timeout plus a margin, in minutes.
     *
     * Derived rather than hard-coded so raising AI_JOB_TIMEOUT for slower
     * validation does not silently start reaping healthy runs.
     */
    private function defaultThreshold(): int
    {
        $timeout = (int) config('ai.queue.timeout', 900);

        return max(30, (int) ceil($timeout / 60) + 15);
    }

    private function queueIsCheckable(): bool
    {
        $connection = config('ai.queue.connection') ?: config('queue.default');

        return config('queue.connections.'.$connection.'.driver') === 'database';
    }

    /**
     * Is there still a job on the queue that could be executing this run?
     *
     * The payload carries the serialised job, and the run's UUID appears in it.
     * Matching on the UUID rather than the numeric id avoids matching run 1
     * against run 12.
     */
    private function hasPendingJob(AiRun $run): bool
    {
        $uuid = (string) $run->uuid;

        if ($uuid === '') {
            return false;
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%'.$uuid.'%')
            ->exists();
    }

    private function reason(): string
    {
        return 'The worker stopped while this run was in progress — usually a deploy or a container '
            .'restart — so nothing was left to finish it. No code was changed, nothing was committed '
            .'and no pull request was opened. Start a new run.';
    }
}
