<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Tickets\LinkTickets;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Enums\TicketLinkType;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\TicketFinder;
use App\Services\TicketLinkReader;
use App\Support\TicketFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer must never receive an internal ticket by any route.
 *
 * Each test closes one specific way the rule could be bypassed: the board, a
 * guessed URL, search, filters, links, and the timeline. They are grouped here
 * rather than spread across feature tests so the whole boundary can be run as
 * one gate before a release.
 */
class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_never_renders_an_internal_ticket_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Internal rotation of secrets']);
        $this->ticketOn($board, $team, ['title' => 'Shared status update', 'customer_visible' => true]);

        $this->actingAs($customer)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee('Shared status update')
            ->assertDontSee('Internal rotation of secrets');
    }

    public function test_a_customer_cannot_open_an_internal_ticket_by_url(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team, ['title' => 'Internal only']);

        $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $internal->number]))
            ->assertNotFound();
    }

    public function test_an_internal_ticket_and_a_nonexistent_one_are_indistinguishable(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team, ['title' => 'Internal only']);

        $forInternal = $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $internal->number]));

        $forMissing = $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => 9999]));

        $this->assertSame(404, $forInternal->status());
        $this->assertSame(404, $forMissing->status());
        $this->assertStringNotContainsString('Internal only', $forInternal->getContent());
    }

    public function test_mounting_the_ticket_component_directly_is_still_authorized(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $internal->number])
            ->assertNotFound();
    }

    public function test_a_customer_cannot_find_an_internal_ticket_through_search(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Zebra internal matter']);
        $this->ticketOn($board, $team, ['title' => 'Zebra public matter', 'customer_visible' => true]);

        $finder = app(TicketFinder::class);

        $customerResults = $finder->searchAcrossBoards($customer, 'Zebra')->pluck('title');
        $teamResults = $finder->searchAcrossBoards($team, 'Zebra')->pluck('title');

        $this->assertEquals(['Zebra public matter'], $customerResults->all());
        $this->assertCount(2, $teamResults);
    }

    public function test_board_search_does_not_surface_internal_tickets_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Quokka internal']);

        Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->set('search', 'Quokka')
            ->assertDontSee('Quokka internal');
    }

    public function test_searching_by_ticket_number_does_not_reveal_an_internal_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team, ['title' => 'Numbered internal']);

        $results = app(TicketFinder::class)
            ->searchAcrossBoards($customer, $board->ticket_prefix.'-'.$internal->number);

        $this->assertCount(0, $results);
    }

    public function test_asking_for_internal_only_as_a_customer_returns_nothing(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Hidden work']);
        $this->ticketOn($board, $team, ['title' => 'Visible work', 'customer_visible' => true]);

        // A hand-edited query string cannot widen visibility: the filter is
        // composed on top of the scope, never instead of it.
        $tickets = app(TicketFinder::class)->forBoard(
            $board,
            $customer,
            new TicketFilters(visibility: TicketFilters::VISIBILITY_INTERNAL)
        );

        $this->assertCount(0, $tickets);
    }

    public function test_a_link_to_an_internal_ticket_is_not_shown_to_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $visible = $this->ticketOn($board, $team, ['title' => 'Public face', 'customer_visible' => true]);
        $internal = $this->ticketOn($board, $team, ['title' => 'Hidden dependency']);

        app(LinkTickets::class)->handle($visible, $internal, TicketLinkType::Blocks, $team);

        $reader = app(TicketLinkReader::class);

        $this->assertCount(1, $reader->forTicket($visible, $team));
        $this->assertCount(
            0,
            $reader->forTicket($visible, $customer),
            'An unreachable link must be omitted entirely, not shown as a placeholder.'
        );

        $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $visible->number]))
            ->assertOk()
            ->assertDontSee('Hidden dependency');
    }

    public function test_a_link_to_a_ticket_on_another_board_is_hidden_from_non_members(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $shared = $this->boardWithColumns([$team, $customer]);
        $other = $this->boardWithColumns([$team]);

        $onShared = $this->ticketOn($shared, $team, ['title' => 'Shared item', 'customer_visible' => true]);
        $elsewhere = $this->ticketOn($other, $team, ['title' => 'Other board item', 'customer_visible' => true]);

        app(LinkTickets::class)->handle($onShared, $elsewhere, TicketLinkType::RelatesTo, $team);

        $reader = app(TicketLinkReader::class);

        $this->assertCount(1, $reader->forTicket($onShared, $team));
        $this->assertCount(0, $reader->forTicket($onShared, $customer));
    }

    public function test_a_customer_does_not_see_visibility_changes_in_the_timeline(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Was internal']);

        // Flipped to visible; the customer must not learn it was ever hidden.
        app(UpdateTicket::class)
            ->handle($ticket, ['customer_visible' => true], $team);

        $customerEvents = TicketEvent::query()
            ->readableBy($customer)
            ->where('ticket_id', $ticket->getKey())
            ->pluck('type');

        $teamEvents = TicketEvent::query()
            ->readableBy($team)
            ->where('ticket_id', $ticket->getKey())
            ->pluck('type');

        $this->assertFalse($customerEvents->contains(TicketEventType::VisibilityChanged));
        $this->assertTrue($teamEvents->contains(TicketEventType::VisibilityChanged));
    }

    public function test_a_non_member_of_any_role_sees_no_tickets_at_all(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Members only', 'customer_visible' => true]);

        $this->assertCount(0, app(TicketFinder::class)->forBoard($board, $outsider));
        $this->assertCount(0, Ticket::query()->visibleTo($outsider)->get());
    }

    public function test_an_administrator_sees_internal_tickets_on_boards_they_do_not_belong_to(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Deep internal']);

        $this->assertFalse($board->hasMember($admin));
        $this->assertCount(1, app(TicketFinder::class)->forBoard($board, $admin));
    }
}
