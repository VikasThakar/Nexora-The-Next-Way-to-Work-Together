<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Models\Ticket;
use App\Models\TicketEvent;
use Livewire\Component;

/**
 * The ticket timeline.
 *
 * Reads through TicketEvent::readableBy(), which applies board membership and
 * then drops event types a customer must not see. A visibility change is the
 * important one: showing a customer that a ticket was flipped from internal to
 * visible tells them it was previously hidden from them.
 */
class Activity extends Component
{
    public Ticket $ticket;

    public int $perPage = 15;

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticket = $ticket;
    }

    public function showMore(): void
    {
        $this->perPage += 15;
    }

    public function render()
    {
        $query = TicketEvent::query()
            ->readableBy(auth()->user())
            ->where('ticket_id', $this->ticket->getKey());

        $total = (clone $query)->count();

        $events = $query
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->perPage)
            ->get();

        return view('livewire.tickets.components.activity', [
            'events' => $events,
            'hasMore' => $total > $events->count(),
            'total' => $total,
        ]);
    }
}
