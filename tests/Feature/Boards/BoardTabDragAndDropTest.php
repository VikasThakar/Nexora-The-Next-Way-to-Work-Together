<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Livewire\Boards\Show as BoardShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dropping a card onto another tab.
 *
 * As with BoardDragAndDropTest, the drag itself needs a browser; these pin the
 * markup SortableJS relies on, who gets it, and what the drop does on the
 * server.
 */
class BoardTabDragAndDropTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_other_tab_takes_a_dropped_card_and_only_a_card(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');

        $html = $this->actingAs($team)->get(route('boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('$wire.moveTicketToTab($item, '.$website->id.')', $html);

        // Not the tab on screen: dropping there would be a move to nowhere.
        $this->assertStringNotContainsString('$wire.moveTicketToTab($item, '.$main->id.')', $html);

        // Takes cards from this board's columns and nothing else, never hands one out.
        $this->assertStringContainsString("put: ['board-".$board->id."']", $html);
        $this->assertStringContainsString('pull: false', $html);
        $this->assertStringContainsString('emptyInsertThreshold: 0', $html);
        $this->assertStringContainsString('data-tab-drop', $html);

        // Tabs reorder among themselves: no group, so a card can never land in the strip itself.
        $this->assertStringContainsString('x-sort="$wire.reorderTab($item, $position)"', $html);
    }

    public function test_customers_see_the_tabs_but_get_no_dragging_at_all(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['customer_visible' => true]);

        $html = $this->actingAs($customer)->get(route('boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('Website', $html);
        $this->assertStringNotContainsString('x-sort', $html);
        $this->assertStringNotContainsString('data-tab-drop', $html);
    }

    public function test_a_card_dropped_on_a_tab_lands_in_the_column_of_the_same_name_at_the_end(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');

        $waiting = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'In Progress', $website)->id]);
        $ticket = $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'In Progress', $main)->id]);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('moveTicketToTab', $ticket->id, $website->id)
            ->assertSet('notice', $ticket->key().' moved to Website › In Progress.')
            ->assertDontSee($ticket->title);

        $inProgress = $this->columnNamed($board, 'In Progress', $website);

        $this->assertSame($inProgress->id, $ticket->fresh()->board_column_id);
        $this->assertSame([$waiting->id, $ticket->id], $inProgress->tickets()->ordered()->pluck('id')->all());
    }

    public function test_a_drop_on_the_tab_it_is_already_in_changes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('moveTicketToTab', $ticket->id, $this->defaultTab($board)->id)
            ->assertSet('notice', null);

        $this->assertSame($this->columnNamed($board, 'Backlog', $this->defaultTab($board))->id, $ticket->fresh()->board_column_id);
    }

    public function test_customers_may_not_drop_and_another_boards_tab_is_not_found(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $mine = $this->ticketOn($board, $customer);

        Livewire::actingAs($customer)->test(BoardShow::class, ['board' => $board])
            ->call('moveTicketToTab', $mine->id, $website->id)
            ->assertForbidden();

        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('moveTicketToTab', $ticket->id, $this->defaultTab($other)->id)
            ->assertNotFound();

        $this->assertSame($this->defaultTab($board)->id, $mine->fresh()->column->board_tab_id);
        $this->assertSame($this->defaultTab($board)->id, $ticket->fresh()->column->board_tab_id);
    }
}
