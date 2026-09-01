<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\CommentPosted;
use App\Notifications\MentionedInComment;
use App\Notifications\TicketAssigned;
use App\Notifications\WorkspaceNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Decides who is told about what.
 *
 * The security rule is one line, in send():
 *
 *   A notification is only written for a recipient who passes the same policy
 *   check that guards the thing being notified about.
 *
 * That is deliberately a policy call and not a fresh set of conditions. The
 * question "may this person see this comment?" already has an answer — in
 * CommentPolicy, which itself delegates the ticket half to TicketPolicy and the
 * board half to BoardAccess. Re-deriving it here would create a second,
 * unreviewed definition of the customer boundary, and the notification bell is
 * exactly the kind of peripheral surface where such a copy goes stale.
 *
 * A customer therefore cannot be notified about an internal note even if
 * something upstream tried to: the Gate check fails and no row is written.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly CommentReader $comments,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * Tell somebody a ticket is now theirs.
     */
    public function ticketAssigned(Ticket $ticket, ?User $assignee, ?User $actor = null): void
    {
        if (! $assignee instanceof User) {
            return;
        }

        // Assigning something to yourself is not news.
        if ($actor instanceof User && $actor->getKey() === $assignee->getKey()) {
            return;
        }

        $this->send(
            collect([$assignee]),
            new TicketAssigned($ticket, $actor),
            fn (User $recipient): bool => Gate::forUser($recipient)->allows('view', $ticket),
        );
    }

    /**
     * Tell the people involved that a comment was posted.
     *
     * Mentions win over the generic notification: somebody named in a comment
     * gets one notification saying so, not two saying different things.
     *
     * @param  Collection<int, User>  $mentioned  already resolved and already
     *                                            restricted to people allowed
     *                                            to read this stream
     */
    public function commentPosted(Comment $comment, Ticket $ticket, Collection $mentioned, ?User $author = null): void
    {
        $authorId = $author?->getKey();

        $canSee = fn (User $recipient): bool => Gate::forUser($recipient)->allows('view', $comment);

        $mentionedIds = $mentioned
            ->reject(fn (User $user): bool => $user->getKey() === $authorId)
            ->values();

        $this->send($mentionedIds, new MentionedInComment($comment, $author), $canSee);

        $alreadyNotified = $mentionedIds->pluck('id')->all();
        $alreadyNotified[] = $authorId;

        $participants = User::query()
            ->whereIn('id', $this->comments->participantIds($ticket, $comment->stream))
            ->whereNotIn('id', array_values(array_filter($alreadyNotified)))
            ->active()
            ->get();

        $this->send($participants, new CommentPosted($comment, $author), $canSee);
    }

    /**
     * Write a notification for every recipient the guard lets through.
     *
     * @param  Collection<int, User>  $recipients
     * @param  callable(User): bool  $mayReceive
     */
    private function send(Collection $recipients, WorkspaceNotification $notification, callable $mayReceive): void
    {
        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User || ! $recipient->isActive()) {
                continue;
            }

            if (! $mayReceive($recipient)) {
                continue;
            }

            $recipient->notify($notification);

            $this->broadcaster->userNotified($recipient);
        }
    }
}
