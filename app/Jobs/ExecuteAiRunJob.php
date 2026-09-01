<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AI\HandleAiRunFailure;
use App\Actions\AI\ProcessAiResult;
use App\Enums\AiRunStatus;
use App\Models\AiRun;
use App\Models\BoardRepository;
use App\Models\Ticket;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\ApplyModeRunner;
use App\Services\AI\CodeGeneration\CodeChangeResult;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Exceptions\AiWorkspaceException;
use App\Services\AI\Exceptions\CodeGenerationException;
use App\Services\AI\Exceptions\GitException;
use App\Services\AI\Exceptions\PullRequestException;
use App\Services\AI\TicketAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one AI run, off the request cycle.
 *
 * Ticket creation dispatches this and returns immediately: everything expensive
 * — the provider call, the clone, the tests, the pull request — happens here, on
 * its own queue, in its own worker. A customer pressing "Create" never waits for
 * a model.
 *
 * Duplicate execution
 * -------------------
 * Guarded twice, because the two guards fail differently.
 *
 *   WithoutOverlapping    a cache lock keyed on the run id. Stops two workers
 *                         starting the same run at the same moment. It is a
 *                         lock, so it depends on the cache being shared and
 *                         alive — good, not sufficient.
 *   the status claim      claim() flips queued → running in a single
 *                         conditional UPDATE and checks the affected row count.
 *                         A second worker that gets past the lock finds zero
 *                         rows and returns without doing anything. This is the
 *                         one that is actually authoritative, because it is the
 *                         database rather than a lock.
 *
 * Retries
 * -------
 * Only transient provider failures are worth retrying, and they are the ones
 * that raise AiProviderException. A missing credential, an unavailable coding
 * runtime, a protected branch or a failed test suite will fail identically three
 * times, so those are marked failed on the first attempt and the job stops. That
 * distinction is what keeps a misconfiguration from producing three identical
 * notes twenty minutes apart.
 *
 * A retry re-enters claim() and finds the run in `running` — its own earlier
 * attempt — so reclaim() allows exactly that case and nothing else.
 *
 * Cleanup
 * -------
 * The isolated directory is released in a finally block, so it goes whether the
 * run succeeded, failed or threw. Nothing durable lives there: the note, the
 * pull request URL, the token counts and the failure reason are all rows before
 * the directory is deleted.
 */
class ExecuteAiRunJob implements ShouldQueue
{
    use Queueable;

    /**
     * The run id rather than the model.
     *
     * A serialised model would carry a snapshot of the row taken before the job
     * was queued; the run's status is exactly the field that must be read fresh.
     * SerializesModels would re-fetch it, but it would also fail the whole job
     * if the ticket had been deleted meanwhile, which is a normal outcome that
     * should be handled rather than retried.
     */
    public function __construct(public readonly int $aiRunId) {}

    public function tries(): int
    {
        return max(1, (int) config('ai.queue.tries', 3));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return (array) config('ai.queue.backoff', [30, 120]);
    }

