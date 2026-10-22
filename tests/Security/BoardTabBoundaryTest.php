<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\AiCapabilityMode;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * Tabs add no way around the existing boundaries.
 *
 * Customers see every tab's name — the board's structure is not secret, as
 * with columns — but only the tickets they could already see, in lists and in
 * counts alike; they may say which tab a request belongs in and nothing more.
 * And a tab is only ever one of the board's own: a slug or id from another
 * board resolves to nothing, wherever it is sent.
 */
class BoardTabBoundaryTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Operator);
    }

    public function test_a_customer_raises_a_request_in_a_tab_but_cannot_choose_its_status(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer], ['slug' => 'client']);
        $website = $this->tabOn($board, 'Website');

        $result = $this->mcpAs($customer)->mcpTool('create_ticket', [
            'board' => 'client',
            'title' => 'Contact form is broken',
            'tab' => 'Website',
            'status' => 'Done',
        ]);

        $this->assertFalse($result['isError']);

        $ticket = Ticket::query()->where('title', 'Contact form is broken')->sole();

        $this->assertSame($this->columnNamed($board, 'Backlog', $website)->id, $ticket->board_column_id);
        $this->assertTrue($ticket->customer_visible);
    }

    public function test_a_customer_cannot_move_their_ticket_to_another_tab(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer], ['slug' => 'client']);
        $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $customer, ['title' => 'Original']);

        $this->mcpAs($customer)->mcpTool('update_ticket', [
            'ticket' => $ticket->key(),
            'title' => 'Renamed',
            'tab' => 'Website',
        ]);

        $ticket->refresh();

        $this->assertSame('Renamed', $ticket->title);
        $this->assertTrue($ticket->column->tab->is($this->defaultTab($board)));
    }

    public function test_tab_counts_never_include_internal_work_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['slug' => 'client']);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['title' => 'Shared', 'board_tab_id' => $website->id, 'customer_visible' => true]);
        $this->ticketOn($board, $team, ['title' => 'Internal', 'board_tab_id' => $website->id]);

        $text = $this->mcpText($this->mcpAs($customer)->mcpTool('get_board', ['board' => 'client']));

        $this->assertStringContainsString('TAB "Website" — 1 ticket', $text);
        $this->assertStringNotContainsString('Internal', $text);
    }

    public function test_another_boards_tab_resolves_to_nothing_on_any_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $secret = $this->tabOn($other, 'Secret plans');
        $this->columnNamed($other, 'Review', $secret)->update(['name' => 'Classified']);

        Livewire::actingAs($team)->withQueryParams(['tab' => $secret->slug])
            ->test(TicketCreate::class, ['board' => $board])
            ->assertSet('tab', '')
            ->assertDontSee('Secret plans')
            ->assertDontSee('Classified');

        Livewire::actingAs($team)->withQueryParams(['tab' => $secret->slug])
            ->test(BoardSettings::class, ['board' => $board])
            ->assertSet('tab', '')
            ->assertDontSee('Classified');
    }

    public function test_an_ai_app_cannot_name_another_boards_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['slug' => 'mine']);
        $other = $this->boardWithColumns([$team]);
        $this->tabOn($other, 'Secret plans');

        $result = $this->mcpAs($team)->mcpTool('create_ticket', [
            'board' => 'mine',
            'title' => 'Smuggled',
            'tab' => 'Secret plans',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('no tab called', $this->mcpText($result));
        $this->assertSame(0, Ticket::query()->where('title', 'Smuggled')->count());
    }

    public function test_a_board_you_are_not_on_shows_none_of_its_tabs(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Secret plans');

        $this->actingAs($outsider)
            ->get(route('boards.show', ['board' => $board, 'tab' => 'secret-plans']))
            ->assertNotFound();
    }
}
