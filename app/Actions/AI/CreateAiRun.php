<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\AI\Exceptions\AiRunRefused;
use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Enums\TicketEventType;
use App\Jobs\ExecuteAiRunJob;
use App\Models\AiRun;
use App\Models\BoardRepository;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\AiRunCap;
use App\Services\AI\AiRunReader;
use App\Services\AI\RepositorySelector;
use App\Services\TicketActivity;
use App\Support\BoardAiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create an AI run and hand it to the queue.
 *
 * The one entry point, for automatic and manual triggers alike, so that every
 * precondition is checked in one place and cannot be checked differently by two
 * callers. In order:
 *
 *   1. AI enabled for the deployment, and a credential present. Checked here
 *      rather than in the job so an unconfigured deployment says so once, on the
 *      screen, instead of failing three times per run in a worker.
 *   2. The daily cap. Automatic runs are capped absolutely; manual ones loosely,
 *      with an administrator bypass when configured. See AiRunCap.
 *   3. Repository selection, resolved and snapshotted onto the run now rather
 *      than when the job executes: which repository was chosen, and why, must
 *      be the same fact afterwards even if the board is reconfigured meanwhile.
 *   4. Apply mode needs a repository. Suggest mode does not.
 *
 * What this action does NOT do is decide whether the person is allowed. That is
 * AiRunPolicy's job, applied at the Livewire component, because authorization
 * belongs to the request and an automatic run has no requester to authorize.
 *
 * Speed matters here. This runs inside the transaction that creates a ticket, so
 * it does one insert and dispatches one job — no HTTP, no clone, no provider
 * call. The dispatch is afterCommit, so a worker cannot pick up a run whose row
 * has not landed yet, and a rolled-back ticket takes its run with it.
 */
class CreateAiRun
{
    public function __construct(
        private readonly AiRunCap $cap,
        private readonly AiRunReader $runs,
        private readonly RepositorySelector $selector,
        private readonly TicketActivity $activity,
    ) {}

    /**
     * @throws AiRunRefused when a precondition is not met
     */
    public function handle(
        Ticket $ticket,
        AiRunMode $mode,
        AiRunTrigger $trigger,
        ?User $actor = null,
    ): AiRun {
        $ticket->loadMissing('board');
        $board = $ticket->board;

        if (! $mode->startsARun()) {
            throw AiRunRefused::modeOff();
        }

        if (! (bool) config('ai.enabled')) {
            throw AiRunRefused::disabled();
        }

        if (! BoardAiSettings::providerConfigured()) {
            throw AiRunRefused::notConfigured();
        }

        $decision = $this->cap->check($board, $trigger, $actor);

        if (! $decision->allowed) {
            // A blocked automatic run leaves a trace on the timeline, so the
            // team can see that the cap bit rather than wondering why nothing
            // happened. The event type is internal-only.
            $this->activity->record($ticket, TicketEventType::AiRunSkipped, [
                'mode' => $mode->value,
                'trigger' => $trigger->value,
                'reason' => $decision->reason,
                'cap' => $decision->toArray(),
            ], $actor);

            throw AiRunRefused::capReached((string) $decision->reason);
        }

        $selection = $this->selector->select($board, $ticket);
        $repository = $selection['repository'];

        if ($mode->writesCode() && ! $repository instanceof BoardRepository) {
            throw AiRunRefused::noRepository();
        }

        $run = DB::transaction(function () use (
            $ticket,
            $board,
            $mode,
            $trigger,
            $actor,
            $repository,
            $selection,
            $decision
        ): AiRun {
            $run = new AiRun;

            // Every one of these is assigned rather than mass assigned; AiRun
            // has an empty $fillable on purpose.
            $run->uuid = (string) Str::uuid();
            $run->ticket_id = $ticket->getKey();
            $run->board_id = $board->getKey();
            $run->trigger_source = $trigger;
            $run->triggered_by_id = $actor?->getKey();
            $run->mode = $mode;
            $run->status = AiRunStatus::Queued;
            $run->model = $board->aiSettings()->model;
            $run->board_repository_id = $repository?->getKey();
            $run->repository = $repository?->repository_name;
            $run->repository_strategy = $selection['strategy'];
            $run->metadata = [
                'cap' => $decision->toArray(),
                'queued_at' => now()->toIso8601String(),
            ];

            $run->save();

            $this->activity->record($ticket, TicketEventType::AiRunQueued, [
                'run_id' => $run->getKey(),
                'mode' => $mode->value,
                'trigger' => $trigger->value,
                'repository' => $run->repository,
                'repository_strategy' => $selection['strategy'],
            ], $actor);

            return $run;
        });

        ExecuteAiRunJob::dispatch($run->getKey())
            ->onConnection(config('ai.queue.connection') ?: config('queue.default'))
            ->onQueue((string) config('ai.queue.name', 'ai'))
            // The caller may be inside the transaction that created the ticket.
            // Without this a worker could dequeue the job before the row exists.
            ->afterCommit();

        return $run;
    }

    /**
     * Would a manual run be refused right now, and why?
     *
     * Lets the ticket screen disable the button and explain itself without
     * creating anything. Deliberately a separate read rather than a dry-run of
     * handle(): a preflight that took the same path would be a second place the
     * rules live.
     */
    public function refusalFor(Ticket $ticket, AiRunMode $mode, ?User $actor): ?AiRunRefused
    {
        $ticket->loadMissing('board');

        if (! (bool) config('ai.enabled')) {
            return AiRunRefused::disabled();
        }

        if (! BoardAiSettings::providerConfigured()) {
            return AiRunRefused::notConfigured();
        }

        if ($this->runs->hasActiveRun($ticket, $actor)) {
            return AiRunRefused::alreadyRunning();
        }

        $decision = $this->cap->check($ticket->board, AiRunTrigger::Manual, $actor);

        if (! $decision->allowed) {
            return AiRunRefused::capReached((string) $decision->reason);
        }

        if ($mode->writesCode()
            && ! $this->selector->select($ticket->board, $ticket)['repository'] instanceof BoardRepository) {
            return AiRunRefused::noRepository();
        }

        return null;
    }
}
