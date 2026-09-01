<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CommentStream;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Slack\SlackNotifier;

/**
 * Announces customer replies to Slack.
 *
 * Deliberately narrow. The in-app notification fan-out already lives in
 * App\Actions\Comments\PostComment, which knows about mentions, participants
 * and the policy check each recipient has to pass — none of which is repeated
 * here. This observer exists for the one thing that is a property of a comment
 * existing rather than of the form that posted it: telling the delivery team's
 * channel that a customer has replied.
 *
 * The stream check is the important line, and it is deliberately here at the
 * top rather than buried in the notifier:
 *
 *   An internal note is never announced. It is the delivery team talking
 *   privately about a customer's ticket, and a Slack channel is not obviously
 *   more private than the ticket it came from — a channel's membership is
 *   managed in Slack, by different people, with no relationship to this
 *   application's board membership. Posting an internal note there would move
 *   private content into a room whose audience this application cannot see.
 *
 * Nothing here may throw: SlackNotifier catches everything and logs, so a
 * misconfigured webhook cannot fail the comment somebody just wrote.
 */
class CommentObserver
{
    public function __construct(private readonly SlackNotifier $slack) {}

    public function created(Comment $comment): void
    {
        // Only the customer conversation. See the class comment.
        if ($comment->stream !== CommentStream::Customer) {
            return;
        }

        $author = $comment->author_id === null
            ? null
            : User::query()->find($comment->author_id);

        // A reply from the delivery team in the customer thread is the team
        // answering, which the team does not need announcing to itself.
        if (! $author instanceof User || ! $author->isCustomer()) {
            return;
        }

        // Not $comment->ticket: strict mode forbids implicit lazy loading, and
        // an observer must work whether or not the caller eager loaded.
        $ticket = Ticket::query()->find($comment->ticket_id);

        if (! $ticket instanceof Ticket) {
            return;
        }

        $this->slack->customerCommentPosted($comment, $ticket, $author);
    }
}
