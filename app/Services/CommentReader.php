<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommentStream;
use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only supported way to read comments.
 *
 * Modelled on TicketFinder, for the same reason: there is no method here that
 * returns an unscoped query, so a caller cannot skip the customer rule by
 * forgetting a scope.
 *
 * Two restrictions are applied to every read, and both are needed:
 *
 *   Comment::visibleTo()  board membership, and for a customer only
 *                         customer-facing streams. This is what protects an
 *                         internal note on a ticket the customer can otherwise
 *                         read.
 *   the ticket filter     comments are only ever fetched for one ticket, and
 *                         that ticket is resolved through TicketFinder, so a
 *                         comment on an internal ticket is unreachable because
 *                         the ticket is.
 *
 * Counts go through the same scope. A count is not a harmless number: telling a
 * customer that a ticket has "3 notes" they cannot open reveals that internal
 * discussion is happening, and how much.
 */
class CommentReader
{
    /**
     * @return Builder<Comment>
     */
    public function query(Ticket $ticket, ?Authenticatable $viewer): Builder
    {
        return Comment::query()
            ->visibleTo($viewer)
            ->where('comments.ticket_id', $ticket->getKey());
    }

    /**
     * One stream of one ticket, oldest first.
     *
     * Asking for the internal stream as a customer is safe and returns nothing:
     * the stream filter is composed on top of the visibility scope, which has
     * already excluded every internal row.
     *
     * @return Collection<int, Comment>
     */
    public function forStream(Ticket $ticket, ?Authenticatable $viewer, CommentStream $stream): Collection
    {
        return $this->query($ticket, $viewer)
            ->inStream($stream)
            ->with(['author', 'attachments'])
            ->ordered()
            ->get();
    }

    /**
     * How many comments this viewer may see, per stream.
     *
     * Returns a map keyed by stream value, containing only streams the viewer
     * is allowed to know about — a customer never receives an `internal` key at
     * all, not even one holding zero.
     *
     * @return array<string, int>
     */
    public function countsByStream(Ticket $ticket, ?Authenticatable $viewer): array
    {
        // Kept on the Eloquent builder rather than dropping to the query
        // builder: toBase() is what applies the global scopes, so a
        // soft-deleted comment is excluded from the count exactly as it is
        // excluded from the thread.
        $counts = $this->query($ticket, $viewer)
            ->select('comments.stream')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('comments.stream')
            ->pluck('aggregate', 'stream')
            ->all();

        $result = [];

        foreach ($counts as $stream => $count) {
            $result[(string) $stream] = (int) $count;
        }

        return $result;
    }

    /**
     * Resolve one comment on a ticket, or fail as 404.
     *
     * Scoped to the ticket as well as to the viewer, so a swapped id cannot
     * reach a comment on a different ticket.
     */
    public function findOrFail(Ticket $ticket, int $id, ?Authenticatable $viewer): Comment
    {
        $comment = $this->query($ticket, $viewer)->whereKey($id)->first();

        if (! $comment instanceof Comment) {
            throw new NotFoundHttpException;
        }

        return $comment;
    }

    /**
     * The people already taking part in a stream, plus the ticket's assignee
     * and the person who raised it.
     *
     * Used to decide who to notify. Returns user ids only; whether each of them
     * is actually allowed to receive the notification is decided by
     * NotificationDispatcher, which re-checks the policy per recipient.
     *
     * @return array<int, int>
     */
    public function participantIds(Ticket $ticket, CommentStream $stream): array
    {
        $ids = Comment::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('stream', $stream->value)
            ->whereNotNull('author_id')
            ->distinct()
            ->pluck('author_id')
            ->all();

        $ids[] = $ticket->assignee_id;
        $ids[] = $ticket->created_by_id;

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): ?int => $id === null ? null : (int) $id,
            $ids
        ))));
    }
}
