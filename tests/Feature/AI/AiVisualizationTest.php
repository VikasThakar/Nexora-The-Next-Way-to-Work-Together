<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiCapabilityMode;
use App\Enums\TicketPriority;
use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\Label;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\GetStatisticsTool;
use App\Services\BoardAccess;
use App\Support\RichResponse\ChartRenderer;
use App\Support\RichResponse\ChartSpec;
use App\Support\RichResponse\RichResponseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Charts drawn from real workspace data.
 *
 * The claim under test is the one the brief cares most about: a chart's numbers
 * come from the database, aggregated with the asker's own visibility applied in
 * SQL, and not from anything the model made up. So almost every test here
 * creates real tickets and then asserts on the figures the tool returned —
 * asserting on the rendered SVG would prove the picture was drawn, which is the
 * easy half.
 *
 * The tool is exercised directly rather than through a fake model's tool call
 * wherever the question is about numbers. That is deliberate: interposing a
 * scripted provider between the tickets and the assertion adds nothing except a
 * second thing that can be wrong.
 */
class AiVisualizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Call the statistics tool as somebody, in a scope.
     *
     * @param  array<string, mixed>  $input
     */
    private function ask(array $input, User $user, ?Board $board = null): string
    {
        $context = new AiToolContext(
            user: $user,
            scope: $board instanceof Board
                ? AiContextScope::board($board)
                : AiContextScope::workspace(),
            session: AiSession::factory()->for($user)->create(),
            mode: AiCapabilityMode::Agent,
            staff: app(BoardAccess::class)->canSeeInternalContent($user),
        );

        return app(GetStatisticsTool::class)->handle($input, $context)->text;
    }

    // -----------------------------------------------------------------
    // Real data
    // -----------------------------------------------------------------

    public function test_a_status_breakdown_counts_the_tickets_that_are_really_there(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        // Three in Backlog, two in Done, and nothing anywhere else.
        $this->ticketOn($board, $admin);
        $this->ticketOn($board, $admin);
        $this->ticketOn($board, $admin);

        foreach (range(1, 2) as $ignored) {
            $ticket = $this->ticketOn($board, $admin);
            $ticket->board_column_id = $this->columnNamed($board, 'Done')->id;
            $ticket->save();
        }

        $text = $this->ask(['metric' => 'by_status'], $admin, $board);

        $this->assertStringContainsString("Backlog\t3", $text);
        $this->assertStringContainsString("Done\t2", $text);
        $this->assertStringContainsString('(total 5)', $text);

        // An empty column is absent rather than zero, because TeamStatistics
        // groups over tickets. That is existing behaviour and the statistics
        // page shows the same thing; the priority breakdown is the one that
        // fills its zeroes, and it does so deliberately.
        $this->assertStringNotContainsString('In Progress', $text);

        // And it says where the numbers came from, so the answer can too.
        $this->assertStringContainsString($board->name, $text);
    }

    public function test_a_priority_breakdown_includes_every_priority_even_at_zero(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->ticketOn($board, $admin, ['priority' => TicketPriority::Critical]);
        $this->ticketOn($board, $admin, ['priority' => TicketPriority::Critical]);
        $this->ticketOn($board, $admin, ['priority' => TicketPriority::Low]);

        $text = $this->ask(['metric' => 'by_priority'], $admin, $board);

        $this->assertStringContainsString("Critical\t2", $text);
        $this->assertStringContainsString("Low\t1", $text);
        // "No high-priority tickets" is information; an absent row reads as an
        // oversight.
        $this->assertStringContainsString("High\t0", $text);
    }

    public function test_an_assignee_breakdown_names_real_members_and_counts_open_work(): void
    {
        $admin = $this->admin();
        $alex = $this->teamMember(['name' => 'Alex Round']);
        $board = $this->boardWithColumns([$admin, $alex]);

        $this->ticketOn($board, $admin, ['assignee_id' => $alex->id]);
        $this->ticketOn($board, $admin, ['assignee_id' => $alex->id]);

        $text = $this->ask(['metric' => 'by_assignee'], $admin, $board);

        $this->assertStringContainsString("Alex Round\t2", $text);
        // Long names read better beside their bars than rotated under them.
        $this->assertStringContainsString('hbar', $text);
    }

    public function test_a_label_breakdown_uses_the_boards_own_labels(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $bug = Label::factory()->create(['board_id' => $board->id, 'name' => 'bug']);
        $this->ticketOn($board, $admin)->labels()->sync([$bug->id]);
        $this->ticketOn($board, $admin)->labels()->sync([$bug->id]);

        $text = $this->ask(['metric' => 'by_label'], $admin, $board);

        $this->assertStringContainsString("bug\t2", $text);
    }

    public function test_totals_are_returned_as_named_figures_for_stat_cards(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->ticketOn($board, $admin);
        $this->ticketOn($board, $admin);

        $text = $this->ask(['metric' => 'totals'], $admin, $board);

        $this->assertStringContainsString("Total\t2", $text);
        // Unrelated totals are cards, not bars — a bar chart of "total, open,
        // closed" implies a comparison that is not there.
        $this->assertStringContainsString('nexora-kpi', $text);
    }

    // -----------------------------------------------------------------
    // Nothing invented
    // -----------------------------------------------------------------

    public function test_an_empty_dataset_says_so_rather_than_offering_a_chart(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $text = $this->ask(['metric' => 'by_assignee'], $admin, $board);

        $this->assertStringContainsString('no data to chart', $text);
        $this->assertStringContainsString('not enough', $text);
        // No fabricated rows.
        $this->assertStringNotContainsString('label<TAB>value', $text);
    }

    public function test_a_board_that_cannot_be_reached_returns_nothing_rather_than_the_workspace(): void
    {
        $admin = $this->admin();
        $outsider = $this->teamMember();

        $mine = $this->boardWithColumns([$outsider]);
        $theirs = $this->boardWithColumns([$admin], ['slug' => 'private-board']);

        $this->ticketOn($theirs, $admin);
        $this->ticketOn($theirs, $admin);

        $text = $this->ask(['metric' => 'by_status', 'board' => 'private-board'], $outsider, $mine);

        // Not "here are the workspace totals instead", which is the dangerous
        // fallback: a figure screenshotted as one board's is a real mistake.
        $this->assertStringContainsString('not one this person can see', $text);
        $this->assertStringNotContainsString('(total 2)', $text);
    }

    // -----------------------------------------------------------------
    // Date ranges
    // -----------------------------------------------------------------

    public function test_a_period_narrows_what_is_counted(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $old = $this->ticketOn($board, $admin);
        $old->created_at = now()->subDays(120);
        $old->save();

        $this->ticketOn($board, $admin);

        $recent = $this->ask(['metric' => 'created_by_week', 'period' => 'last_7_days'], $admin, $board);
        $wide = $this->ask(['metric' => 'created_by_week', 'period' => 'this_year'], $admin, $board);

        // One ticket was raised in the last week; both fall inside the year.
        $this->assertStringContainsString('(total 1)', $recent);
        $this->assertStringContainsString('(total 2)', $wide);
    }

    public function test_the_natural_language_ranges_resolve_to_real_dates(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $this->ticketOn($board, $admin);

        foreach (['today', 'yesterday', 'this_week', 'last_week', 'last_month'] as $period) {
            $text = $this->ask(['metric' => 'created_by_week', 'period' => $period], $admin, $board);

            // Each states the range it actually used, so nothing is ambiguous
            // about what "last week" meant.
            $this->assertMatchesRegularExpression(
                '/\d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}/',
                $text,
                "The {$period} range did not report its dates."
            );
        }
    }

    public function test_a_custom_range_is_accepted(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $this->ticketOn($board, $admin);

        $text = $this->ask([
            'metric' => 'created_by_week',
            'from' => now()->subDays(3)->toDateString(),
            'to' => now()->toDateString(),
        ], $admin, $board);

        $this->assertStringContainsString(now()->subDays(3)->toDateString(), $text);
        $this->assertStringContainsString(now()->toDateString(), $text);
    }

    public function test_a_comparison_returns_the_preceding_range_as_a_second_series(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->ticketOn($board, $admin);

        $text = $this->ask([
            'metric' => 'created_by_week',
            'period' => 'last_7_days',
            'compare_previous' => true,
        ], $admin, $board);

        $this->assertStringContainsString('Previous period', $text);
        $this->assertStringContainsString('SECOND dataset', $text);
    }

    // -----------------------------------------------------------------
    // Chart types
    // -----------------------------------------------------------------

    public function test_every_chart_type_including_the_new_one_renders(): void
    {
        $renderer = app(ChartRenderer::class);
        $parser = app(RichResponseParser::class);

        foreach (ChartSpec::TYPES as $type) {
            $blocks = $parser->parse(<<<ANSWER
            ```nexora-chart
            {"type": "{$type}", "title": "T", "labels": ["Alpha", "Beta", "Gamma"],
             "datasets": [{"label": "S", "data": [3, 7, 5]}]}
            ```
            ANSWER, $this->admin());

            $this->assertTrue($blocks[0]->isChart(), "The {$type} chart did not parse.");
            $this->assertSame($type, $blocks[0]->chart->type);

            foreach ([false, true] as $dark) {
                $svg = $renderer->render($blocks[0]->chart, $dark);

                $this->assertStringStartsWith('<svg', $svg);
                $this->assertStringEndsWith('</svg>', $svg);
                $this->assertStringNotContainsString('<script', $svg);
            }
        }
    }

    public function test_a_horizontal_bar_chart_is_reachable_by_its_synonyms(): void
    {
        foreach (['hbar', 'horizontal_bar', 'barh'] as $written) {
            $chart = ChartSpec::fromArray([
                'type' => $written,
                'labels' => ['A', 'B'],
                'datasets' => [['label' => 'S', 'data' => [1, 2]]],
            ]);

            $this->assertSame('hbar', $chart->type, "'{$written}' did not resolve to hbar.");
        }
    }

    public function test_a_stacked_bar_is_a_bar_with_the_flag_set(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'stacked_bar',
            'stacked' => true,
            'labels' => ['A', 'B'],
            'datasets' => [
                ['label' => 'One', 'data' => [1, 2]],
                ['label' => 'Two', 'data' => [3, 4]],
            ],
        ]);

        $this->assertSame('bar', $chart->type);
        $this->assertTrue($chart->stacked);
        $this->assertSame(2, $chart->seriesCount());
    }

    public function test_the_dark_and_light_renderings_differ_only_in_their_chrome(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'title' => 'Tickets',
            'labels' => ['A', 'B'],
            'datasets' => [['label' => 'S', 'data' => [3, 6]]],
        ]);

        $renderer = app(ChartRenderer::class);

        $light = $renderer->render($chart, false);
        $dark = $renderer->render($chart, true);

        $this->assertNotSame($light, $dark);

        // The series colour is a vivid accent and is correct on either ground,
        // so it is deliberately NOT remapped — see app.css.
        $this->assertStringContainsString('#4f46e5', $light);
        $this->assertStringContainsString('#4f46e5', $dark);

        // The text is. A slate-600 label on a dark surface is unreadable.
        $this->assertStringContainsString('#475569', $light);
        $this->assertStringNotContainsString('#475569', $dark);
    }

    // -----------------------------------------------------------------
    // KPI cards
    // -----------------------------------------------------------------

    public function test_a_kpi_fence_becomes_stat_cards_and_exports_as_a_table(): void
    {
        $blocks = app(RichResponseParser::class)->parse(<<<'ANSWER'
        Here is where NutriLens stands.

        ```nexora-kpi
        {"title": "NutriLens", "source": "Last 30 days",
         "cards": [{"label": "Open", "value": 14}, {"label": "Closed", "value": 31, "caption": "+8"}]}
        ```
        ANSWER, $this->admin());

        $kpi = $blocks[1]->kpi;

        $this->assertTrue($blocks[1]->isKpi());
        $this->assertSame('NutriLens', $kpi->title);
        $this->assertSame(2, $kpi->cardCount());
        $this->assertSame('Open', $kpi->cards[0]['label']);
        $this->assertSame('14', $kpi->cards[0]['value']);
        $this->assertSame('+8', $kpi->cards[1]['caption']);

        // Exportable like every other structured block.
        $this->assertTrue($blocks[1]->isExportable());
        $this->assertSame(['Measure', 'Value', 'Note'], $blocks[1]->asTable()->columns);
    }

    public function test_a_malformed_kpi_fence_falls_back_to_prose(): void
    {
        $blocks = app(RichResponseParser::class)->parse(<<<'ANSWER'
        ```nexora-kpi
        {"cards": "not an array"}
        ```
        ANSWER, $this->admin());

        // A code fence rendered as a code fence is a readable degradation. An
        // answer must never become an error because its illustration was wrong.
        $this->assertTrue($blocks[0]->isProse());
    }

    // -----------------------------------------------------------------
    // Change View
    // -----------------------------------------------------------------

    public function test_change_view_redraws_the_same_numbers_without_asking_the_model_again(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $fake->willReturn(<<<'ANSWER'
        Here is the split.

        ```nexora-chart
        {"type": "bar", "title": "Tickets by status", "labels": ["Backlog", "Done"],
         "datasets": [{"label": "Tickets", "data": [8, 4]}]}
        ```
        ANSWER);

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Chart the statuses')
            ->call('send');

        $message = AiChatMessage::query()->where('role', 'assistant')->sole();

        $callsBefore = $fake->calls;

        $component->call('useChartView', $message->id, 1, 'donut');

        $this->assertSame('donut', $component->get('chartViews')[$message->id.'.1'] ?? null);

        // The whole point: no second question, and therefore no second bill.
        $this->assertSame($callsBefore, $fake->calls);

        // The stored answer is untouched — the chart type is a view of it.
        $this->assertStringContainsString('"type": "bar"', $message->refresh()->content);
    }

    public function test_change_view_refuses_a_type_the_numbers_cannot_honestly_take(): void
    {
        // A negative value has no slice: a pie divides a whole.
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B'],
            'datasets' => [['label' => 'S', 'data' => [-4, 9]]],
        ]);

        $this->assertNotContains('pie', $chart->supportedTypes());
        $this->assertStringContainsString('negative', (string) $chart->circularRefusal());

        // Asked anyway, it stays as it was rather than misrepresenting the data.
        $this->assertSame('bar', $chart->withType('pie')->type);

        // A type it can take does apply.
        $this->assertSame('line', $chart->withType('line')->type);
    }

    public function test_too_many_categories_disables_the_circular_views(): void
    {
        $labels = [];
        $values = [];

        foreach (range(1, 20) as $n) {
            $labels[] = 'Cat '.$n;
            $values[] = $n;
        }

        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => $labels,
            'datasets' => [['label' => 'S', 'data' => $values]],
        ]);

        $this->assertNotContains('donut', $chart->supportedTypes());
        $this->assertStringContainsString('unreadable', (string) $chart->circularRefusal());
    }

    public function test_switching_to_a_pie_drops_to_a_single_series(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'line',
            'labels' => ['A', 'B'],
            'datasets' => [
                ['label' => 'One', 'data' => [1, 2]],
                ['label' => 'Two', 'data' => [3, 4]],
            ],
        ]);

        $this->assertSame(2, $chart->seriesCount());

        // The same narrowing fromArray() applies, because it is the same rule.
        $pie = $chart->withType('pie');

        $this->assertSame('pie', $pie->type);
        $this->assertSame(1, $pie->seriesCount());
        $this->assertSame('One', $pie->datasets[0]['label']);
    }

    // -----------------------------------------------------------------
    // The whole path, including voice
    // -----------------------------------------------------------------

    public function test_a_charting_answer_renders_with_its_toolbar_in_the_conversation(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willReturn(<<<'ANSWER'
        Here is the current distribution.

        ```nexora-chart
        {"type": "donut", "title": "Tickets by status", "labels": ["Backlog", "Done"],
         "datasets": [{"label": "Tickets", "data": [8, 4]}]}
        ```
        ANSWER);

        $html = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Create a pie chart for tickets by status')
            ->call('send')
            ->html();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Download PNG', $html);
        $this->assertStringContainsString('Export CSV', $html);
        $this->assertStringContainsString('View data', $html);

        // Change View, with the type the model chose marked as current.
        $this->assertStringContainsString('useChartView', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);

        // The figures are on the page as text, not only as a picture.
        $this->assertStringContainsString('Backlog', $html);
    }

    /**
     * §"VOICE INPUT": a spoken chart request needs no visualisation logic of
     * its own, because it becomes an ordinary question before any of this runs.
     */
    public function test_a_spoken_chart_request_takes_the_same_path(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willReturn(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "title": "Tickets by priority", "labels": ["Critical", "Low"],
         "datasets": [{"label": "Tickets", "data": [2, 5]}]}
        ```
        ANSWER);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->call('sendSpoken', 'Show me a graph of tickets by priority');

        $message = AiChatMessage::query()->where('role', 'assistant')->sole();

        $this->assertTrue(app(RichResponseParser::class)->looksRich($message->content));

        $blocks = app(RichResponseParser::class)->parse($message->content, $admin, $board);

        $this->assertTrue($blocks[0]->isChart());
        $this->assertSame([2.0, 5.0], $blocks[0]->chart->datasets[0]['values']);
    }

    /**
     * The tool is a read, so it is offered wherever reads are — and its
     * material reaches the model.
     */
    public function test_the_model_receives_the_real_figures_when_it_asks_for_them(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->ticketOn($board, $admin, ['priority' => TicketPriority::Critical]);
        $this->ticketOn($board, $admin, ['priority' => TicketPriority::Critical]);

        $fake = $this->fakeAiProvider();
        $fake->willLookUp('get_statistics', ['metric' => 'by_priority'], 'Two critical tickets.');

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Chart tickets by priority')
            ->call('send');

        // The numbers the model was handed are the numbers in the database.
        $this->assertStringContainsString("Critical\t2", $fake->toolResultText());
    }
}
