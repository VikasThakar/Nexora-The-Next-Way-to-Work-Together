<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\AI\TriggerAutomaticAiRun;
use App\Actions\Notifications\AlertCriticalTicket;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BoardBroadcaster;
use App\Services\NotificationDispatcher;
use App\Services\Slack\SlackNotifier;
use Illuminate\Support\Facades\Auth;

/**
 * Fans a ticket change out to the realtime channels and the notification bell.
 *
 * An observer rather than four calls in four actions, because "every change to
 * a ticket is announced" is a property of the model, not of any one screen. A
 * future action, console command or import gets it without remembering to.
 *
 * The two things it does are deliberately different in kind:
 *
 *   broadcasting  content-free, and audience-aware. BoardBroadcaster decides
 *                 whether customers hear about this change at all.
 *   notifying     writes a row per recipient, each one policy-checked by
 *                 NotificationDispatcher.
 *   AI automation on creation only, and only for a customer's ticket. See
 *                 App\Actions\AI\TriggerAutomaticAiRun.
 *   announcing    queues an outbound message to Slack, or a text message for a
 *                 critical ticket. Both are per-board opt-ins and both are
 *                 queued, so a third-party outage costs the request nothing.
 *
 * Neither may throw: nothing here is important enough to fail a ticket save.
 * Broadcasting is queued and notification writes share the caller's
 * transaction, so a rollback takes them with it.
 *
 * Position rewrites during a drag are done with the query builder rather than
 * on models (see MoveTicket::resequence), so shuffling ten cards produces one
 * announcement from the ticket that actually moved, not eleven.
 */
class TicketObserver
{
    public function __construct(
        private readonly BoardBroadcaster $broadcaster,
        private readonly NotificationDispatcher $notifications,
        private readonly TriggerAutomaticAiRun $automaticAiRun,
        private readonly SlackNotifier $slack,
        private readonly AlertCriticalTicket $criticalAlert,
    ) {}

    public function created(Ticket $ticket): void
    {
        $this->broadcaster->ticketChanged($ticket);

        $author = $this->userById($ticket->created_by_id);

        $this->notifyAssignee($ticket, $author);

        // "A customer ticket may start an AI run" belongs here for the same
        // reason broadcasting does: it is a property of a ticket coming into
        // existence, not of the form that happened to create it, so a console
        // command or a future API gets it without remembering to.
        //
        // TriggerAutomaticAiRun decides whether anything happens at all and
        // never throws — see its class comment. All it does synchronously is
        // one insert and one queue dispatch, so ticket creation stays fast.
        $this->automaticAiRun->handle($ticket);

        // A ticket raised from outside the delivery team is the one that needs
        // somebody to look at it. A team member filing their own work does not
        // need announcing to the team.
        if ($author instanceof User && $author->isCustomer()) {
            $this->slack->customerTicketRaised($ticket, $author);
        }

        // Critical tickets page the on-call rota, whoever raised them. Also
        // never throws; see AlertCriticalTicket.
        $this->criticalAlert->handle($ticket);
    }

    public function updated(Ticket $ticket): void
    {
        // A ticket that has just been hidden still changes what customers see:
        // their card has to disappear. Pass the previous value so the
        // broadcaster can decide that for itself.
        $wasCustomerVisible = $ticket->wasChanged('customer_visible')
            ? (bool) $ticket->getOriginal('customer_visible')
            : null;

        $this->broadcaster->ticketChanged($ticket, $wasCustomerVisible);

        if ($ticket->wasChanged('assignee_id')) {
            $this->notifyAssignee($ticket, $this->currentUser());
        }

        // "Moved to done" is detected here rather than inside MoveTicket, so a
        // ticket dragged on the board, moved from the status dropdown, or moved
        // by the column-delete flow all announce identically. The board's own
        // `is_done` flag decides, so a board whose final column is called
        // "Shipped" still works.
        if ($ticket->wasChanged('board_column_id')) {
            $this->announceIfDone($ticket);
        }
    }

    /**
     * Tell Slack when a ticket lands in a column the board marks as done.
     */
    private function announceIfDone(Ticket $ticket): void
    {
        $column = BoardColumn::query()->find($ticket->board_column_id);

        if (! $column instanceof BoardColumn || ! $column->is_done) {
            return;
        }

        $this->slack->ticketMovedToDone($ticket, $column, $this->currentUser());
    }

    public function deleted(Ticket $ticket): void
    {
        $this->broadcaster->ticketChanged($ticket, (bool) $ticket->customer_visible);
    }

    private function notifyAssignee(Ticket $ticket, ?User $actor): void
    {
        if ($ticket->assignee_id === null) {
            return;
        }

        $this->notifications->ticketAssigned(
            $ticket,
            $this->userById($ticket->assignee_id),
            $actor,
        );
    }

    private function userById(int|string|null $id): ?User
    {
        if ($id === null) {
            return null;
        }

        // Not $ticket->assignee: strict mode forbids implicit lazy loading, and
        // an observer must work whether or not the caller eager loaded.
        return User::query()->find($id);
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
