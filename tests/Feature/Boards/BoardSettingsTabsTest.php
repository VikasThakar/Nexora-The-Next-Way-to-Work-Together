<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Livewire\Boards\Settings as BoardSettings;
use App\Models\BoardColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board configuration edits one tab's columns at a time.
 */
class BoardSettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_edits_the_columns_of_the_tab_in_the_url(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'Design review']);

        Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardSettings::class, ['board' => $board])
            ->assertSee('The columns of the Website tab.')
            ->assertSee('Design review')
            ->set('newColumnName', 'Staging')
            ->call('addColumn')
            ->assertHasNoErrors();

        $this->assertSame(1, $website->columns()->where('name', 'Staging')->count());
        $this->assertSame(0, $this->defaultTab($board)->columns()->where('name', 'Staging')->count());
    }

    public function test_column_names_are_unique_per_tab_not_per_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->tabOn($board, 'Website');

        $component = Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardSettings::class, ['board' => $board]);

        // "Backlog" exists in Main — and in Website, which copied it.
        $component->set('newColumnName', 'Backlog')->call('addColumn')->assertHasErrors('newColumnName');

        $website = $board->tabs()->where('slug', 'website')->sole();
        $website->columns()->where('name', 'Backlog')->first()->update(['name' => 'Ideas']);

        $component->set('newColumnName', 'Backlog')->call('addColumn')->assertHasNoErrors();
    }

    public function test_a_column_of_another_tab_is_not_found_here(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $foreign = $this->columnNamed($board, 'Review', $website);

        foreach ([
            ['startEditingColumn', [$foreign->id]],
            ['toggleDoneColumn', [$foreign->id]],
            ['reorderColumn', [$foreign->id, 0]],
            ['startDeletingColumn', [$foreign->id]],
        ] as [$method, $arguments]) {
            Livewire::actingAs($team)->test(BoardSettings::class, ['board' => $board])
                ->call($method, ...$arguments)
                ->assertNotFound();
        }

        $this->assertNotNull(BoardColumn::query()->find($foreign->id));
    }

    public function test_deleting_a_column_offers_only_columns_of_its_own_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $review = $this->columnNamed($board, 'Review', $website);
        $this->ticketOn($board, $team, ['board_column_id' => $review->id]);

        $component = Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startDeletingColumn', $review->id)
            ->assertSet('destinationColumnId', $this->columnNamed($board, 'Backlog', $website)->id);

        // A forged destination in another tab is not used.
        $component->set('destinationColumnId', $this->columnNamed($board, 'Done', $this->defaultTab($board))->id)
            ->call('confirmDeleteColumn');

        $this->assertNotNull(BoardColumn::query()->find($review->id));

        $component->set('destinationColumnId', $this->columnNamed($board, 'Done', $website)->id)
            ->call('confirmDeleteColumn');

        $this->assertNull(BoardColumn::query()->find($review->id));
    }

    public function test_pills_appear_only_when_there_is_more_than_one_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)->test(BoardSettings::class, ['board' => $board])
            ->assertDontSee('Tabs are added, renamed and removed from the tab strip on the board.');

        $this->tabOn($board, 'Website');

        Livewire::actingAs($team)->test(BoardSettings::class, ['board' => $board])
            ->assertSee('Tabs are added, renamed and removed from the tab strip on the board.')
            ->call('$set', 'tab', 'website')
            ->assertSet('tab', 'website')
            ->assertSee('The columns of the Website tab.');
    }
}
