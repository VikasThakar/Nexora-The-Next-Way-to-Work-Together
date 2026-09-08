<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketPriority;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketPriorityChanged;
use App\Notifications\TicketStatusChanged;
use App\Support\NotificationItem;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Turns stored notifications into something safe to show.
 *
 * Stored notifications hold identifiers only, so this class has to re-read
 * every subject before it can render a single line. That is the point: the
 * re-read goes through Ticket::visibleTo() and Comment::visibleTo(), the same
 * scopes that protect the board and the ticket page, and anything that does not
 * come back is dropped.
 *
 * The consequence worth stating plainly: if a ticket a customer was notified
 * about is later made internal, their notification silently disappears. It does
 * not become a broken link, and it certainly does not keep displaying the
 * ticket's title. The same holds for a comment that was deleted, a board the
 * user was removed from, and a ticket that no longer exists.
 *
 * The unread badge is counted from that same filtered list rather than with a
 * cheap `count()` on the table. A badge saying 3 above a list showing 1 would
 * itself be a leak — it would tell the customer that two things happened which
 * they are not allowed to see.
 */
class NotificationReader
{
    /**
     * How far back the bell looks. Beyond this the badge shows "50+".
     */
    public const WINDOW = 50;

    /**
     * How many rows the bell shows at once.
     *
     * Fewer than WINDOW, so a busy week does not put fifty rows in a dropdown.
     * The gap between the two is what visibleCount() exists to report.
     */
    public const PAGE = 15;

    /**
     * The most recent notifications this user may still be shown.
     *
     * @return Collection<int, NotificationItem>
     */
    public function items(User $user, int $limit = self::PAGE): Collection
    {
        $notifications = $user->notifications()
            ->latest()
            ->limit(self::WINDOW)
            ->get();

        return $this->resolve($notifications, $user)->take($limit)->values();
    }

    /**
     * How many readable notifications there are within the window.
     *
     * The bell shows PAGE of them, and until this existed there was no way to
     * tell that the rest were there: a badge counting thirty above a list of
     * fifteen looked like a miscount rather than a truncation. The count is of
     * the resolved list, so it is a count of things this viewer may actually
     * see — never a count of rows in the table.
     */
    public function visibleCount(User $user): int
    {
        $notifications = $user->notifications()
            ->latest()
            ->limit(self::WINDOW)
            ->get();

        return $this->resolve($notifications, $user)->count();
    }

    /**
     * How many unread notifications this user may still be shown.
     */
    public function unreadCount(User $user): int
    {
        $notifications = $user->unreadNotifications()
            ->latest()
            ->limit(self::WINDOW)
            ->get();

        return $this->resolve($notifications, $user)->count();
    }

    /**
     * Mark one notification read.
     *
     * Scoped to the user's own notifications, so an id from somebody else's
     * bell does nothing.
     */
    public function markRead(User $user, string $id): void
    {
        $user->notifications()
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Mark everything read, including notifications the reader would hide.
     *
     * Hidden ones are marked too, on purpose: they are invisible but still
     * unread, and leaving them behind would make "mark all as read" appear not
     * to work whenever something had been retracted.
     */
    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * Re-read every subject through the visibility scopes and drop whatever
     * does not come back.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, NotificationItem>
     */
    private function resolve(Collection $notifications, User $user): Collection
    {
        if ($notifications->isEmpty()) {
            return collect();
        }

        $payloads = $notifications->map(fn (DatabaseNotification $row): array => (array) $row->data);

        $ticketIds = $payloads->pluck('ticket_id')->filter()->unique()->all();
        $commentIds = $payloads->pluck('comment_id')->filter()->unique()->all();
        $actorIds = $payloads->pluck('actor_id')->filter()->unique()->all();
        $columnIds = $payloads->pluck('column_id')->filter()->unique()->all();

        // The security step. Both scopes apply board membership and the
        // customer rule; anything not returned is simply not shown.
        $tickets = $ticketIds === [] ? collect() : Ticket::query()
            ->visibleTo($user)
            ->whereIn('tickets.id', $ticketIds)
            ->with('board')
            ->get()
            ->keyBy('id');

        $comments = $commentIds === [] ? collect() : Comment::query()
            ->visibleTo($user)
            ->whereIn('comments.id', $commentIds)
            ->get()
            ->keyBy('id');

        $actors = $actorIds === [] ? collect() : User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        /*
         * Columns for the "moved to" notifications.
         *
         * Read live rather than stored as a name, so a column renamed next
         * month reads correctly in a notification written today — while *which*
         * column the ticket moved into stays frozen, which is what a reader of
         * an old notification wants to know.
         *
         * Not scoped: a column carries nothing but a name and a board id, and
         * the board id is checked against the ticket's below. A tampered
         * payload therefore cannot name a column from a board the recipient
         * cannot see.
         */
        $columns = $columnIds === [] ? collect() : BoardColumn::query()
            ->whereIn('id', $columnIds)
            ->get(['id', 'board_id', 'name'])
            ->keyBy('id');

        return $notifications
            ->map(function (DatabaseNotification $row) use ($tickets, $comments, $actors, $columns): ?NotificationItem {
                $data = (array) $row->data;

                $ticket = $tickets->get($data['ticket_id'] ?? null);

                if (! $ticket instanceof Ticket) {
                    return null;
                }

                $column = $columns->get($data['column_id'] ?? null);

                if ($column instanceof BoardColumn && (int) $column->board_id !== (int) $ticket->board_id) {
                    $column = null;
                }

                $comment = null;

                if (! empty($data['comment_id'])) {
                    $comment = $comments->get($data['comment_id']);

                    // The notification is about a comment; if the comment is
                    // gone or no longer readable, so is the notification.
                    if (! $comment instanceof Comment) {
                        return null;
                    }
                }

                $actorName = $actors->get($data['actor_id'] ?? null)?->name;
                $type = (string) ($data['type'] ?? '');

                return new NotificationItem(
                    id: (string) $row->getKey(),
                    type: $type,
                    message: $this->message($type, $ticket, $comment, $actorName, $column, $data),
                    ticketKey: $ticket->key(),
                    url: route('tickets.show', ['board' => $ticket->board, 'number' => $ticket->number]),
                    actorName: $actorName,
                    createdAt: $row->created_at,
                    unread: $row->read_at === null,
                    internal: $comment instanceof Comment ? $comment->isInternal() : $ticket->isInternal(),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * The line shown in the bell, built from live records.
     *
     * @param  array<string, mixed>  $data  the stored payload, for the closed
     *                                      enum values that are frozen in it
     */
    private function message(
        string $type,
        Ticket $ticket,
        ?Comment $comment,
        ?string $actorName,
        ?BoardColumn $column = null,
        array $data = [],
    ): string {
        $who = $actorName ?? 'Somebody';

        return match ($type) {
            TicketAssigned::TYPE => $who.' assigned '.$ticket->key().' to you',
            MentionedInComment::TYPE => $who.' mentioned you on '.$ticket->key(),

            TicketStatusChanged::TYPE => $column instanceof BoardColumn
                ? $who.' moved '.$ticket->key().' to '.$column->name
                // The column was deleted after the notification was written.
                // The move still happened, so it is still reported.
                : $who.' moved '.$ticket->key(),

            TicketPriorityChanged::TYPE => $who.' set '.$ticket->key().' to '
                .(TicketPriority::tryFrom((string) ($data['priority'] ?? ''))?->label() ?? 'a new priority'),

            default => $comment !== null && $comment->isInternal()
                ? $who.' added an internal note on '.$ticket->key()
                : $who.' commented on '.$ticket->key(),
        };
    }
}
