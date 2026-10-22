<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ticket pages on a board split into tabs: the New ticket form starts in
 * the board's tab and can choose another; the ticket page shows its tab and
 * moves it from there; the way back leads to that tab.
 */
class TicketTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_ticket_form_starts_in_the_boards_tab_and_offers_its_columns(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'Design review']);

        Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(TicketCreate::class, ['board' => $board])
            ->assertSet('tab', 'website')
            ->assertSet('columnId', (string) $this->columnNamed($board, 'Backlog', $website)->id)
            ->assertSee('Design review')
            ->set('title', 'Filed on the website')
            ->set('columnId', (string) $this->columnNamed($board, 'Design review', $website)->id)
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Filed on the website')->sole();
        $this->assertSame($this->columnNamed($board, 'Design review', $website)->id, $ticket->board_column_id);
    }

    public function test_choosing_another_tab_starts_the_column_again_and_a_stale_column_is_not_used(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $component = Livewire::actingAs($team)->test(TicketCreate::class, ['board' => $board])
            ->set('columnId', (string) $this->columnNamed($board, 'Done', $this->defaultTab($board))->id)
            ->set('tab', 'website')
            ->assertSet('columnId', (string) $this->columnNamed($board, 'Backlog', $website)->id);

        // A column from the old tab sent anyway lands in the chosen tab's first column.
        $component->set('columnId', (string) $this->columnNamed($board, 'Done', $this->defaultTab($board))->id)
            ->set('title', 'Stale column')
            ->call('save');

        $this->assertSame(
            $this->columnNamed($board, 'Backlog', $website)->id,
            Ticket::query()->where('title', 'Stale column')->value('board_column_id'),
        );
    }

    public function test_a_customer_chooses_the_tab_and_is_told_where_their_request_starts(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->tabOn($board, 'Website');

        Livewire::actingAs($customer)->test(TicketCreate::class, ['board' => $board])
            ->assertSee('Your request will start in')
            ->set('tab', 'website')
            ->assertSee('Website › Backlog')
            ->set('title', 'Please fix the contact form')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Please fix the contact form')->sole();
        $this->assertSame('Website', $ticket->column->tab->name);
        $this->assertSame('Backlog', $ticket->column->name);
    }

    public function test_the_ticket_page_lists_its_own_tabs_columns_and_moves_it_to_another_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'Design review']);

        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'In Progress', $main)->id]);

        $component = Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertSet('tabId', (string) $main->id)
            ->assertDontSee('Design review');

        $component->set('tabId', (string) $website->id)
            ->assertSet('tabNotice', 'Moved to Website › In Progress.')
            ->assertSet('columnId', (string) $this->columnNamed($board, 'In Progress', $website)->id)
            ->assertSee('Design review');

        $this->assertSame($this->columnNamed($board, 'In Progress', $website)->id, $ticket->fresh()->board_column_id);
    }

    public function test_the_status_choice_cannot_reach_another_tabs_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->set('columnId', (string) $this->columnNamed($board, 'Done', $website)->id)
            ->assertNotFound();

        $this->assertSame($this->defaultTab($board)->id, $ticket->fresh()->column->board_tab_id);
    }

    public function test_a_customer_sees_the_tab_but_cannot_move_the_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $customer, ['board_tab_id' => $website->id]);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->assertSee('Website')
            ->set('tabId', (string) $this->defaultTab($board)->id)
            ->assertForbidden();

        $this->assertSame($website->id, $ticket->fresh()->column->board_tab_id);
    }

    public function test_the_way_back_and_the_trail_lead_to_the_tickets_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $tabUrl = route('boards.show', ['board' => $board, 'tab' => 'website']);

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee($tabUrl, false);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('destroyTicket')
            ->assertRedirect($tabUrl);
    }

    public function test_a_single_tab_board_keeps_its_plain_trail_and_links(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $html = $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('?tab=', $html);
        $this->assertStringNotContainsString('id="ticket-tab"', $html);
    }
}
