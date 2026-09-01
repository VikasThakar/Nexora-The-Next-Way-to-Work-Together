<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketLink;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Reads a ticket's links without leaking the ones the viewer may not see.
 *
 * Links are the only place a ticket points at a row on another board, so this
 * is the one relationship that can bypass the board membership rule if it is
 * read naively. Rendering $link->target directly would hand a customer the
 * title of an internal ticket.
 *
 * The approach: collect the ids at the far end, re-query them through the
 * ordinary visibility scope, and drop every link whose far end did not come
 * back. Unreachable links are omitted entirely — not shown as a placeholder and
 * not counted — because even "2 linked tickets you cannot see" tells the viewer
 * that those tickets exist.
 */
class TicketLinkReader
{
    /**
     * @return Collection<int, object{link: TicketLink, ticket: Ticket, label: string, direction: string}>
     */
    public function forTicket(Ticket $ticket, ?Authenticatable $viewer): Collection
    {
        $outgoing = TicketLink::query()
            ->where('source_ticket_id', $ticket->getKey())
            ->get();

        $incoming = TicketLink::query()
            ->where('target_ticket_id', $ticket->getKey())
            ->get();

        $otherIds = $outgoing->pluck('target_ticket_id')
            ->merge($incoming->pluck('source_ticket_id'))
            ->unique()
            ->all();

        if ($otherIds === []) {
            return collect();
        }

        // The security step: the far ends are re-fetched through the same
        // scope as any other ticket read, so board membership and the customer
        // rule both apply.
        $reachable = Ticket::query()
            ->visibleTo($viewer)
            ->whereIn('tickets.id', $otherIds)
            ->with('board')
            ->get()
            ->keyBy('id');

        $rows = collect();

        foreach ($outgoing as $link) {
            $target = $reachable->get($link->target_ticket_id);

            if ($target instanceof Ticket) {
                $rows->push((object) [
                    'link' => $link,
                    'ticket' => $target,
                    'label' => $link->type->outwardLabel(),
                    'direction' => 'outgoing',
                ]);
            }
        }

        foreach ($incoming as $link) {
            $source = $reachable->get($link->source_ticket_id);

            if ($source instanceof Ticket) {
                $rows->push((object) [
                    'link' => $link,
                    'ticket' => $source,
                    'label' => $link->type->inwardLabel(),
                    'direction' => 'incoming',
                ]);
            }
        }

        return $rows->sortBy(fn (object $row): string => $row->label.$row->ticket->number)->values();
    }
}
