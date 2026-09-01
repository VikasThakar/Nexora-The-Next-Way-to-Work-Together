<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Actions\Tickets\MoveTicket;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\TeamStatistics;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Flow metrics, and the property that makes them worth having: they are
 * computed from the event history, not from where cards happen to sit today.
 *
 * Several tests below deliberately construct a history that *disagrees* with
 * the current state — a ticket finished and reopened, a ticket moved into done
 * before the reporting window — because those are exactly the cases a
 * current-state calculation gets wrong while still returning a plausible number.
 */
class FlowMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_time_is_measured_from_creation_to_the_first_arrival_in_done(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        Carbon::setTestNow('2026-06-04 09:00:00');
        $this->moveTo($ticket, $board, 'Done', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $summary = app(FlowMetrics::class)->cycleTime($this->scope($team));

        $this->assertSame(1, $summary->count);
        // Three days, to the hour.
        $this->assertSame(72.0, $summary->medianHours);
        $this->assertSame('3 d', $summary->medianLabel());
    }

    public function test_a_ticket_that_was_reopened_is_measured_from_its_first_completion(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        Carbon::setTestNow('2026-06-02 09:00:00');
        $this->moveTo($ticket, $board, 'Done', $team);

        // Reopened and finished again, later in the same period.
        Carbon::setTestNow('2026-06-05 09:00:00');
        $this->moveTo($ticket, $board, 'In Progress', $team);

        Carbon::setTestNow('2026-06-09 09:00:00');
        $this->moveTo($ticket, $board, 'Done', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $flow = app(FlowMetrics::class);
        $scope = $this->scope($team);

        // One ticket, not two: the same ticket reaching done twice is one
        // closure, counted the first time it got there.
        $this->assertSame(1, $flow->closures($scope)->count());
        $this->assertSame(24.0, $flow->cycleTime($scope)->medianHours);
    }

    public function test_throughput_counts_the_history_not_the_current_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $finished = $this->ticketOn($board, $team, ['title' => 'Finished and reopened']);

        Carbon::setTestNow('2026-06-03 09:00:00');
        $this->moveTo($finished, $board, 'Done', $team);

        // Pulled back out again. Its current column is not Done, but it was
        // genuinely completed during the period and the team's throughput
        // should say so.
        Carbon::setTestNow('2026-06-04 09:00:00');
        $this->moveTo($finished, $board, 'In Progress', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $scope = $this->scope($team);

        $this->assertSame(1, app(FlowMetrics::class)->closures($scope)->count());

        // …and the current-state count disagrees, which is the whole point.
        $this->assertSame(0, app(TeamStatistics::class)->counts($scope)['closed']);
    }

    public function test_a_ticket_closed_before_the_period_is_not_counted_in_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-01-05 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        Carbon::setTestNow('2026-01-06 09:00:00');
        $this->moveTo($ticket, $board, 'Done', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        // Last 30 days: January is long gone.
        $this->assertSame(0, app(FlowMetrics::class)->closures($this->scope($team))->count());
    }

    public function test_a_ticket_created_before_the_period_still_reports_its_full_cycle_time(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // Raised months before the reporting window opens.
        Carbon::setTestNow('2026-03-01 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        Carbon::setTestNow('2026-06-05 09:00:00');
        $this->moveTo($ticket, $board, 'Done', $team);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $summary = app(FlowMetrics::class)->cycleTime($this->scope($team));

        // The measurement is not clipped to the window: the ticket really did
        // take 96 days, and reporting 5 would flatter the team enormously.
        $this->assertSame(1, $summary->count);
        $this->assertEqualsWithDelta(96 * 24, $summary->medianHours, 24.0);
    }

    public function test_weekly_throughput_includes_quiet_weeks_as_zero(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-10 12:00:00');

        $series = app(FlowMetrics::class)->throughputByWeek($this->scope($team));

        // Roughly five Monday-to-Sunday buckets cover 30 days.
        $this->assertGreaterThanOrEqual(4, count($series));

        foreach ($series as $week) {
            // A gap in the chart would compress the timeline and make a stalled
            // month look busy.
            $this->assertSame(0, $week['value']);
            $this->assertArrayHasKey('label', $week);
        }
    }

    public function test_time_in_column_measures_completed_stays_only(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        // Two days in Backlog, then one day in To Do, then it stops.
        Carbon::setTestNow('2026-06-03 09:00:00');
        $this->moveTo($ticket, $board, 'To Do', $team);

        Carbon::setTestNow('2026-06-04 09:00:00');
        $this->moveTo($ticket, $board, 'In Progress', $team);

        Carbon::setTestNow('2026-06-20 12:00:00');

        $rows = collect(app(FlowMetrics::class)->timeInColumn($this->scope($team)))
            ->keyBy('column');

        $this->assertSame(48.0, $rows['Backlog']['summary']->averageHours);
        $this->assertSame(24.0, $rows['To Do']['summary']->averageHours);

        // The ticket is still in In Progress. That stay has not ended, so it is
        // not measured — counting "so far" would make a healthy board look
        // worse the longer it stayed healthy.
        $this->assertArrayNotHasKey('In Progress', $rows->all());
    }

    public function test_a_move_records_both_column_ids_on_the_event(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $backlog = $this->columnNamed($board, 'Backlog');
        $done = $this->columnNamed($board, 'Done');

        $this->moveTo($ticket, $board, 'Done', $team);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'ticket_moved')
            ->sole();

        // Promoted out of the JSON payload into indexed columns, which is what
        // makes the flow queries index scans rather than table scans.
        $this->assertSame($backlog->id, $event->from_column_id);
        $this->assertSame($done->id, $event->to_column_id);

        // And the payload still carries the names, so a history written before
        // a column was renamed still reads correctly.
        $this->assertSame('Backlog', $event->payload['from_column']);
        $this->assertSame('Done', $event->payload['to_column']);
    }

    public function test_ticket_creation_is_recorded_as_arrival_in_the_first_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'ticket_created')
            ->sole();

        // Without this the first interval has no beginning and the time a
        // ticket spends in the backlog is invisible.
        $this->assertSame($this->columnNamed($board, 'Backlog')->id, $event->to_column_id);
        $this->assertNull($event->from_column_id);
    }

    public function test_a_board_whose_done_column_was_renamed_still_reports_closures(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // The flag decides, not the name.
        $done = $this->columnNamed($board, 'Done');
        $done->update(['name' => 'Shipped']);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $ticket = $this->ticketOn($board, $team);

        Carbon::setTestNow('2026-06-02 09:00:00');
        app(MoveTicket::class)->handle($ticket, $done->refresh(), 0, $team);

        Carbon::setTestNow('2026-06-05 12:00:00');

        $this->assertSame(1, app(FlowMetrics::class)->closures($this->scope($team))->count());
    }

    public function test_metrics_are_empty_rather_than_wrong_for_a_board_with_no_done_column(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $board->columns()->update(['is_done' => false]);

        $ticket = $this->ticketOn($board, $team);
        $this->moveTo($ticket, $board, 'Done', $team);

        $flow = app(FlowMetrics::class);
        $scope = $this->scope($team);

        $this->assertSame(0, $flow->closures($scope)->count());
        $this->assertTrue($flow->cycleTime($scope)->isEmpty());
        $this->assertSame('—', $flow->cycleTime($scope)->medianLabel());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------

    private function scope(User $viewer, ?Board $board = null): StatisticsScope
    {
        return StatisticsScope::for($viewer, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board);
    }

    private function moveTo(Ticket $ticket, Board $board, string $column, User $actor): void
    {
        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, $column), 0, $actor);
    }
}
