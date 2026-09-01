<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Auth\Access\Response;

/**
 * Ticket authorization.
 *
 * Two layers protect a ticket and they must agree:
 *
 *  - Ticket::visibleTo() filters collections in SQL. That is the primary
 *    defence and the one that protects lists, search and links.
 *  - canRead() below is the same rule expressed for a single already-loaded
 *    model, used when there is no query to constrain.
 *
 * If those two ever disagree the SQL one wins and something is wrong, so
 * canRead() is written to mirror the scope line for line.
 *
 * Write rules, in one place:
 *
 *   read            board member (customers: customer-visible tickets only)
 *   create          any active board member, customers included
 *   update          staff board members; a customer may edit a ticket they
 *                   raised themselves
 *   move / assign / labels / links / visibility / delete    staff only
 *
 * Customers are excluded from moving tickets because column position is the
 * delivery team's workflow, not a customer-facing field.
 */
class TicketPolicy
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * Deny as 404: telling a customer that AQD-42 exists but is internal is
     * itself a leak, so a hidden ticket and a nonexistent one look identical.
     */
    public function view(User $user, Ticket $ticket): Response
    {
        return $this->canRead($user, $ticket)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Anyone who can reach the board may raise a ticket on it, including a
     * customer. What they are allowed to put in it is decided by
     * App\Actions\Tickets\CreateTicket, not here.
     *
     * Denied as 404 rather than 403, for the same reason BoardPolicy::view is:
     * a 403 on /boards/{slug}/tickets/create would confirm that a board with
     * that slug exists.
     */
    public function create(User $user, Board $board): Response
    {
        return $this->access->canView($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Ticket $ticket): Response
    {
        if (! $this->canRead($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        if ($user->isStaff()) {
            return Response::allow();
        }

        // A customer may keep their own request up to date. Which fields they
        // may touch is narrower still, and is enforced by UpdateTicket.
        return $ticket->created_by_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only edit tickets you raised.');
    }

    public function delete(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can delete tickets.');
    }

    /**
     * Drag-and-drop, and any other change of column or position.
     */
    public function move(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can move tickets.');
    }

    public function assign(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can assign tickets.');
    }

    /**
     * Flip a ticket between internal and customer-visible.
     *
     * The single most sensitive write in the product: it is what exposes work
     * to a customer, so it is staff-only and always recorded in the timeline.
     */
    public function changeVisibility(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can change ticket visibility.');
    }

    public function manageLabels(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can change labels.');
    }

    /**
     * Links can point at another board, so only staff may create them — and
     * even then only to tickets they can already see (enforced by LinkTickets).
     */
    public function manageLinks(User $user, Ticket $ticket): Response
    {
        return $this->staffOnly($user, $ticket, 'Only the delivery team can link tickets.');
    }

    /**
     * Checklists follow the write rule of the ticket itself, so a customer can
     * tick off items on a request they raised.
     */
    public function manageSubtasks(User $user, Ticket $ticket): Response
    {
        return $this->update($user, $ticket);
    }

    /**
     * Attaching a screenshot is a normal part of raising a request, so this
     * follows update() rather than being staff-only.
     */
    public function manageAttachments(User $user, Ticket $ticket): Response
    {
        return $this->update($user, $ticket);
    }

    /**
     * The single-model mirror of Ticket::visibleTo().
     *
     * loadMissing rather than a lazy read: strict mode forbids implicit lazy
     * loading, and a policy must work whether or not the caller eager loaded.
     */
    private function canRead(User $user, Ticket $ticket): bool
    {
        $ticket->loadMissing('board');

        if (! $ticket->board instanceof Board) {
            return false;
        }

        if (! $this->access->canView($user, $ticket->board)) {
            return false;
        }

        return $ticket->customer_visible || $this->access->canSeeInternalContent($user);
    }

    private function staffOnly(User $user, Ticket $ticket, string $message): Response
    {
        if (! $this->canRead($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $user->isStaff() ? Response::allow() : Response::deny($message);
    }
}
