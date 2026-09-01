<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Enums\AiRunStatus;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\Ticket;
use App\Services\BoardBroadcaster;
use App\Services\Slack\SlackNotifier;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A run failed. Say so, internally, once.
 *
 * A failure that leaves nothing behind is worse than no automation: somebody
 * configured this expecting a note, and silence is indistinguishable from
 * "the model had nothing to add". So every terminal failure writes an internal
 * note naming the step that stopped and what to do about it.
 *
 * Two properties matter and both are enforced here:
 *
 *   internal only    the note is written to CommentStream::Internal as a
 *                    literal, exactly as ProcessAiResult does. An AI *error*
 *                    is if anything more sensitive than an AI success: it names
 *                    infrastructure, missing credentials and repository
 *                    internals. A customer must never see it.
 *   once per run     the job retries, and three attempts must not produce three
 *                    notes. The note is written only on the final failure — the
 *                    job's failed() hook — and the guard below makes the write
 *                    idempotent even if that is somehow reached twice.
 *
 * Never throws. A failure handler that fails would lose the diagnosis entirely,
 * so the last resort is a log line.
 */
class HandleAiRunFailure
{
    public function __construct(
        private readonly TicketActivity $activity,
        private readonly BoardBroadcaster $broadcaster,
        private readonly SlackNotifier $slack,
    ) {}

    /**
     * @param  bool  $willRetry  true while attempts remain: record the reason on
     *                           the run but write no note yet
     */
    public function handle(AiRun $run, string $reason, bool $willRetry = false): void
    {
        try {
            $this->record($run, $reason, $willRetry);
        } catch (Throwable $exception) {
            Log::error('Could not record an AI run failure.', [
                'ai_run_id' => $run->getKey(),
                'reason' => $reason,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function record(AiRun $run, string $reason, bool $willRetry): void
    {
        $reason = $this->trim($reason);

        if ($willRetry) {
            // Still in flight. Keep the last reason visible on the run so the
            // status panel can show what went wrong on the previous attempt,
            // but leave the status alone and write no note.
            $run->error_message = $reason;
            $run->metadata = array_merge((array) $run->metadata, [
                'last_attempt_error' => $reason,
                'last_attempt_at' => now()->toIso8601String(),
            ]);
            $run->save();

            return;
        }

        // Already dealt with: a retry that lost a race, or a duplicate call.
        if ($run->status === AiRunStatus::Failed && $run->result_comment_id !== null) {
            return;
        }

        $ticket = Ticket::query()->find($run->ticket_id);

        if (! $ticket instanceof Ticket) {
            // The ticket was deleted while the run was in flight. Nothing to
            // write a note on; the run still records why it stopped.
            $run->status = AiRunStatus::Failed;
            $run->error_message = $reason;
            $run->finished_at = now();
            $run->save();

            return;
        }

        $ticket->loadMissing('board');
        $run->loadMissing('triggeredBy');

        $comment = DB::transaction(function () use ($run, $ticket, $reason): Comment {
            $comment = new Comment(['body_md' => $this->note($run, $reason)]);

            $comment->ticket_id = $ticket->getKey();
            $comment->board_id = $ticket->board_id;
            // The audience rule, as a literal.
            $comment->stream = CommentStream::Internal;
            $comment->author_id = null;

            $comment->save();

            $run->status = AiRunStatus::Failed;
            $run->error_message = $reason;
            $run->result_comment_id = $comment->getKey();
            $run->finished_at = now();
            $run->save();

            $this->activity->record($ticket, TicketEventType::AiRunFailed, [
                'run_id' => $run->getKey(),
                'mode' => $run->mode->value,
                'trigger' => $run->trigger_source->value,
                'reason' => $reason,
            ], $run->triggeredBy);

            return $comment;
        });

        $this->broadcaster->commentPosted($comment);

        // The reason deliberately does not travel: an error message is the most
        // likely place for a path, a hostname or a credential fragment to
        // appear, and a Slack channel's audience is not this application's to
        // control. The message says a run failed and links to the note that has
        // the detail. See App\Notifications\Slack\AiRunFinished.
        $this->slack->aiRunFinished($run->refresh(), $ticket);
    }

    /**
     * The note. Written to be actionable rather than apologetic.
     */
    private function note(AiRun $run, string $reason): string
    {
        $lines = [
            '**Automated '.$run->mode->value.' run failed.**',
            '',
            $reason,
            '',
            '---',
            '',
            '*'.ucfirst($run->trigger_source->value).' run · '
                .($run->model ?? 'model not recorded')
                .($run->repository !== null ? ' · '.$run->repository : '')
                .'. Nothing was changed'
                .($run->mode->writesCode() ? ', no branch was pushed and no pull request was opened' : '')
                .'.*',
        ];

        return implode("\n", $lines);
    }

    /**
     * Keep a failure message readable, and never longer than the column.
     */
    private function trim(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            return 'The run failed without reporting a reason.';
        }

        return mb_substr($reason, 0, 4000);
    }
}
