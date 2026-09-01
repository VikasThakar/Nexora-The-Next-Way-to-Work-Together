<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Livewire\Stats\Customer as CustomerStats;
use App\Livewire\Stats\Team as TeamStats;
use App\Models\AiRun;
use App\Services\Statistics\AiStatistics;
use App\Services\Statistics\CustomerStatistics;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\TeamStatistics;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customer boundary, as it applies to reporting.
 *
 * A statistics screen is a uniquely dangerous place to get visibility wrong. On
 * a board, a leaked internal ticket is a visible row somebody would notice in
 * review; in a report it is +1 on a number, and there is no assertion about the
 * page's HTML that would ever catch it. These tests count.
 */
class StatisticsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_open_the_team_statistics_screen(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $this->boardWithColumns([$team, $customer]);

        $this->actingAs($customer)
            ->get(route('stats'))
            ->assertForbidden();
    }

    public function test_a_customer_mounting_the_team_component_directly_is_refused(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $this->boardWithColumns([$team, $customer]);

        // The route middleware is the first gate; this is the second. A
        // Livewire request can reach a component without passing through the
        // route it was declared on.
        Livewire::actingAs($customer)
            ->test(TeamStats::class)
            ->assertNotFound();
    }

    public function test_internal_tickets_are_absent_from_every_customer_figure(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $customer, ['title' => 'Theirs']);

        for ($i = 0; $i < 7; $i++) {
            $this->ticketOn($board, $team, ['title' => 'Internal '.$i]);
        }

        $scope = $this->scope($customer);
        $statistics = app(CustomerStatistics::class);

        $counts = $statistics->counts($scope);

        // One, not eight. The number is the assertion: a leak here has no
        // visible symptom at all.
        $this->assertSame(1, $counts['total']);
        $this->assertSame(1, $counts['open']);
        $this->assertSame(1, $counts['created']);

        $this->assertSame(1, array_sum(array_column($statistics->byPriority($scope), 'value')));
        $this->assertSame(1, array_sum(array_column($statistics->statusSplit($scope), 'value')));
        $this->assertSame(1, array_sum(array_column($statistics->createdByWeek($scope), 'value')));
        $this->assertCount(1, $statistics->recentTickets($scope));
    }

    public function test_a_customer_never_receives_ai_figures(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create([
            'tokens_input' => 5000,
            'tokens_output' => 2000,
            'estimated_cost' => '1.250000',
        ]);

        $ai = app(AiStatistics::class);

        // AiRun::visibleTo refuses customers outright rather than filtering, so
        // the aggregate is genuinely empty rather than a filtered subset.
        $summary = $ai->summary($this->scope($customer));

        $this->assertSame(0, $summary['runs']);
        $this->assertSame(0, $summary['input_tokens']);
        $this->assertSame(0.0, $summary['known_cost']);
        $this->assertSame([], $ai->byBoard($this->scope($customer)));

        // …and the same query, asked by staff, does return the run — proving
        // the zero above is the boundary working, not an empty database.
        $this->assertSame(1, $ai->summary($this->scope($team))['runs']);
    }

    public function test_the_customer_screen_renders_no_ai_or_flow_wording(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        AiRun::factory()->forTicket($ticket)->manual($team)->completed()->create();

        Livewire::actingAs($customer)
            ->test(CustomerStats::class)
            ->assertOk()
            ->assertDontSee('AI activity')
            ->assertDontSee('Estimated cost')
            ->assertDontSee('Cycle time')
            ->assertDontSee('Throughput')
            ->assertDontSee('Internal');
    }

    public function test_a_customer_cannot_reach_another_boards_figures_through_the_filter(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();

        $theirs = $this->boardWithColumns([$team, $customer]);
        $other = $this->boardWithColumns([$team], ['name' => 'Another customer']);

        $this->ticketOn($theirs, $customer);
        $this->ticketOn($other, $team, ['customer_visible' => true, 'title' => 'Not for them']);
        $this->ticketOn($other, $team, ['customer_visible' => true, 'title' => 'Also not']);

        Livewire::actingAs($customer)
            ->test(CustomerStats::class)
            ->set('boardSlug', $other->slug)
            ->assertOk()
            ->assertDontSee('Not for them')
            ->assertSee('Nothing shared with you yet');
    }

    public function test_the_board_filter_only_offers_boards_the_viewer_belongs_to(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();

        $theirs = $this->boardWithColumns([$team, $customer], ['name' => 'Their project']);
        $other = $this->boardWithColumns([$team], ['name' => 'Somebody elses project']);

        Livewire::actingAs($customer)
            ->test(CustomerStats::class)
            ->assertOk()
            ->assertSee('Their project')
            // A dropdown that lists every board is an enumeration of the
            // workspace's customers.
            ->assertDontSee('Somebody elses project');

        $this->assertNotSame($theirs->slug, $other->slug);
    }

    public function test_an_administrator_sees_boards_they_are_not_a_member_of(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team);
        $this->ticketOn($board, $team);

        // The administrator bypass is part of BoardAccess, and the statistics
        // scope inherits it rather than re-deriving it.
        $this->assertSame(
            2,
            app(TeamStatistics::class)->counts($this->scope($admin))['total']
        );
    }

    public function test_a_deactivated_user_gets_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $team->forceFill(['deactivated_at' => now()])->save();

        $scope = $this->scope($team->refresh());

        $this->assertTrue($scope->isEmpty());
        $this->assertSame(0, app(TeamStatistics::class)->counts($scope)['total']);
    }

    public function test_statistics_are_unreachable_without_signing_in(): void
    {
        $this->get(route('stats'))->assertRedirect(route('login'));
        $this->get(route('stats.customer'))->assertRedirect(route('login'));
    }

    private function scope($viewer, $board = null): StatisticsScope
    {
        return StatisticsScope::for($viewer, StatsPeriod::preset(StatsPeriod::LAST_30_DAYS, 'UTC'), $board);
    }
}
