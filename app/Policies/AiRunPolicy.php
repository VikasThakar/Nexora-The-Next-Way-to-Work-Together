<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AiRunMode;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Auth\Access\Response;

/**
 * AI run authorization.
 *
 * One rule underneath all of it: a customer has no relationship with AI runs at
 * all. Not "cannot start one" — cannot see that they exist, cannot see a status,
 * cannot see a cost, cannot see a failure. Every ability below therefore denies
 * a customer as 404 rather than 403, because a 403 on "AI runs for AQD-42" would
 * confirm that the feature is being used on their ticket.
 *
 * That is enforced three times over, deliberately:
 *
 *   in SQL      AiRun::visibleTo() refuses customers outright, so a list, a
 *               count and a lookup all return nothing;
 *   here        for a single already-loaded run, and for the "may I start one?"
 *               question, which has no query to constrain;
 *   in the UI   the panel renders nothing for a customer — a usability
 *               consequence of the rule above, never the rule itself.
 *
 * Write rules:
 *
 *   viewAny / view    staff members of the board (administrators anywhere)
 *   create            same, and only for a ticket they can already read
 *   cancel            same, and only while the run is still queued
 *
 * Customers never trigger runs manually. This is stated as its own line in
 * canUseAi() rather than falling out of the staff check, so a later requirement
 * that lets some customers do it has one place to change.
 */
class AiRunPolicy
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * May this user see the AI runs on this ticket at all?
     */
    public function viewAny(User $user, Ticket $ticket): Response
    {
        return $this->canUseAi($user, $ticket)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $user, AiRun $run): Response
    {
        $run->loadMissing('ticket');

        if (! $run->ticket instanceof Ticket) {
            return Response::denyAsNotFound();
        }

        return $this->canUseAi($user, $run->ticket)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * May this user start a run on this ticket?
     *
     * Whether a run *would* be started — the cap, the provider credential, the
     * repository — is not an authorization question and is not asked here. That
     * is App\Actions\AI\CreateAiRun's job, which is why refusals there are a
     * distinct exception type rather than a denial.
     */
    public function create(User $user, Ticket $ticket, ?AiRunMode $mode = null): Response
    {
        if (! $this->canUseAi($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        // Apply mode opens a pull request against the team's own repositories,
        // so it is held to the same bar as changing a ticket's visibility: any
        // staff member of the board, never a customer. Kept as its own branch so
        // narrowing it to administrators later is a one-line change.
        if ($mode?->writesCode() === true) {
            return $user->isStaff()
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /**
     * May this user cancel a run that has not started yet?
     */
    public function cancel(User $user, AiRun $run): Response
    {
        $run->loadMissing('ticket');

        if (! $run->ticket instanceof Ticket || ! $this->canUseAi($user, $run->ticket)) {
            return Response::denyAsNotFound();
        }

        return $run->status->isCancellable()
            ? Response::allow()
            : Response::deny('That run has already started, so it can no longer be cancelled.');
    }

    /**
     * The single rule: staff, on a board they can reach, for a ticket they can
     * read.
     *
     * Composed from the existing definitions rather than restated — the ticket
     * half delegates to TicketPolicy, so the customer boundary has one
     * definition and cannot drift.
     */
    private function canUseAi(User $user, Ticket $ticket): bool
    {
        // Customers do not trigger, see or hear about internal AI runs.
        if (! $this->access->canSeeInternalContent($user)) {
            return false;
        }

        return $user->can('view', $ticket);
    }
}
