<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ActivityCategory;
use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Models\Activity;
use App\Support\ActivityFilters;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Activity screen: what it renders, and what its search, filters and
 * pagination actually do.
 *
 * Everything is asserted through the component rather than against the query
 * builder, because the point of these is that the *screen* narrows correctly —
 * a filter that works in isolation and is never applied by render() is not a
 * working filter.
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_for_a_team_member(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Fix Safari login issue']);

        $this->actingAs($team)
            ->get(route('activity'))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('Fix Safari login issue');
    }

    public function test_the_sidebar_offers_activity_directly_below_statistics(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->actingAs($team)
            ->get(route('dashboard'))
            ->assertOk()
            // The order the brief asks for, asserted rather than assumed:
            // Dashboard, Boards, Statistics, Activity.
            ->assertSeeInOrder(['Dashboard', 'Boards', 'Statistics', 'Activity'])
            ->assertSee(route('activity'));
    }

    public function test_the_sidebar_hides_activity_from_a_customer(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        // Asserted against the URL rather than the word: "Activity" is also the
        // heading of the timeline card on a ticket, and a false pass here would
        // be worse than no test.
        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('activity'));
    }

    public function test_a_movement_reads_as_a_sentence_on_the_page(): void
    {
        $team = $this->teamMember(['name' => 'Vikas Jamariya']);
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $team);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->assertSee('Vikas Jamariya')
            ->assertSee('moved '.$ticket->key().' from Backlog to In Progress')
            // Board context, and the from → new chips.
            ->assertSee($board->name)
            ->assertSee('In Progress');
    }

    public function test_rows_are_grouped_under_a_day_heading(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->assertSee('Today')
            ->assertSee('Just now');
    }

    public function test_older_activity_is_grouped_under_its_date(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        Activity::query()->update([
            'created_at' => now()->subDay()->setTime(9, 30),
        ]);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->assertSee('Yesterday');
    }

    public function test_the_newest_activity_comes_first(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $first = $this->ticketOn($board, $team, ['title' => 'Older ticket']);
        $second = $this->ticketOn($board, $team, ['title' => 'Newer ticket']);

        $ids = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->viewData('activities')
            ->pluck('subject_id')
            ->all();

        $this->assertSame(
            [(int) $second->getKey(), (int) $first->getKey()],
            array_map('intval', $ids)
        );
    }

    // -----------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------

    public function test_search_finds_activity_by_ticket_key(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $wanted = $this->ticketOn($board, $team, ['title' => 'Wanted']);
        $this->ticketOn($board, $team, ['title' => 'Unwanted']);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', $wanted->key())
            ->assertSee('Wanted')
            ->assertDontSee('Unwanted');
    }

    public function test_search_finds_activity_by_ticket_title(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Safari login breaks']);
        $this->ticketOn($board, $team, ['title' => 'Invoice rounding']);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', 'Safari')
            ->assertSee('Safari login breaks')
            ->assertDontSee('Invoice rounding');
    }

    public function test_search_finds_activity_by_the_person_who_did_it(): void
    {
        $vikas = $this->teamMember(['name' => 'Vikas Jamariya']);
        $alex = $this->teamMember(['name' => 'Alex Smith']);
        $board = $this->boardWithColumns([$vikas, $alex]);

        $this->ticketOn($board, $vikas, ['title' => 'Raised by Vikas']);
        $this->ticketOn($board, $alex, ['title' => 'Raised by Alex']);

        Livewire::actingAs($vikas)
            ->test(ActivityIndex::class)
            ->set('search', 'Alex')
            ->assertSee('Raised by Alex')
            ->assertDontSee('Raised by Vikas');
    }

    public function test_search_finds_activity_by_board_name(): void
    {
        $team = $this->teamMember();
        $wedding = $this->boardWithColumns([$team], ['name' => 'Wedding Invitations']);
        $platform = $this->boardWithColumns([$team], ['name' => 'Platform']);

        $this->ticketOn($wedding, $team, ['title' => 'Choose a font']);
        $this->ticketOn($platform, $team, ['title' => 'Upgrade the queue']);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', 'Wedding')
            ->assertSee('Choose a font')
            ->assertDontSee('Upgrade the queue');
    }

    public function test_search_finds_activity_by_description(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Anything']);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', 'from Backlog to Done');

        $this->assertSame(1, $component->viewData('activities')->total());
    }

    public function test_a_search_that_matches_nothing_says_so_and_offers_a_way_out(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', 'zzz-nothing-matches-this')
            ->assertSee('No activity matches')
            ->assertSee('Clear filters');
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function test_the_board_filter_narrows_to_one_board(): void
    {
        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['name' => 'Board One']);
        $two = $this->boardWithColumns([$team], ['name' => 'Board Two']);

        $this->ticketOn($one, $team, ['title' => 'On board one']);
        $this->ticketOn($two, $team, ['title' => 'On board two']);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('boardSlug', $one->slug)
            ->assertSee('On board one')
            ->assertDontSee('On board two');
    }

    public function test_a_board_slug_the_viewer_cannot_reach_yields_nothing_rather_than_everything(): void
    {
        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team], ['name' => 'Mine']);
        $theirs = $this->boardWithColumns([$this->teamMember()], ['name' => 'Theirs']);

        $this->ticketOn($mine, $team, ['title' => 'My ticket']);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('boardSlug', $theirs->slug);

        // Not "every board" — nothing. And identical to a slug that never
        // existed, so the filter cannot be used to discover boards.
        $this->assertSame(0, $component->viewData('activities')->total());

        $component->set('boardSlug', 'no-such-board-anywhere');
        $this->assertSame(0, $component->viewData('activities')->total());
    }

    public function test_the_type_filter_narrows_by_category(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'A ticket']);
        $this->docPageOn($board, $team, ['title' => 'A page']);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('type', ActivityCategory::Documentation->value)
            ->assertSee('A page')
            ->assertDontSee($ticket->key());
    }

    public function test_the_type_filter_narrows_by_a_single_type(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);
        app(UpdateTicket::class)->handle($ticket, ['priority' => TicketPriority::Critical->value], $team);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('type', ActivityType::TicketMoved->value);

        $events = $component->viewData('activities')->pluck('event')->unique()->all();

        $this->assertSame([ActivityType::TicketMoved->value], array_values($events));
    }

    public function test_an_unrecognised_type_narrows_to_nothing_rather_than_widening(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('type', 'not-a-real-type');

        // The value is discarded by ActivityFilters, so the feed is unfiltered
        // rather than narrowed to a nonsense value — and, crucially, the badge
        // does not claim a filter is active.
        $this->assertSame('', $component->viewData('filters')->type);
        $this->assertTrue($component->viewData('filters')->isEmpty());
    }

    public function test_the_person_filter_narrows_to_one_user(): void
    {
        $vikas = $this->teamMember(['name' => 'Vikas Jamariya']);
        $alex = $this->teamMember(['name' => 'Alex Smith']);
        $board = $this->boardWithColumns([$vikas, $alex]);

        $this->ticketOn($board, $vikas, ['title' => 'By Vikas']);
        $this->ticketOn($board, $alex, ['title' => 'By Alex']);

        Livewire::actingAs($vikas)
            ->test(ActivityIndex::class)
            ->set('userId', (string) $alex->getKey())
            ->assertSee('By Alex')
            ->assertDontSee('By Vikas');
    }

    public function test_the_person_filter_only_offers_people_from_readable_boards(): void
    {
        $team = $this->teamMember(['name' => 'Mine Person']);
        $stranger = $this->teamMember(['name' => 'Stranger Person']);

        $mine = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$stranger]);

        $this->ticketOn($mine, $team);
        $this->ticketOn($theirs, $stranger);

        $options = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->viewData('actorOptions');

        $this->assertContains('Mine Person', $options);
        $this->assertNotContains('Stranger Person', $options);
    }

    public function test_the_date_range_filter_narrows_by_period(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['title' => 'Recent work']);

        // Push one row well outside the last seven days.
        $old = $this->ticketOn($board, $team, ['title' => 'Ancient work']);
        Activity::query()
            ->where('subject_id', $old->getKey())
            ->update(['created_at' => now()->subMonths(3)]);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('range', StatsPeriod::LAST_7_DAYS);

        $component->assertSee('Recent work')->assertDontSee('Ancient work');

        $component->set('range', ActivityFilters::ALL_TIME);
        $this->assertSame(2, $component->viewData('activities')->total());
    }

    public function test_leaving_the_custom_range_clears_its_dates(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('range', StatsPeriod::CUSTOM)
            ->set('customFrom', '2026-01-01')
            ->set('customTo', '2026-02-01')
            ->set('range', StatsPeriod::LAST_7_DAYS)
            ->assertSet('customFrom', '')
            ->assertSet('customTo', '');
    }

    public function test_clearing_filters_restores_the_full_feed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Everything']);

        $component = Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('search', 'zzz')
            ->set('type', ActivityCategory::Boards->value);

        $this->assertSame(0, $component->viewData('activities')->total());

        $component->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('type', '')
            ->assertSee('Everything');
    }

    public function test_filters_compose_rather_than_replace_one_another(): void
    {
        $vikas = $this->teamMember(['name' => 'Vikas Jamariya']);
        $alex = $this->teamMember(['name' => 'Alex Smith']);
        $board = $this->boardWithColumns([$vikas, $alex]);

        $mine = $this->ticketOn($board, $vikas);
        $theirs = $this->ticketOn($board, $alex);

        app(MoveTicket::class)->handle($mine, $this->columnNamed($board, 'Done'), 0, $vikas);
        app(MoveTicket::class)->handle($theirs, $this->columnNamed($board, 'Done'), 0, $alex);

        $component = Livewire::actingAs($vikas)
            ->test(ActivityIndex::class)
            ->set('boardSlug', $board->slug)
            ->set('type', ActivityType::TicketMoved->value)
            ->set('userId', (string) $alex->getKey());

        $rows = $component->viewData('activities');

        $this->assertSame(1, $rows->total());
        $this->assertSame((int) $theirs->getKey(), (int) $rows->first()->subject_id);
        $this->assertSame(3, $component->viewData('filters')->activeCount());
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    public function test_the_feed_is_paginated_rather_than_loaded_whole(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        for ($i = 0; $i < 30; $i++) {
            $this->ticketOn($board, $team, ['title' => 'Ticket number '.$i]);
        }

        $component = Livewire::actingAs($team)->test(ActivityIndex::class);

        $page = $component->viewData('activities');

        $this->assertSame(30, $page->total());
        $this->assertSame(25, $page->perPage());
        $this->assertCount(25, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    public function test_the_second_page_holds_the_rest_and_repeats_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        for ($i = 0; $i < 30; $i++) {
            $this->ticketOn($board, $team, ['title' => 'Ticket number '.$i]);
        }

        $component = Livewire::actingAs($team)->test(ActivityIndex::class);

        $firstPageIds = collect($component->viewData('activities')->items())->pluck('id')->all();

        $component->set('paginators.page', 2);

        $secondPageIds = collect($component->viewData('activities')->items())->pluck('id')->all();

        $this->assertCount(5, $secondPageIds);
        $this->assertSame([], array_intersect($firstPageIds, $secondPageIds));
    }

    public function test_changing_a_filter_returns_to_the_first_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        for ($i = 0; $i < 30; $i++) {
            $this->ticketOn($board, $team, ['title' => 'Ticket number '.$i]);
        }

        Livewire::actingAs($team)
            ->test(ActivityIndex::class)
            ->set('paginators.page', 2)
            ->set('search', 'Ticket')
            ->assertSet('paginators.page', 1);
    }

    // -----------------------------------------------------------------
    // Realtime
    // -----------------------------------------------------------------

    public function test_no_realtime_subscription_is_opened_for_a_cross_board_feed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        // Switched on only now that the fixtures are written: creating a ticket
        // dispatches BoardUpdated, and with a real driver selected that would
        // try to reach a websocket server this test has no interest in. All the
        // component asks of the config is App\Support\Broadcasting::enabled().
        config(['broadcasting.default' => 'reverb']);

        $unfiltered = Livewire::actingAs($team)->test(ActivityIndex::class);
        $this->assertSame([], $this->listenersOf($unfiltered));

        // Narrowed to one board, it reuses that board's existing internal
        // channel rather than adding anything of its own.
        $filtered = Livewire::actingAs($team)
            ->test(ActivityIndex::class, ['boardSlug' => $board->slug])
            ->set('boardSlug', $board->slug);

        $this->assertArrayHasKey(
            'echo-private:board.'.$board->getKey().'.internal,.board.updated',
            $this->listenersOf($filtered)
        );
    }

    /**
     * @return array<string, string>
     */
    private function listenersOf($component): array
    {
        $instance = $component->instance();

        $listeners = (new \ReflectionMethod($instance, 'getListeners'));
        $listeners->setAccessible(true);

        return $listeners->invoke($instance);
    }
}
