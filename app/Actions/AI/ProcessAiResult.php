<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Enums\AiRunStatus;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\Ticket;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiUsageRecorder;
use App\Services\AI\CostCalculationService;
use App\Services\BoardBroadcaster;
use App\Services\Slack\SlackNotifier;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;

/**
 * A run succeeded. Write it down.
 *
 * The one place AI output becomes a row, and therefore the one place the
 * audience rule has to hold. It holds three times over:
 *
 *   1. the stream is hard-coded to CommentStream::Internal. Not a parameter, not
 *      derived from a setting, not read from the run — a literal, so there is no
 *      value anybody can pass to publish an assessment to a customer;
 *   2. `author_id` is null. The note is attributed to the workspace rather than
 *      impersonating the person whose button press started it, and a null author
 *      is what makes it obvious in the thread that a machine wrote it;
 *   3. it does not go through App\Actions\Comments\PostComment. That action
 *      notifies participants and resolves @mentions — reasonable for a person
 *      typing, wrong for a machine: mentions in generated text would ping
 *      whoever the model happened to name.
 *
 * Notifications are deliberately omitted. A team that has switched automation on
 * gets a note per customer ticket; turning each one into a notification for every
 * participant would train them to ignore the bell.
 */
class ProcessAiResult
{
    public function __construct(
        private readonly CostCalculationService $costs,
        private readonly TicketActivity $activity,
        private readonly BoardBroadcaster $broadcaster,
        private readonly SlackNotifier $slack,
        private readonly AiUsageRecorder $usage,
        private readonly AiConfigurationResolver $configuration,
    ) {}

    /**
     * @param  string  $body  the Markdown to post as an internal note
     * @param  array<string, mixed>  $metadata  merged into the run's diagnostics
     */
    public function handle(
        AiRun $run,
        Ticket $ticket,
        string $body,
        ?int $inputTokens,
        ?int $outputTokens,
        ?string $model,
        ?int $durationMs,
        ?string $pullRequestUrl = null,
        ?string $branchName = null,
        array $metadata = [],
    ): AiRun {
        $ticket->loadMissing('board');
        // Strict mode forbids implicit lazy loading, and this runs in a worker
        // where the run was rehydrated from an id rather than passed in loaded.
        $run->loadMissing('triggeredBy');

        $comment = DB::transaction(function () use (
            $run,
            $ticket,
            $body,
            $inputTokens,
            $outputTokens,
            $model,
            $durationMs,
            $pullRequestUrl,
            $branchName,
            $metadata
        ): Comment {
            $comment = new Comment(['body_md' => trim($body)]);

            $comment->ticket_id = $ticket->getKey();
            $comment->board_id = $ticket->board_id;
            // The audience rule, as a literal. See the class comment.
            $comment->stream = CommentStream::Internal;
            // No author: the workspace wrote this, not a person.
            $comment->author_id = null;

            $comment->save();

            $run->status = AiRunStatus::Completed;
            $run->result_comment_id = $comment->getKey();
            $run->tokens_input = $inputTokens;
            $run->tokens_output = $outputTokens;
            // The model the provider actually served, falling back to the one
            // that was asked for, because that is what the cost is derived from.
            $run->model = $model ?? $run->model;
            $run->estimated_cost = $this->costs->estimate($run->model, $inputTokens, $outputTokens);
            $run->duration_ms = $durationMs;
            $run->pull_request_url = $pullRequestUrl;
            $run->branch_name = $branchName;
            $run->finished_at = now();
            $run->error_message = null;
            $run->metadata = array_merge((array) $run->metadata, $metadata, [
                'cost_priced' => $this->costs->isPriced($run->model),
            ]);

            $run->save();

            $this->activity->record($ticket, TicketEventType::AiRunCompleted, [
                'run_id' => $run->getKey(),
                'mode' => $run->mode->value,
                'trigger' => $run->trigger_source->value,
                'comment_id' => $comment->getKey(),
                'pull_request_url' => $pullRequestUrl,
            ], $run->triggeredBy);

            return $comment;
        });

        // Wakes the internal channel only: BoardBroadcaster works out the
        // audience from the comment's own stream, and an internal note never
        // reaches the customer channel. Content-free, as every broadcast is.
        $this->broadcaster->commentPosted($comment);

        // Announced to Slack only if the board asked for it — the event
        // defaults to off. The message says a run finished and links to the
        // ticket; the analysis stays in the note written above and does not
        // travel. See App\Notifications\Slack\AiRunFinished.
        $run->refresh();

        /*
         * Mirror the run into the usage ledger.
         *
         * After the refresh, so the ledger row carries the reconciled figures —
         * the model the provider actually served, and the cost derived from it
         * — rather than what was asked for. Written outside the transaction
         * because it is a report of what happened rather than part of it: a
         * ledger write that failed must not roll back the note the team is
         * waiting for.
         */
        $this->usage->recordRun($run, $this->configuration->providerFor($ticket->board));

        $this->slack->aiRunFinished($run, $ticket);

        return $run;
    }
}
