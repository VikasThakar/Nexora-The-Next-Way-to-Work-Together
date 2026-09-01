<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Actions\Tickets\MoveTicket;
use App\Enums\TicketPriority;
use App\Livewire\Stats\Team;
use App\Models\AiRun;
use App\Services\Statistics\AiStatistics;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\TeamStatistics;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counts_split_active_from_closed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $open = $this->ticketOn($board, $team, ['title' => 'Open']);
        $this->ticketOn($board, $team, ['title' => 'Also open']);
        $closed = $this->ticketOn($board, $team, ['title' => 'Closed']);

        app(MoveTicket::class)->handle($closed, $this->columnNamed($board, 'Done'), 0, $team);

        $counts = app(TeamStatistics::class)->counts($this->scope($team));

        $this->assertSame(3, $counts['total']);
        $this->assertSame(2, $counts['active']);
        $this->assertSame(1, $counts['closed']);
        $this->assertSame(3, $counts['created']);
        $this->assertSame($open->id, $open->id);
    }

    public function test_every_priority_appears_even_at_zero(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        $rows = collect(app(TeamStatistics::class)->byPriority($this->scope($team)))->keyBy('label');

        // "No critical tickets" is information; an absent row reads as an
        // oversight.
        $this->assertCount(count(TicketPriority::cases()), $rows);
        $this->assertSame(1, $rows['Critical']['value']);
        $this->assertSame(0, $rows['Low']['value']);
    }

    public function test_the_assignee_breakdown_covers_open_work_and_names_unassigned(): void
    {
        $team = $this->teamMember(['name' => 'Grace']);
        $other = $this->teamMember(['name' => 'Ada']);
        $board = $this->boardWithColumns([$team, $other]);

        $this->ticketOn($board, $team, ['assignee_id' => $team->id]);
        $this->ticketOn($board, $team, ['assignee_id' => $team->id]);
        $this->ticketOn($board, $team, ['assignee_id' => $other->id]);
        $this->ticketOn($board, $team);

        $done = $this->ticketOn($board, $team, ['assignee_id' => $team->id]);
        app(MoveTicket::class)->handle($done, $this->columnNamed($board, 'Done'), 0, $team);

        $rows = collect(app(TeamStatistics::class)->byAssignee($this->scope($team)))->keyBy('label');

        // Finished work is excluded: this is a workload view, not a leaderboard.
        $this->assertSame(2, $rows['Grace']['value']);
        $this->assertSame(1, $rows['Ada']['value']);
        $this->assertSame(1, $rows['Unassigned']['value']);
    }

    public function test_the_board_filter_narrows_the_report(): void
    {
        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['name' => 'Alpha']);
        $two = $this->boardWithColumns([$team], ['name' => 'Beta']);

        $this->ticketOn($one, $team);
        $this->ticketOn($two, $team);
        $this->ticketOn($two, $team);

        $stats = app(TeamStatistics::class);

        $this->assertSame(3, $stats->counts($this->scope($team))['total']);
        $this->assertSame(1, $stats->counts($this->scope($team, $one))['total']);
        $this->assertSame(2, $stats->counts($this->scope($team, $two))['total']);
    }

    public function test_a_board_the_viewer_cannot_reach_is_not_counted(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();

        $mine = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$stranger]);

        $this->ticketOn($mine, $team);
        $this->ticketOn($theirs, $stranger);
        $this->ticketOn($theirs, $stranger);

        // Every query starts from the ordinary visibility scope, so a board the
        // viewer is not a member of contributes nothing.
        $this->assertSame(1, app(TeamStatistics::class)->counts($this->scope($team))['total']);
    }

    public function test_a_board_slug_typed_into_the_filter_cannot_widen_the_report(): void
    {
        $team = $this->teamMember();
        $stranger = $this->teamMember();

        $mine = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$stranger], ['name' => 'Not yours']);

        $this->ticketOn($mine, $team);
        $this->ticketOn($theirs, $stranger);

        // Asking for somebody else's board by slug produces an empty scope, not
        // their numbers.
        Livewire::actingAs($team)
            ->test(Team::class)
            ->set('boardSlug', $theirs->slug)
            ->assertOk()
            ->assertSee('No boards to report on');
    }

    public function test_the_ai_panel_reports_unpriced_runs_separately(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create([
            'tokens_input' => 1000,
            'tokens_output' => 500,
            'estimated_cost' => '0.500000',
            'model' => 'claude-opus-5',
        ]);

        // A run whose usage the provider never reported.
        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create([
            'tokens_input' => null,
            'tokens_output' => null,
            'estimated_cost' => null,
        ]);

        $summary = app(AiStatistics::class)->summary($this->scope($team));

        $this->assertSame(2, $summary['runs']);
        $this->assertSame(0.5, $summary['known_cost']);
        // Counted, never folded in as zero — the real total is higher than the
        // figure shown, and the screen says so.
        $this->assertSame(1, $summary['unpriced_runs']);
    }

    public function test_the_success_rate_is_undefined_rather_than_perfect_when_nothing_has_finished(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        AiRun::factory()->forTicket($ticket)->manual($team)->create();

        $summary = app(AiStatistics::class)->summary($this->scope($team));

        $this->assertSame(1, $summary['runs']);
        $this->assertNull($summary['success_rate']);
    }

    public function test_the_screen_renders_the_headline_figures(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['name' => 'Aqueduct Platform']);
        $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(Team::class)
            ->assertOk()
            ->assertSee('Total tickets')
            ->assertSee('Median cycle time')
            ->assertSee('Weekly throughput')
            ->assertSee('Customer visibility');
    }

    public function test_a_custom_range_is_clamped_rather_than_rejected(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        // Reversed, and far longer than the ceiling. Both are corrected.
        Livewire::actingAs($team)
            ->test(Team::class)
            ->set('range', StatsPeriod::CUSTOM)
            ->set('customFrom', '2030-01-01')
            ->set('customTo', '1990-01-01')
            ->assertOk()
            ->assertHasNoErrors();
    }

    private function scope($viewer, $board = null): StatisticsScope
    {
        return StatisticsScope::for($viewer, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board);
    }
}
