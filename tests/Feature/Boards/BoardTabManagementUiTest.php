<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Livewire\Boards\Show as BoardShow;
use App\Models\BoardTab;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Managing tabs from the board's tab strip: "+", the tab menu, dragging tabs,
 * and the delete panel — each re-authorized and resolved within the board.
 */
class BoardTabManagementUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_plus_adds_a_tab_with_the_open_tabs_columns_and_opens_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->columnNamed($board, 'Review')->update(['name' => 'With legal']);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('createTab', 'Backend API')
            ->assertHasNoErrors()
            ->assertSet('tab', 'backend-api')
            ->assertSet('notice', 'Added the Backend API tab, with the same columns as Main.')
            ->assertSee('With legal');

        $tab = $board->tabs()->where('slug', 'backend-api')->sole();
        $this->assertSame('With legal', $tab->columns()->where('name', 'With legal')->value('name'));
    }

    public function test_a_bad_name_is_shown_beside_the_strip(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('createTab', 'main')
            ->assertHasErrors('tabName')
            ->assertSee('This board already has a tab called');

        $this->assertSame(1, $board->tabs()->count());
    }

    public function test_tabs_are_renamed_and_moved_left_or_right(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');
        $api = $this->tabOn($board, 'API');

        $component = Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('renameTab', $website->id, 'Public website')
            ->assertHasNoErrors();

        $this->assertSame('Public website', $website->fresh()->name);
        $this->assertSame('website', $website->fresh()->slug);

        $component->call('moveTab', $api->id, -1);
        $this->assertSame([$main->id, $api->id, $website->id], $board->tabs()->pluck('id')->all());

        $component->call('reorderTab', $website->id, 0);
        $this->assertSame([$website->id, $main->id, $api->id], $board->tabs()->pluck('id')->all());
    }

    public function test_the_delete_panel_says_where_every_ticket_will_go_before_anything_happens(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'QA']);

        $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'QA', $website)->id]);
        $this->ticketOn($board, $team, ['board_column_id' => $this->columnNamed($board, 'Done', $website)->id]);

        $component = Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('startDeletingTab', $website->id)
            ->assertSet('deletingTabId', $website->id)
            ->assertSet('destinationTabId', $this->defaultTab($board)->id)
            ->assertSee('Delete the “Website” tab?')
            ->assertSee('It holds 2 tickets.')
            ->assertSee('QA (1) → Backlog')
            ->assertSee('(no column of that name)')
            ->assertSee('Done (1) → Done');

        // Nothing has moved yet.
        $this->assertSame(2, Ticket::query()->inTab($website)->count());

        $component->call('confirmDeleteTab')
            ->assertSet('deletingTabId', null)
            ->assertSee('Removed the Website tab. Its 2 tickets moved to Main.');

        $this->assertNull(BoardTab::query()->find($website->id));
        $this->assertSame(2, Ticket::query()->inTab($this->defaultTab($board))->count());
    }

    public function test_deleting_the_open_tab_moves_the_board_to_where_its_tickets_went(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $api = $this->tabOn($board, 'API');
        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardShow::class, ['board' => $board])
            ->call('startDeletingTab', $website->id)
            ->set('destinationTabId', $api->id)
            ->call('confirmDeleteTab')
            ->assertSet('tab', 'api');
    }

    public function test_the_only_tab_cannot_be_deleted_from_the_strip(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('startDeletingTab', $this->defaultTab($board)->id)
            ->assertHasErrors('tabName')
            ->assertSet('deletingTabId', null);
    }

    public function test_customers_cannot_manage_tabs_and_another_boards_tab_is_not_found(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        foreach ([
            ['createTab', ['Sneaky']],
            ['renameTab', [$website->id, 'Sneaky']],
            ['moveTab', [$website->id, -1]],
            ['reorderTab', [$website->id, 0]],
            ['startDeletingTab', [$website->id]],
        ] as [$method, $arguments]) {
            Livewire::actingAs($customer)->test(BoardShow::class, ['board' => $board])
                ->call($method, ...$arguments)
                ->assertForbidden();
        }

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('renameTab', $this->defaultTab($other)->id, 'Mine now')
            ->assertNotFound();

        $this->assertSame(['Main', 'Website'], $board->tabs()->pluck('name')->all());
        $this->assertSame('Main', $this->defaultTab($other)->name);
    }

    public function test_a_forged_destination_is_refused_and_nothing_is_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('startDeletingTab', $website->id)
            ->set('destinationTabId', $this->defaultTab($other)->id)
            ->call('confirmDeleteTab')
            ->assertDispatched('dialog');

        $this->assertNotNull(BoardTab::query()->find($website->id));
        $this->assertSame(1, Ticket::query()->inTab($website)->count());
    }
}
