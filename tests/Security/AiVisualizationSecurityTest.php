<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\AiCapabilityMode;
use App\Enums\TicketPriority;
use App\Livewire\Ai\Assistant;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\AI\Tools\GetStatisticsTool;
use App\Services\BoardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The visualisation boundary.
 *
 * An aggregate is the most dangerous shape a leak can take, which is why this
 * has a suite of its own. A count does not look like a disclosure: nobody reads
 * "17" and thinks about which tickets it counted, no page assertion catches it,
 * and a customer shown a total that includes internal work has been told
 * something about work they are not allowed to see — from which, given two such
 * totals, they can often recover the individual figure.
 *
 * So the claim under test is stronger than "the chart is right". It is that the
 * authorization happens in SQL *before* the aggregation, which is what
 * App\Services\Statistics\StatisticsScope exists to guarantee, and that a
 * visualisation request can never become a write.
 *
 * Every test runs under AI Agent, the most permissive capability mode in the
 * product, because the claim is that the mode grants nobody a figure they could
 * not already see.
 */
class AiVisualizationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMode(AiCapabilityMode::Agent);
    }

    private function context(User $user, ?Board $board = null): AiToolContext
    {
        return new AiToolContext(
            user: $user,
            scope: $board instanceof Board ? AiContextScope::board($board) : AiContextScope::workspace(),
            session: AiSession::factory()->for($user)->create(),
            mode: AiCapabilityMode::Agent,
            staff: app(BoardAccess::class)->canSeeInternalContent($user),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ask(array $input, User $user, ?Board $board = null): string
    {
        return app(GetStatisticsTool::class)->handle($input, $this->context($user, $board))->text;
    }

    // -----------------------------------------------------------------
    // A customer's totals count only what a customer can open
    // -----------------------------------------------------------------

    public function test_a_customers_totals_exclude_internal_tickets(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        // Two shared with the customer, five internal.
        $this->ticketOn($board, $staff, ['customer_visible' => true]);
        $this->ticketOn($board, $staff, ['customer_visible' => true]);

        foreach (range(1, 5) as $ignored) {
            $this->ticketOn($board, $staff, ['customer_visible' => false]);
        }

        $customerText = $this->ask(['metric' => 'by_status'], $customer, $board);
        $staffText = $this->ask(['metric' => 'by_status'], $staff, $board);

        // The customer's total is 2, not 7 and not "7 minus the internal ones"
        // computed afterwards — the internal rows never entered the count.
        $this->assertStringContainsString('(total 2)', $customerText);
        $this->assertStringContainsString('(total 7)', $staffText);

        // And nowhere does the customer's answer carry the real figure, from
        // which the hidden count could be recovered by subtraction.
        $this->assertStringNotContainsString('7', $customerText);
    }

    public function test_a_customer_cannot_aggregate_a_board_they_are_not_on(): void
    {
        $customer = $this->customer();
        $theirs = $this->boardWithColumns([$customer]);

        $staff = $this->teamMember();
        $other = $this->boardWithColumns([$staff], ['slug' => 'other-client']);

        foreach (range(1, 9) as $ignored) {
            $this->ticketOn($other, $staff, ['customer_visible' => true]);
        }

        $text = $this->ask(['metric' => 'by_status', 'board' => 'other-client'], $customer, $theirs);

        // Indistinguishable from a board that does not exist, and empty rather
        // than quietly falling back to their own board's numbers under the
        // other board's name.
        $this->assertStringContainsString('not one this person can see', $text);
        $this->assertStringNotContainsString('(total 9)', $text);
    }

    /**
     * The workspace scope is not a way round the board scope.
     *
     * Asking with no board reports across "every board this person can see",
     * and that phrase has to mean what it says.
     */
    public function test_a_workspace_wide_request_still_only_spans_reachable_boards(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();

        $theirs = $this->boardWithColumns([$customer, $staff]);
        $other = $this->boardWithColumns([$staff], ['slug' => 'not-theirs']);

        $this->ticketOn($theirs, $staff, ['customer_visible' => true]);

        foreach (range(1, 6) as $ignored) {
            $this->ticketOn($other, $staff, ['customer_visible' => true]);
        }

        $text = $this->ask(['metric' => 'by_status'], $customer);

        $this->assertStringContainsString('(total 1)', $text);
        $this->assertStringNotContainsString('not-theirs', $text);
    }

    // -----------------------------------------------------------------
    // Staff-only metrics do not exist for a customer
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_ask_for_the_team_workload(): void
    {
        $customer = $this->customer();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$customer, $alex]);

        $this->ticketOn($board, $alex, ['assignee_id' => $alex->id, 'customer_visible' => true]);

        $outcome = app(GetStatisticsTool::class)
            ->handle(['metric' => 'by_assignee'], $this->context($customer, $board));

        $this->assertFalse($outcome->success);
        // Refused, and no colleague is named in the refusal.
        $this->assertStringNotContainsString('Alex Round', $outcome->text);
    }

    public function test_a_customer_cannot_ask_how_much_is_hidden_from_them(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $this->ticketOn($board, $staff, ['customer_visible' => true]);
        $this->ticketOn($board, $staff, ['customer_visible' => false]);
        $this->ticketOn($board, $staff, ['customer_visible' => false]);

        $outcome = app(GetStatisticsTool::class)
            ->handle(['metric' => 'visibility_split'], $this->context($customer, $board));

        $this->assertFalse($outcome->success);

        // The internal count is the exact figure this metric would disclose,
        // so it must not appear even incidentally in the refusal.
        $this->assertStringNotContainsString('Internal', $outcome->text);
    }

    public function test_a_customer_cannot_ask_for_the_label_breakdown(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $outcome = app(GetStatisticsTool::class)
            ->handle(['metric' => 'by_label'], $this->context($customer, $board));

        $this->assertFalse($outcome->success);
    }

    /**
     * The metrics a customer IS offered still work.
     *
     * A boundary that refuses everything is not a boundary, it is an outage —
     * and the brief is explicit that a customer may chart what they can read.
     */
    public function test_a_customer_can_chart_the_metrics_they_are_allowed(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $this->ticketOn($board, $staff, [
            'customer_visible' => true,
            'priority' => TicketPriority::Critical,
        ]);

        foreach (['totals', 'by_status', 'by_priority', 'created_by_week', 'completed_by_week'] as $metric) {
            $outcome = app(GetStatisticsTool::class)
                ->handle(['metric' => $metric], $this->context($customer, $board));

            $this->assertTrue(
                $outcome->success,
                "A customer was refused '{$metric}', which they are allowed to see."
            );
        }
    }

    /**
     * A customer's status chart is open-versus-closed, not the team's workflow.
     *
     * A product decision CustomerStatistics already made, and the tool honours
     * it rather than reaching around it to the team version.
     */
    public function test_a_customers_status_chart_does_not_expose_the_internal_workflow(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $this->ticketOn($board, $staff, ['customer_visible' => true]);

        $text = $this->ask(['metric' => 'by_status'], $customer, $board);

        $this->assertStringContainsString('Open', $text);
        $this->assertStringContainsString('Closed', $text);
        $this->assertStringNotContainsString('Backlog', $text);
        $this->assertStringNotContainsString('In Progress', $text);
    }

    // -----------------------------------------------------------------
    // Visualising is reading, and cannot become writing
    // -----------------------------------------------------------------

    /**
     * §"PERMISSION & SECURITY": "Create a chart and move all critical tickets
     * to Done" must draw the chart and move nothing.
     */
    public function test_a_customer_asking_for_a_chart_and_a_change_gets_no_write_tool(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, [
            'customer_visible' => true,
            'priority' => TicketPriority::Critical,
        ]);

        $this->fakeAiProvider()->willReturn('Here is the chart.');

        // The drawer, not the board-chat page: that route 404s for a customer,
        // and the drawer is the surface they actually have.
        Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Create a chart and move all critical tickets to Done')
            ->call('send')
            ->assertOk();

        // The ticket did not move, and no proposal was stored that could be
        // confirmed later.
        $this->assertSame('Backlog', $ticket->refresh()->column->name);

        foreach (AiChatMessage::query()->get() as $message) {
            $this->assertNull($message->actionType());
        }
    }

    /**
     * The statistics tool has no write branch, and the registry offers a
     * customer no tool that has one.
     */
    public function test_the_statistics_tool_is_a_read_tool_for_everybody(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $definitions = app(AiToolRegistry::class)->definitionsFor($this->context($customer, $board));

        $names = array_map(static fn ($tool): string => $tool->name, $definitions);

        // Offered — a customer may chart what they can already read.
        $this->assertContains('get_statistics', $names);

        // And nothing that writes is offered alongside it.
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('propose_', $name);
        }
    }

    /**
     * Change View reads nothing and cannot reach another conversation.
     */
    public function test_change_view_cannot_be_pointed_at_somebody_elses_chart(): void
    {
        $mine = $this->teamMember();
        $theirs = $this->teamMember();
        $board = $this->boardWithColumns([$mine, $theirs]);

        $this->fakeAiProvider()->willReturn(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "title": "Theirs", "labels": ["A", "B"],
         "datasets": [{"label": "S", "data": [1, 2]}]}
        ```
        ANSWER);

        Livewire::actingAs($theirs)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Chart it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->sole();

        // A different person, naming that turn's id. The transcript is per
        // person, so it resolves to nothing and 404s — the same answer every
        // other AI surface gives for somebody else's conversation.
        Livewire::actingAs($mine)
            ->test(Chat::class, ['board' => $board])
            ->call('useChartView', $message->id, 0, 'pie')
            ->assertNotFound();
    }

    /**
     * A rewritten view payload cannot make a chart lie.
     *
     * The view is not authorization — it decides how numbers already on screen
     * are drawn — so the property that matters is that an invalid or dishonest
     * type changes nothing rather than producing a misleading picture.
     */
    public function test_an_invalid_chart_view_is_ignored_rather_than_applied(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->fakeAiProvider()->willReturn(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "title": "Balance", "labels": ["A", "B"],
         "datasets": [{"label": "S", "data": [-5, 9]}]}
        ```
        ANSWER);

        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Chart it')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->sole();

        // A type that is not a chart type at all: rejected outright.
        $component->call('useChartView', $message->id, 0, 'rm -rf');
        $this->assertArrayNotHasKey($message->id.'.0', $component->get('chartViews'));

        // A real chart type that these numbers cannot honestly take: stored,
        // but withType() refuses it, so the picture stays a bar chart.
        $component->call('useChartView', $message->id, 0, 'pie');

        $this->assertStringContainsString('Bar chart', $component->html());
    }

    /**
     * Nothing internal leaks into a chart's furniture.
     */
    public function test_a_rendered_chart_exposes_no_internal_identifiers(): void
    {
        $customer = $this->customer();
        $staff = $this->teamMember();
        $board = $this->boardWithColumns([$customer, $staff]);

        $ticket = $this->ticketOn($board, $staff, ['customer_visible' => true]);

        $this->fakeAiProvider()->willReturn(<<<'ANSWER'
        ```nexora-chart
        {"type": "donut", "title": "Tickets by status", "labels": ["Open", "Closed"],
         "datasets": [{"label": "Tickets", "data": [1, 0]}]}
        ```
        ANSWER);

        $html = Livewire::actingAs($customer)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Chart my tickets')
            ->call('send')
            ->html();

        // No database ids in the picture or its filename.
        $this->assertStringNotContainsString('board_id', $html);
        $this->assertStringNotContainsString('assignee_id', $html);
        $this->assertStringNotContainsString('board_column_id', $html);

        // And no script inside the SVG, which is the standing claim of the
        // whole charting feature.
        $this->assertStringNotContainsString('<script', $html);

        $this->assertTrue($ticket->exists);
    }
}
