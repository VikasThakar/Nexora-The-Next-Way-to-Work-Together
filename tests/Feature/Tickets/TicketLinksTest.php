<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\LinkTickets;
use App\Enums\TicketEventType;
use App\Enums\TicketLinkType;
use App\Livewire\Tickets\Components\Links;
use App\Models\TicketLink;
use App\Services\TicketLinkReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class TicketLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_tickets_can_be_linked_and_read_from_both_ends(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $blocker = $this->ticketOn($board, $team, ['title' => 'Blocker']);
        $blocked = $this->ticketOn($board, $team, ['title' => 'Blocked']);

        app(LinkTickets::class)->handle($blocker, $blocked, TicketLinkType::Blocks, $team);

        $reader = app(TicketLinkReader::class);

        $fromSource = $reader->forTicket($blocker, $team)->sole();
        $fromTarget = $reader->forTicket($blocked, $team)->sole();

        $this->assertSame('Blocks', $fromSource->label);
        $this->assertSame('Blocked', $fromSource->ticket->title);

        $this->assertSame('Blocked by', $fromTarget->label);
        $this->assertSame('Blocker', $fromTarget->ticket->title);
    }

    public function test_tickets_on_different_boards_can_be_linked(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $here = $this->ticketOn($board, $team, ['title' => 'Here']);
        $there = $this->ticketOn($other, $team, ['title' => 'There']);

        app(LinkTickets::class)->handle($here, $there, TicketLinkType::RelatesTo, $team);

        $row = app(TicketLinkReader::class)->forTicket($here, $team)->sole();

        $this->assertSame('There', $row->ticket->title);
        $this->assertNotSame($here->board_id, $row->ticket->board_id);
    }

    public function test_a_ticket_cannot_be_linked_to_itself(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->expectException(RuntimeException::class);

        app(LinkTickets::class)->handle($ticket, $ticket, TicketLinkType::Blocks, $team);
    }

    public function test_the_same_link_is_not_stored_twice(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);

        $linker = app(LinkTickets::class);

        $linker->handle($a, $b, TicketLinkType::Blocks, $team);
        $linker->handle($a, $b, TicketLinkType::Blocks, $team);

        $this->assertSame(1, TicketLink::query()->count());
    }

    public function test_a_symmetric_link_is_not_duplicated_in_the_reverse_direction(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);

        $linker = app(LinkTickets::class);

        $linker->handle($a, $b, TicketLinkType::RelatesTo, $team);
        $linker->handle($b, $a, TicketLinkType::RelatesTo, $team);

        $this->assertSame(1, TicketLink::query()->count(), '"Relates to" reads the same from both ends.');
    }

    public function test_linking_to_a_ticket_the_actor_cannot_see_is_refused(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $secretBoard = $this->boardWithColumns([$stranger]);

        $mine = $this->ticketOn($board, $team);
        $hidden = $this->ticketOn($secretBoard, $stranger, ['title' => 'Not for you']);

        $this->expectException(RuntimeException::class);

        try {
            app(LinkTickets::class)->handle($mine, $hidden, TicketLinkType::Blocks, $team);
        } finally {
            $this->assertSame(0, TicketLink::query()->count());
        }
    }

    public function test_linking_records_history_on_both_tickets(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);

        app(LinkTickets::class)->handle($a, $b, TicketLinkType::Blocks, $team);

        $this->assertSame(1, $a->events()->where('type', TicketEventType::LinkChanged)->count());
        $this->assertSame(1, $b->events()->where('type', TicketEventType::LinkChanged)->count());
    }

    public function test_a_link_can_be_removed_from_either_end(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);

        $link = app(LinkTickets::class)->handle($a, $b, TicketLinkType::Blocks, $team);

        Livewire::actingAs($team)
            ->test(Links::class, ['ticket' => $b])
            ->call('unlink', $link->id);

        $this->assertSame(0, TicketLink::query()->count());
    }

    public function test_a_link_between_two_other_tickets_cannot_be_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);
        $bystander = $this->ticketOn($board, $team);

        $link = app(LinkTickets::class)->handle($a, $b, TicketLinkType::Blocks, $team);

        Livewire::actingAs($team)
            ->test(Links::class, ['ticket' => $bystander])
            ->call('unlink', $link->id)
            ->assertNotFound();

        $this->assertSame(1, TicketLink::query()->count());
    }

    public function test_the_link_search_only_offers_tickets_the_actor_can_read(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $secretBoard = $this->boardWithColumns([$stranger]);

        $mine = $this->ticketOn($board, $team);
        $this->ticketOn($secretBoard, $stranger, ['title' => 'Wombat secret']);
        $this->ticketOn($board, $team, ['title' => 'Wombat mine']);

        Livewire::actingAs($team)
            ->test(Links::class, ['ticket' => $mine])
            ->set('search', 'Wombat')
            ->assertSee('Wombat mine')
            ->assertDontSee('Wombat secret');
    }

    public function test_links_disappear_when_a_linked_ticket_is_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $a = $this->ticketOn($board, $team);
        $b = $this->ticketOn($board, $team);

        app(LinkTickets::class)->handle($a, $b, TicketLinkType::Blocks, $team);

        $b->delete();

        $this->assertSame(0, TicketLink::query()->count());
        $this->assertCount(0, app(TicketLinkReader::class)->forTicket($a->fresh(), $team));
    }
}