    public function retryUntil(): \DateTimeInterface
    {
        // A ceiling on the whole run, attempts included. Past this it is stale:
        // the ticket has probably been dealt with by a person.
        return now()->addHours(2);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->aiRunId))
                ->expireAfter((int) config('ai.queue.timeout', 900))
                // Do not queue a second attempt behind the first: if a run is
                // already executing, this job has nothing to add.
                ->dontRelease(),
        ];
    }

    public function handle(
        TicketAnalysisService $analysis,
        ApplyModeRunner $apply,
        ProcessAiResult $processResult,
        HandleAiRunFailure $handleFailure,
        AiRunWorkspace $workspace,
    ): void {
        $run = AiRun::query()->find($this->aiRunId);

        if (! $run instanceof AiRun) {
            // The board or ticket was deleted, taking the run with it.
            return;
        }

        if (! $this->claim($run)) {
            return;
        }

        $run->refresh();

        $ticket = Ticket::query()->with(['board', 'column', 'labels', 'assignee', 'creator'])
            ->find($run->ticket_id);

        if (! $ticket instanceof Ticket) {
            $handleFailure->handle($run, 'The ticket was deleted before the run could start.');

            return;
        }

        $startedAt = microtime(true);
        $succeeded = false;

        try {
            $workspace->acquire($run);

            $repository = $run->board_repository_id !== null
                ? BoardRepository::query()->find($run->board_repository_id)
                : null;

            $run->mode->writesCode()
                ? $this->runApply($run, $ticket, $repository, $apply, $processResult, $startedAt)
                : $this->runSuggest($run, $ticket, $repository, $analysis, $processResult, $startedAt);

            $succeeded = true;
        } catch (AiProviderException $exception) {
            // Transient by nature — rate limits, overload, timeouts. Worth
            // another attempt, so rethrow and let the queue decide.
            $retrying = $this->willRetry();

            $handleFailure->handle($run, $exception->getMessage(), willRetry: $retrying);

            if ($retrying) {
                throw $exception;
            }

            $this->markFailed($run, $exception->getMessage(), $handleFailure);
        } catch (
            AiWorkspaceException
            |CodeGenerationException
            |GitException
            |PullRequestException $exception
        ) {
            // Deterministic. A second attempt would fail the same way, so it
            // stops here rather than repeating the note twice more.
            $this->markFailed($run, $exception->getMessage(), $handleFailure);
        } catch (Throwable $exception) {
            $this->markFailed(
                $run,
                'The run stopped unexpectedly: '.$exception->getMessage(),
                $handleFailure
            );
        } finally {
            if (! $workspace->release($run, $succeeded)) {
                // Not a failure of the run. Recorded so a worker leaking
                // directories is visible rather than mysterious.
                $run->metadata = array_merge((array) $run->metadata, ['workspace_retained' => true]);
                $run->saveQuietly();
            }
        }
    }

    /**
     * Last resort: the job itself failed (timeout, worker killed, retries done).
     *
     * Reached without handle() finishing, so the run would otherwise sit in
     * `running` for ever and the ticket would never get its note.
     */
    public function failed(?Throwable $exception): void
    {
        $run = AiRun::query()->find($this->aiRunId);

        if (! $run instanceof AiRun || $run->status->isFinished()) {
            return;
        }

        app(HandleAiRunFailure::class)->handle(
            $run,
            $exception?->getMessage() ?? 'The run did not finish. The worker stopped or timed out.',
        );
    }

    // -----------------------------------------------------------------

    private function runSuggest(
        AiRun $run,
        Ticket $ticket,
        ?BoardRepository $repository,
        TicketAnalysisService $analysis,
        ProcessAiResult $processResult,
        float $startedAt,
    ): void {
        $outcome = $analysis->analyse($run, $ticket, $repository);
        $completion = $outcome['completion'];

        $processResult->handle(
            run: $run,
            ticket: $ticket,
            body: $this->suggestNote($run, $completion->text, $outcome['diagnostics']),
            inputTokens: $completion->inputTokens,
            outputTokens: $completion->outputTokens,
            model: $completion->model,
            durationMs: $this->elapsed($startedAt),
            metadata: array_merge($outcome['diagnostics'], $completion->metadata),
        );
    }

    private function runApply(
        AiRun $run,
        Ticket $ticket,
        ?BoardRepository $repository,
        ApplyModeRunner $apply,
        ProcessAiResult $processResult,
        float $startedAt,
    ): void {
        if (! $repository instanceof BoardRepository) {
            throw CodeGenerationException::notConfigured(
                'The repository chosen for this run is no longer attached to the board, so apply '
                .'mode had nothing to work in.'
            );
        }

        $outcome = $apply->run($run, $ticket, $repository);

        $processResult->handle(
            run: $run,
            ticket: $ticket,
            body: $this->applyNote($run, $outcome),
            inputTokens: $outcome['result']->inputTokens,
            outputTokens: $outcome['result']->outputTokens,
            model: $outcome['result']->model,
            durationMs: $this->elapsed($startedAt),
            pullRequestUrl: $outcome['pull_request_url'],
            branchName: $outcome['branch'],
            metadata: [
                'changed_files' => array_slice($outcome['changed_files'], 0, 200),
                'validation' => $outcome['validation'],
                'runtime' => $outcome['result']->metadata['runtime'] ?? null,
            ],
        );
    }

    /**
     * Claim the run for this worker.
     *
     * A single conditional UPDATE — the only place the status moves off `queued`
     * — so the database decides who executes it. Reading the status and then
     * writing it would leave a window two workers can both pass through.
     */
    private function claim(AiRun $run): bool
    {
        $claimed = DB::table('ai_runs')
            ->where('id', $run->getKey())
            ->where('status', AiRunStatus::Queued->value)
            ->update([
                'status' => AiRunStatus::Running->value,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            return true;
        }

        return $this->reclaim($run);
    }

    /**
     * Allow this job's own retry to continue a run it already started.
     *
     * A retry finds the run in `running` because its previous attempt put it
     * there. That is the one case where a non-queued run may proceed, and only
     * on attempt two or later — a first attempt finding `running` means another
     * worker has it.
     */
    private function reclaim(AiRun $run): bool
    {
        $run->refresh();

        return $run->status === AiRunStatus::Running && $this->attempts() > 1;
    }

    private function markFailed(AiRun $run, string $reason, HandleAiRunFailure $handleFailure): void
    {
        $handleFailure->handle($run, $reason);
    }

    /**
     * Is there really another attempt coming?
     *
     * Two conditions, and the second is easy to overlook. On the `sync` driver
     * there is no next attempt at all, and rethrowing would push the exception
     * out through whoever dispatched the job — which, for an automatic run, is
     * the request that created the ticket. A provider hiccup would then surface
     * as a 500 on a customer's ticket form, which is exactly the coupling this
     * whole job exists to avoid.
     *
     * So on sync, the run is marked failed and written up immediately instead.
     */
    private function willRetry(): bool
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            return false;
        }

        return $this->attempts() < $this->tries();
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Wrap the model's analysis in a short provenance line.
     *
     * The footer matters more than it looks: a note in the internal thread with
     * no author needs to say what wrote it, in what mode, and — crucially —
     * whether it could actually read the code. Otherwise a confident assessment
     * produced from the ticket text alone reads exactly like one produced from
     * the repository.
     *
     * @param  array<string, mixed>  $diagnostics
     */
    private function suggestNote(AiRun $run, string $analysis, array $diagnostics): string
    {
        $checkedOut = (bool) ($diagnostics['repository_checked_out'] ?? false);

        $provenance = ucfirst($run->trigger_source->value).' suggest-mode run · '
            .($run->model ?? 'model not recorded')
            .($run->repository !== null ? ' · '.$run->repository : ' · no repository')
            .' · '.($checkedOut ? 'read the repository' : 'did NOT read the repository');

        return trim($analysis)."\n\n---\n\n*".$provenance
            .'. No code was changed. Internal note — not visible to the customer.*';
    }

    /**
     * @param  array{
     *     result: CodeChangeResult,
     *     branch: string,
     *     base: string,
     *     pull_request_url: string,
     *     changed_files: list<string>,
     *     diffstat: string,
     *     validation: list<array{command: string, passed: bool}>
     * }  $outcome
     */
    private function applyNote(AiRun $run, array $outcome): string
    {
        $lines = [
            '**Apply-mode run opened a draft pull request.**',
            '',
            '- Pull request: '.$outcome['pull_request_url'],
            '- Branch: `'.$outcome['branch'].'` → `'.$outcome['base'].'`',
            '- Files changed: '.count($outcome['changed_files']),
        ];

        if ($outcome['validation'] !== []) {
            $lines[] = '- Validation: '.implode(
                ', ',
                array_map(
                    static fn (array $entry): string => '`'.$entry['command'].'` passed',
                    $outcome['validation']
                )
            );
        } else {
            $lines[] = '- Validation: none configured on this deployment';
        }

        $summary = trim($outcome['result']->summary);

        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '### What the run says it did';
            $lines[] = '';
            $lines[] = $summary;
        }

        if (trim($outcome['diffstat']) !== '') {
            $lines[] = '';
            $lines[] = '### Diffstat';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = trim($outcome['diffstat']);
            $lines[] = '```';
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '*'.ucfirst($run->trigger_source->value).' apply-mode run · '
            .($run->model ?? 'model not recorded')
            .' · '.(string) $run->repository
            .'. The pull request is a draft and has NOT been reviewed or merged. '
            .'Internal note — not visible to the customer.*';

        return implode("\n", $lines);
    }
}
