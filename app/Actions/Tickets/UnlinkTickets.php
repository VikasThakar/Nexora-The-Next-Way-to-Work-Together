<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;

/**
 * Remove a link between two tickets.
 *
 * The caller must be authorized on the ticket it is acting from; the far end
 * gets a history entry too, but only after the far ticket is loaded, so a
 * removal never reveals anything the actor could not already see (they had to
 * see the link to remove it).
 */
class UnlinkTickets
{
    public function __construct(private readonly TicketActivity $activity) {}

    public function handle(TicketLink $link, Ticket $actingFrom, User $actor): void
    {
        DB::transaction(function () use ($link, $actingFrom, $actor): void {
            $otherId = $link->source_ticket_id === $actingFrom->getKey()
                ? $link->target_ticket_id
                : $link->source_ticket_id;

            $other = Ticket::query()->with('board')->find($otherId);
            $type = $link->type;

            $link->delete();

            $actingFrom->loadMissing('board');

            $this->activity->record($actingFrom, TicketEventType::LinkChanged, [
                'action' => 'removed',
                'relation' => $type->label(),
                'ticket' => $other?->key(),
            ], $actor);

            if ($other instanceof Ticket) {
                $this->activity->record($other, TicketEventType::LinkChanged, [
                    'action' => 'removed',
                    'relation' => $type->label(),
                    'ticket' => $actingFrom->key(),
                ], $actor);
            }
        });
    }
}
