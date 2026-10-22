<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\MoveTicketToTab;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Services\NotificationReader;
use App\Services\Search\GlobalSearch;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\TeamStatistics;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Tab › Column" wherever a ticket's place is named — and only on a board
 * split into tabs, so every other board reads exactly as before.
 */
class TabLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_name_the_tab_and_keep_tabs_in_order(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');

        $this->ticketOn($board, $team);
        $this->ticketOn($board, $team, ['board_tab_id' => $website->id]);

        $labels = array_column(
            app(TeamStatistics::class)->byColumn(StatisticsScope::for($team, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board)),
            'label',
        );

        $this->assertSame(['Main › Backlog', 'Website › Backlog'], $labels);
    }

    public function test_statistics_on_a_board_with_one_tab_are_unchanged(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $labels = array_column(
            app(TeamStatistics::class)->byColumn(StatisticsScope::for($team, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board)),
            'label',
        );

        $this->assertSame(['Backlog'], $labels);
    }

    public function test_the_bell_says_which_tab_a_ticket_moved_to(): void
    {
        $team = $this->teamMember();
        $assignee = $this->teamMember();
        $board = $this->boardWithColumns([$team, $assignee]);
        $website = $this->tabOn($board, 'Website');

        $ticket = $this->ticketOn($board, $team, ['board_tab_id' => $website->id, 'assignee_id' => $assignee->id]);

        // The notification names whoever is signed in as the one who moved it.
        $this->actingAs($team);

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done', $website), 0, $team);

        $messages = app(NotificationReader::class)->items($assignee)->pluck('message')->all();

        $this->assertContains($team->name.' moved '.$ticket->key().' to Website › Done', $messages);
    }

    public function test_search_hints_name_the_board_and_the_tab(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Gritch']);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team, ['title' => 'Hero image is blurry', 'board_tab_id' => $website->id]);

        $hits = collect(collect(app(GlobalSearch::class)->search($team, 'blurry'))->firstWhere('key', 'tickets')['hits'] ?? []);

        $this->assertSame('Gritch · Website', $hits->firstWhere('key', $ticket->key())?->hint);
    }

    public function test_the_feed_names_both_tabs_of_a_move_between_them(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $website = $this->tabOn($board, 'Website');
        $ticket = $this->ticketOn($board, $team);

        app(MoveTicketToTab::class)->handle($ticket, $website, $team);

        $activity = Activity::query()->where('event', ActivityType::TicketMoved->value)->sole();

        $this->assertSame('moved '.$ticket->key().' from Main › Backlog to Website › Backlog', $activity->description);
    }
}
