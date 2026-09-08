<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketPriority;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\CommentPosted;
use App\Notifications\MentionedInComment;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketPriorityChanged;
use App\Notifications\TicketStatusChanged;
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
     * Tell the people waiting on a ticket that it moved.
     *
     * The assignee and the person who raised it, which is the set that has
     * actually asked. Not every past commenter: a status change is a fact about
     * somebody else's work for anybody who merely said something on the thread
     * once, and a bell that fires for those is a bell people turn off.
     *
     * A customer reporter is included, and is told nothing new by it — the
     * column of their own ticket is on the page they can already open. Whether
     * they may be told at all is decided by the Gate check below, as always.
     */
    public function ticketStatusChanged(Ticket $ticket, BoardColumn $column, ?User $actor = null): void
    {
        $this->send(
            $this->interested($ticket, $actor),
            new TicketStatusChanged($ticket, $column, $actor),
            fn (User $recipient): bool => Gate::forUser($recipient)->allows('view', $ticket),
        );
    }

    /**
     * Tell whoever is doing the work that its priority changed.
     *
     * The assignee only. Priority is a decision the delivery team makes about
     * its own order of work; telling the reporter — often a customer — invites
     * a conversation about the decision rather than about the ticket, and
     * nobody asked for that conversation.
     */
    public function ticketPriorityChanged(Ticket $ticket, TicketPriority $priority, ?User $actor = null): void
    {
        $assignee = $this->userById($ticket->assignee_id);

        if (! $assignee instanceof User || $assignee->getKey() === $actor?->getKey()) {
            return;
        }

        $this->send(
            collect([$assignee]),
            new TicketPriorityChanged($ticket, $priority, $actor),
            fn (User $recipient): bool => Gate::forUser($recipient)->allows('view', $ticket),
        );
    }

    /**
     * The assignee and the reporter, minus whoever did it.
     *
     * @return Collection<int, User>
     */
    private function interested(Ticket $ticket, ?User $actor): Collection
    {
        $ids = array_values(array_unique(array_filter([
            $ticket->assignee_id === null ? null : (int) $ticket->assignee_id,
            $ticket->created_by_id === null ? null : (int) $ticket->created_by_id,
        ])));

        $ids = array_values(array_diff($ids, array_filter([$actor?->getKey()])));

        if ($ids === []) {
            return collect();
        }

        // Not $ticket->assignee: strict mode forbids implicit lazy loading, and
        // this is reached from an observer that cannot know what was loaded.
        return User::query()->whereIn('id', $ids)->active()->get();
    }

    private function userById(int|string|null $id): ?User
    {
        return $id === null ? null : User::query()->find($id);
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
