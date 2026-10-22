<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Tabs\DeleteTab;
use App\Actions\Tabs\ReorderTabs;
use App\Livewire\Boards\Show as BoardShow;
use App\Models\BoardTab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The board page with tabs: the strip above the columns, switching, what each
 * tab shows, the counts, and the URL.
 */
class BoardTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_tab_on_screen_is_drawn_and_its_columns_are_its_own(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->columnNamed($board, 'Review', $website)->update(['name' => 'Design review']);

        $this->ticketOn($board, $team, ['title' => 'Main tab ticket']);
        $this->ticketOn($board, $team, ['title' => 'Website tab ticket', 'board_tab_id' => $website->id]);

        $component = Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->assertSee('Main tab ticket')
            ->assertDontSee('Website tab ticket')
            ->assertDontSee('Design review');

        $component->call('selectTab', $website->id)
            ->assertSet('tab', 'website')
            ->assertSee('Website tab ticket')
            ->assertSee('Design review')
            ->assertDontSee('Main tab ticket');
    }

    public function test_the_strip_is_a_real_tablist_with_counts(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);
        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $html = $this->actingAs($team)->get(route('boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('id="board-tab-'.$website->id.'"', $html);
        $this->assertStringContainsString('aria-controls="board-tabpanel"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*>\s*<span class="truncate">Main<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/<span class="truncate">Website<\/span>\s*<span[^>]*>\s*2<span class="sr-only"> tickets/s', $html);
    }

    public function test_a_board_with_one_tab_shows_no_strip_to_those_who_cannot_add_one(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->actingAs($customer)->get(route('boards.show', $board))
            ->assertOk()
            ->assertDontSee('role="tablist"', false);

        // The delivery team can split it, so for them the strip — with "+" — is there.
        $this->actingAs($team)->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('Add a tab');
    }

    public function test_counts_follow_the_filters_and_what_each_viewer_may_see(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['title' => 'Visible bug', 'board_tab_id' => $website->id, 'customer_visible' => true]);
        $this->ticketOn($board, $team, ['title' => 'Internal chore', 'board_tab_id' => $website->id]);

        $staff = Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board]);
        $this->assertSame([$website->id => 2], $staff->viewData('tabCounts'));

        $staff->set('search', 'bug');
        $this->assertSame([$website->id => 1], $staff->viewData('tabCounts'));

        $client = Livewire::actingAs($customer)->test(BoardShow::class, ['board' => $board]);
        $this->assertSame([$website->id => 1], $client->viewData('tabCounts'));
    }

    public function test_filters_stay_on_when_switching_tabs(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team, ['title' => 'Login bug', 'board_tab_id' => $website->id]);
        $this->ticketOn($board, $team, ['title' => 'Pricing copy', 'board_tab_id' => $website->id]);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->set('search', 'login')
            ->call('selectTab', $website->id)
            ->assertSet('search', 'login')
            ->assertSee('Login bug')
            ->assertDontSee('Pricing copy');
    }

    public function test_the_url_names_the_tab_and_a_tab_from_elsewhere_falls_back_to_the_default(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $foreign = $this->tabOn($other, 'Secret plans');

        Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardShow::class, ['board' => $board])
            ->assertSet('tab', 'website')
            ->assertDispatched('board-tab-changed', board: $board->slug, tab: 'website');

        // Another board's slug is not this board's tab: the default, and a word about it.
        Livewire::actingAs($team)->withQueryParams(['tab' => $foreign->slug])
            ->test(BoardShow::class, ['board' => $board])
            ->assertSet('tab', '')
            ->assertSee('That tab does not exist on this board any more.')
            ->assertDontSee('Secret plans');

        $this->assertTrue($website->exists);
    }

    public function test_a_board_opened_without_a_tab_shows_the_one_you_last_had_open(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('selectTab', $website->id);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->assertSet('tab', 'website');

        // Once that tab is gone, back to the default.
        app(DeleteTab::class)->handle($website, null, $team);

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->assertSet('tab', '');
    }

    public function test_the_browsers_back_button_steps_between_tabs(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['title' => 'Website tab ticket', 'board_tab_id' => $website->id]);

        // Back/forward set ?tab, which arrives as the property itself.
        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->set('tab', 'website')
            ->assertSee('Website tab ticket')
            ->assertDispatched('board-tab-changed', board: $board->slug, tab: 'website')
            ->set('tab', '')
            ->assertDontSee('Website tab ticket');
    }

    public function test_a_column_or_tab_from_elsewhere_is_not_found(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        // A column of a tab that is not on screen — a drop that cannot have happened.
        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('moveTicket', $ticket->id, 0, $this->columnNamed($board, 'Done', $website)->id)
            ->assertNotFound();

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $this->columnNamed($board, 'Backlog', $website)->id)
            ->assertNotFound();

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('selectTab', $this->defaultTab($other)->id)
            ->assertNotFound();

        $this->assertSame($this->columnNamed($board, 'Backlog', $this->defaultTab($board))->id, $ticket->fresh()->board_column_id);
    }

    public function test_switching_tabs_closes_an_open_quick_add(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $this->columnNamed($board, 'Backlog', $this->defaultTab($board))->id)
            ->set('quickAddTitle', 'Half typed')
            ->call('selectTab', $website->id)
            ->assertSet('quickAddColumnId', null)
            ->assertSet('quickAddTitle', '');
    }

    public function test_the_new_ticket_and_configure_links_carry_the_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardShow::class, ['board' => $board])
            ->assertSee(route('tickets.create', ['board' => $board, 'tab' => 'website']), false)
            ->assertSee(route('boards.settings', ['board' => $board, 'tab' => 'website']), false);

        $this->assertSame('website', $website->slug);
    }

    public function test_when_the_open_tab_is_removed_by_someone_else_the_board_falls_back(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $component = Livewire::actingAs($team)->withQueryParams(['tab' => 'website'])
            ->test(BoardShow::class, ['board' => $board]);

        app(DeleteTab::class)->handle(BoardTab::query()->findOrFail($website->id), null, $team);

        // The realtime signal is a plain $refresh.
        $component->call('$refresh')
            ->assertSet('tab', '')
            ->assertSee('The tab you had open was removed, so this is Main.');
    }

    public function test_the_tab_on_screen_stays_when_someone_else_makes_another_tab_the_first(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $main = $this->defaultTab($board);
        $website = $this->tabOn($board, 'Website');
        $this->ticketOn($board, $team, ['title' => 'Main tab ticket']);
        $this->ticketOn($board, $team, ['title' => 'Website tab ticket', 'board_tab_id' => $website->id]);

        // Opened on the leftmost tab, so the URL is plain.
        $component = Livewire::actingAs($team)->test(BoardShow::class, ['board' => $board])
            ->assertSet('tab', '')
            ->assertSee('Main tab ticket');

        app(ReorderTabs::class)->handle($board, [$website->id, $main->id], $team);

        // Still Main after the refresh — now named in the URL, since it is no
        // longer the tab a plain URL means.
        $component->call('$refresh')
            ->assertSet('tab', 'main')
            ->assertSee('Main tab ticket')
            ->assertDontSee('Website tab ticket')
            ->assertSee(route('tickets.create', ['board' => $board, 'tab' => 'main']), false);
    }
}
