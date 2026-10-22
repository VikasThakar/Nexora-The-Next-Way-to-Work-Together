<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BoardUpdated;
use App\Events\NotificationReceived;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Decides which realtime audiences hear about a change.
 *
 * This is the whole of the "do not broadcast internal data to customers" rule,
 * in one place. Every caller says what changed; this class works out whether
 * customers are affected and signals only the channels that should wake up.
 *
 * The rule for tickets is subtler than "is it customer-visible?", because a
 * ticket that has just been hidden also changes what customers see — their card
 * has to disappear. So the customer audience is signalled when the ticket is
 * customer-visible now OR was before the change. Both are content-free signals;
 * what the customer's browser then receives comes from the ordinary scoped
 * query.
 *
 * Events are dispatched after the surrounding transaction commits. Broadcasting
 * a change that then rolls back would tell every open board to re-read
 * something that never happened.
 */
class BoardBroadcaster
{
    /**
     * Board/audience signals queued for the open transaction (see signal()).
     * The class is bound as scoped, so this never outlives the request.
     *
     * @var array<string, true>
     */
    private array $queued = [];

    /**
     * @param  bool|null  $wasCustomerVisible  the value before this change;
     *                                         null when it did not change
     */
    public function ticketChanged(Ticket $ticket, ?bool $wasCustomerVisible = null): void
    {
        $affectsCustomers = (bool) $ticket->customer_visible
            || ($wasCustomerVisible ?? false);

        $this->board((int) $ticket->board_id, $affectsCustomers);
    }

    /**
     * A new, edited or removed comment.
     *
     * An internal note never wakes the customer channel. A comment in the
     * customer stream wakes both, but only on a ticket the customer can already
     * see — a customer-stream comment on an internal ticket is unreachable for
     * them anyway, and signalling it would be a timing side channel, so the
     * ticket's own visibility is required too.
     */
    public function commentPosted(Comment $comment): void
    {
        $comment->loadMissing('ticket');

        $affectsCustomers = $comment->stream->isCustomerFacing()
            && $comment->ticket !== null
            && (bool) $comment->ticket->customer_visible;

        $this->board((int) $comment->board_id, $affectsCustomers);
    }

    /**
     * A documentation page changed.
     *
     * Docs are not live-edited collaboratively, so this exists mainly so an
     * open sidebar picks up a new page. Same audience rule as everything else.
     */
    public function documentationChanged(int $boardId, bool $affectsCustomers): void
    {
        $this->board($boardId, $affectsCustomers);
    }

    /**
     * The board's structure changed: a tab or a column was added, renamed,
     * reordered or removed.
     *
     * Both audiences, always: tab and column names are not secret (customers
     * see every one of them), so an open customer board has to redraw too.
     * The signal carries nothing, as ever.
     */
    public function structureChanged(int $boardId): void
    {
        $this->board($boardId, true);
    }

    /**
     * Nudge one person's notification bell.
     */
    public function userNotified(User $user): void
    {
        $this->afterCommit(fn () => NotificationReceived::dispatch((int) $user->getKey()));
    }

    private function board(int $boardId, bool $affectsCustomers): void
    {
        $this->signal($boardId, BoardUpdated::AUDIENCE_INTERNAL);

        if ($affectsCustomers) {
            $this->signal($boardId, BoardUpdated::AUDIENCE_CUSTOMER);
        }
    }

    /**
     * One "re-read the board" per board and audience per transaction.
     *
     * The signal carries no content, so ten of them mean exactly what one
     * does — but each makes every open board fetch itself again. Deleting a
     * tab saves every ticket in it, and each save would otherwise wake every
     * open board once more. Inside a transaction a board/audience pair is
     * queued once; the mark is cleared when the transaction commits (after the
     * signal is sent) or rolls back, so the next transaction signals afresh.
     * Outside a transaction there is nothing to coalesce and it goes at once.
     */
    private function signal(int $boardId, string $audience): void
    {
        if (DB::transactionLevel() === 0) {
            BoardUpdated::dispatch($boardId, $audience);

            return;
        }

        $key = $boardId.':'.$audience;

        if (isset($this->queued[$key])) {
            return;
        }

        $this->queued[$key] = true;

        DB::afterCommit(function () use ($boardId, $audience, $key): void {
            unset($this->queued[$key]);

            BoardUpdated::dispatch($boardId, $audience);
        });

        DB::afterRollBack(function () use ($key): void {
            unset($this->queued[$key]);
        });
    }

    /**
     * Run now when there is no open transaction, or on commit when there is.
     */
    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
