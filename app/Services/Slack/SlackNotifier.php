<?php

declare(strict_types=1);

namespace App\Services\Slack;

use App\Enums\AiRunStatus;
use App\Enums\NotificationEvent;
use App\Enums\TicketPriority;
use App\Models\AiRun;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Slack\AiRunFinished;
use App\Notifications\Slack\BoardSlackNotification;
use App\Notifications\Slack\CustomerCommentPosted;
use App\Notifications\Slack\CustomerTicketRaised;
use App\Notifications\Slack\TicketMovedToDone;
use App\Support\BoardSlackSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Decides which board hears about what, in Slack.
 *
 * Called from the ticket and comment observers and from the two AI result
 * actions. Every method obeys the same contract, and it is the important one:
 *
 *   Nothing here may ever throw.
 *
 * These are announcements about work that has already happened. A
 * misconfigured webhook URL, a board whose settings row is malformed, or a
 * queue that is momentarily unreachable must not roll back a ticket somebody
 * just created or a comment somebody just posted. Every entry point is wrapped,
 * and a failure becomes a log line.
 *
 * What each method actually does is one settings read and one queue dispatch,
 * so the cost to the request that triggered it is negligible — the HTTP call to
 * Slack happens in a worker.
 */
class SlackNotifier
{
    /**
     * A customer filed a ticket.
     *
     * Staff tickets are not announced. A team creating its own work does not
     * need to be told it did, and the channel exists to catch things that
     * arrived from outside.
     */
    public function customerTicketRaised(Ticket $ticket, ?User $author): void
    {
        $this->dispatch($ticket->board_id, NotificationEvent::CustomerTicketRaised, function (Board $board) use ($ticket, $author): BoardSlackNotification {
            $ticket->setRelation('board', $board);

            return new CustomerTicketRaised(
                ticketKey: $ticket->key(),
                title: $ticket->title,
                url: $this->ticketUrl($board, $ticket),
                boardName: $board->name,
                author: $author?->name ?? 'A customer',
                priority: $ticket->priority->label(),
                isCritical: $ticket->priority === TicketPriority::Critical,
            );
        });
    }

    /**
     * A customer replied in the customer conversation.
     *
     * The stream check lives at the call site rather than here, so that this
     * method is never the thing standing between an internal note and a Slack
     * channel. See App\Observers\CommentObserver.
     */
    public function customerCommentPosted(Comment $comment, Ticket $ticket, ?User $author): void
    {
        $this->dispatch($ticket->board_id, NotificationEvent::CustomerCommentPosted, function (Board $board) use ($ticket, $author): BoardSlackNotification {
            $ticket->setRelation('board', $board);

            return new CustomerCommentPosted(
                ticketKey: $ticket->key(),
                ticketTitle: $ticket->title,
                url: $this->ticketUrl($board, $ticket),
                boardName: $board->name,
                author: $author?->name ?? 'A customer',
            );
        });
    }

    /**
     * An AI run reached a terminal state.
     *
     * Note how little is passed to the notification: mode, success, and whether
     * a pull request exists. Not the note, not the error, not the URL. See
     * App\Notifications\Slack\AiRunFinished for why.
     */
    public function aiRunFinished(AiRun $run, Ticket $ticket): void
    {
        $this->dispatch($ticket->board_id, NotificationEvent::AiRunFinished, function (Board $board) use ($run, $ticket): BoardSlackNotification {
            $ticket->setRelation('board', $board);

            return new AiRunFinished(
                ticketKey: $ticket->key(),
                ticketTitle: $ticket->title,
                url: $this->ticketUrl($board, $ticket),
                boardName: $board->name,
                mode: $run->mode->value,
                succeeded: $run->status === AiRunStatus::Completed,
                openedPullRequest: filled($run->pull_request_url),
            );
        });
    }

    /**
     * A ticket reached a column marked as done.
     */
    public function ticketMovedToDone(Ticket $ticket, BoardColumn $column, ?User $actor): void
    {
        $this->dispatch($ticket->board_id, NotificationEvent::TicketMovedToDone, function (Board $board) use ($ticket, $column, $actor): BoardSlackNotification {
            $ticket->setRelation('board', $board);

            return new TicketMovedToDone(
                ticketKey: $ticket->key(),
                title: $ticket->title,
                url: $this->ticketUrl($board, $ticket),
                boardName: $board->name,
                columnName: $column->name,
                actor: $actor?->name,
            );
        });
    }

    // -----------------------------------------------------------------

    /**
     * Resolve the board, check it wants this event, and queue the message.
     *
     * The notification is built inside a closure rather than passed in, so a
     * board that is muted costs one settings read and never constructs the
     * message or touches the ticket's relations at all.
     *
     * @param  callable(Board): BoardSlackNotification  $build
     */
    private function dispatch(int $boardId, NotificationEvent $event, callable $build): void
    {
        try {
            $board = Board::query()->find($boardId);

            if (! $board instanceof Board) {
                return;
            }

            $settings = BoardSlackSettings::forBoard($board);

            if (! $settings->wants($event)) {
                return;
            }

            Notification::route('slack_webhook', $settings->webhookUrl)
                ->notify($build($board));
        } catch (Throwable $exception) {
            // A Slack announcement is never worth failing the workflow that
            // triggered it. The message is ours, not the exception's, so a
            // stack trace quoting a webhook URL cannot reach the log.
            Log::warning('A Slack notification could not be queued.', [
                'board_id' => $boardId,
                'event' => $event->value,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function ticketUrl(Board $board, Ticket $ticket): string
    {
        return route('tickets.show', ['board' => $board, 'number' => $ticket->number]);
    }
}
