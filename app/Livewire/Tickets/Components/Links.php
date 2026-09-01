<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Actions\Tickets\LinkTickets;
use App\Actions\Tickets\UnlinkTickets;
use App\Enums\TicketLinkType;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Services\TicketFinder;
use App\Services\TicketLinkReader;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

/**
 * Related tickets, including tickets on other boards.
 *
 * Three separate places have to hold for this not to leak:
 *
 *  - listing goes through TicketLinkReader, which re-queries the far end
 *    through the ordinary visibility scope and silently drops what the viewer
 *    cannot see;
 *  - the search box that finds a ticket to link goes through TicketFinder, so
 *    it can only ever offer tickets the actor can already read;
 *  - LinkTickets re-checks the target itself, so a guessed id in a crafted
 *    request cannot create a link to something invisible.
 */
class Links extends Component
{
    public Ticket $ticket;

    public string $search = '';

    public string $type = 'blocks';

    public ?int $selectedTicketId = null;

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticket = $ticket;
    }

    public function selectTicket(int $ticketId): void
    {
        $this->selectedTicketId = $ticketId;
    }

    public function clearSelection(): void
    {
        $this->selectedTicketId = null;
        $this->search = '';
    }

    public function link(LinkTickets $linkTickets): void
    {
        $this->authorize('manageLinks', $this->ticket);

        $validated = $this->validate([
            'selectedTicketId' => ['required', 'integer'],
            'type' => ['required', Rule::enum(TicketLinkType::class)],
        ], attributes: ['selectedTicketId' => 'ticket']);

        // Resolved through the visibility scope, so an id the actor cannot
        // read simply does not resolve.
        $target = Ticket::query()
            ->visibleTo(auth()->user())
            ->whereKey($validated['selectedTicketId'])
            ->with('board')
            ->first();

        if (! $target instanceof Ticket) {
            $this->addError('selectedTicketId', 'That ticket could not be found.');

            return;
        }

        try {
            $linkTickets->handle(
                $this->ticket,
                $target,
                TicketLinkType::from($validated['type']),
                auth()->user()
            );
        } catch (RuntimeException $exception) {
            $this->addError('selectedTicketId', $exception->getMessage());

            return;
        }

        $this->clearSelection();
    }

    public function unlink(int $linkId, UnlinkTickets $unlinkTickets): void
    {
        $this->authorize('manageLinks', $this->ticket);

        // The link must touch this ticket; otherwise a swapped id could delete
        // a relationship between two tickets the actor never saw.
        $link = TicketLink::query()
            ->whereKey($linkId)
            ->where(function ($query): void {
                $query->where('source_ticket_id', $this->ticket->getKey())
                    ->orWhere('target_ticket_id', $this->ticket->getKey());
            })
            ->first();

        abort_unless($link instanceof TicketLink, 404);

        $unlinkTickets->handle($link, $this->ticket, auth()->user());
    }

    public function render(TicketLinkReader $reader, TicketFinder $finder)
    {
        $results = collect();

        if (trim($this->search) !== '' && auth()->user()->can('manageLinks', $this->ticket)) {
            $results = $finder->searchAcrossBoards(auth()->user(), $this->search, 8)
                ->reject(fn (Ticket $candidate): bool => $candidate->is($this->ticket));
        }

        return view('livewire.tickets.components.links', [
            'links' => $reader->forTicket($this->ticket, auth()->user()),
            'results' => $results,
            'linkTypes' => TicketLinkType::options(),
            'canManage' => auth()->user()->can('manageLinks', $this->ticket),
            'selected' => $this->selectedTicketId !== null
                ? Ticket::query()->visibleTo(auth()->user())->with('board')->find($this->selectedTicketId)
                : null,
        ]);
    }
}
