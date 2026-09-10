<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Livewire\Ai\Chat;
use App\Models\AiChatMessage;
use App\Support\RichResponse\ChartRenderer;
use App\Support\RichResponse\RichBlock;
use App\Support\RichResponse\RichResponseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tables and charts in an assistant answer.
 *
 * Two claims are being tested. That structure the model produces is *rendered*
 * as structure — a real table, a real chart — rather than printed as text. And
 * that a model cannot reach the browser with anything but numbers and labels:
 * no markup, no script, no colour, no URL.
 */
class AiRichResponseTest extends TestCase
{
    use RefreshDatabase;

    private function parse(string $content): array
    {
        return app(RichResponseParser::class)->parse($content, $this->teamMember());
    }

    // -----------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------

    public function test_a_fenced_table_becomes_a_table_block(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        Throughput improved through the quarter.

        ```nexora-table
        {"title": "Tickets by month", "columns": ["Month", "Tickets", "Completed"],
         "rows": [["Jan", 120, 96], ["Feb", 143, 121]]}
        ```

        February was the busiest month.
        ANSWER);

        // Prose, table, prose — in the order the model wrote them, which is the
        // shape the requirement asked for.
        $this->assertCount(3, $blocks);
        $this->assertTrue($blocks[0]->isProse());
        $this->assertTrue($blocks[1]->isTable());
        $this->assertTrue($blocks[2]->isProse());

        $table = $blocks[1]->table;

        $this->assertSame('Tickets by month', $table->title);
        $this->assertSame(['Month', 'Tickets', 'Completed'], $table->columns);
        $this->assertSame([['Jan', '120', '96'], ['Feb', '143', '121']], $table->rows);
    }

    /**
     * An ordinary Markdown table is promoted too.
     *
     * The path most tables actually take: a model asked for a table writes a
     * pipe table whatever the prompt says, at least some of the time.
     */
    public function test_a_markdown_pipe_table_is_promoted_to_a_real_table(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        Here is the breakdown.

        | Month | Tickets |
        |-------|---------|
        | Jan   | 120     |
        | Feb   | 143     |

        That is a 19% increase.
        ANSWER);

        $tables = array_values(array_filter($blocks, fn (RichBlock $b): bool => $b->isTable()));

        $this->assertCount(1, $tables);
        $this->assertSame(['Month', 'Tickets'], $tables[0]->table->columns);
        $this->assertSame([['Jan', '120'], ['Feb', '143']], $tables[0]->table->rows);
    }

    /**
     * A sentence containing pipes stays a sentence.
     *
     * The delimiter row is what makes the detection reliable, and this is the
     * false positive it exists to prevent.
     */
    public function test_prose_containing_pipes_is_not_mistaken_for_a_table(): void
    {
        $blocks = $this->parse('The options are A | B | C, and none of them is free.');

        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]->isProse());
    }

    public function test_a_pipe_inside_a_cell_survives_the_round_trip(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        | Name | Expression |
        |------|------------|
        | Or   | a \| b     |
        ANSWER);

        $this->assertTrue($blocks[0]->isTable());
        $this->assertSame([['Or', 'a | b']], $blocks[0]->table->rows);
    }

    public function test_a_ragged_table_is_squared_off_rather_than_dropped(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-table
        {"columns": ["A", "B", "C"], "rows": [["1", "2"], ["1", "2", "3", "4"]]}
        ```
        ANSWER);

        // A short row is the model's arithmetic mistake, not a reason to lose
        // the data it did produce.
        $this->assertSame([['1', '2', ''], ['1', '2', '3']], $blocks[0]->table->rows);
    }

    public function test_object_shaped_rows_are_read_by_column_name(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-table
        {"columns": ["Month", "Tickets"], "rows": [{"Month": "Jan", "Tickets": 120}]}
        ```
        ANSWER);

        $this->assertSame([['Jan', '120']], $blocks[0]->table->rows);
    }

    // -----------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------

    public function test_a_fenced_chart_becomes_a_chart_block(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        Revenue increased by 18% in Q3.

        ```nexora-chart
        {"type": "bar", "title": "Revenue by quarter", "labels": ["Q1", "Q2", "Q3"],
         "datasets": [{"label": "Revenue", "data": [100, 120, 142]}]}
        ```
        ANSWER);

        $chart = $blocks[1]->chart;

        $this->assertTrue($blocks[1]->isChart());
        $this->assertSame('bar', $chart->type);
        $this->assertSame('Revenue by quarter', $chart->title);
        $this->assertSame(['Q1', 'Q2', 'Q3'], $chart->labels);
        $this->assertSame([100.0, 120.0, 142.0], $chart->datasets[0]['values']);
    }

    public function test_every_supported_chart_type_renders(): void
    {
        $renderer = app(ChartRenderer::class);

        foreach (['line', 'bar', 'area', 'pie', 'donut', 'scatter'] as $type) {
            $blocks = $this->parse(<<<ANSWER
            ```nexora-chart
            {"type": "{$type}", "labels": ["A", "B", "C"],
             "datasets": [{"label": "S", "data": [3, 7, 5]}]}
            ```
            ANSWER);

            $this->assertTrue($blocks[0]->isChart(), "The {$type} chart did not parse.");

            $svg = $renderer->render($blocks[0]->chart);

            $this->assertStringStartsWith('<svg', $svg, "The {$type} chart did not render.");
            $this->assertStringEndsWith('</svg>', $svg);
        }
    }

    /**
     * A gap in a series stays a gap.
     *
     * Coercing a missing value to zero would draw a month with no data as a
     * month with none sold, which is a different and wrong claim.
     */
    public function test_a_missing_value_is_a_gap_and_not_a_zero(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "line", "labels": ["A", "B", "C"],
         "datasets": [{"label": "S", "data": [3, null, 5]}]}
        ```
        ANSWER);

        $this->assertSame([3.0, null, 5.0], $blocks[0]->chart->datasets[0]['values']);
    }

    public function test_quoted_and_formatted_numbers_are_understood(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "labels": ["A", "B"],
         "datasets": [{"label": "S", "data": ["1,240", "€980"]}]}
        ```
        ANSWER);

        $this->assertSame([1240.0, 980.0], $blocks[0]->chart->datasets[0]['values']);
    }

    public function test_an_unrecognised_chart_type_falls_back_to_a_bar_chart(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "sankey-radar-thing", "labels": ["A"], "datasets": [{"label": "S", "data": [1]}]}
        ```
        ANSWER);

        // A usable chart rather than a dropped illustration.
        $this->assertSame('bar', $blocks[0]->chart->type);
    }

    public function test_a_chart_with_no_numbers_is_left_as_prose(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "labels": ["A", "B"], "datasets": [{"label": "S", "data": ["x", "y"]}]}
        ```
        ANSWER);

        // Degraded to the code fence the model wrote, never to an error in the
        // middle of an answer.
        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]->isProse());
    }

    public function test_malformed_json_in_a_fence_is_left_as_prose(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-table
        {"columns": ["A", oh dear
        ```
        ANSWER);

        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]->isProse());
    }

    /**
     * An ordinary ```json example is not silently turned into a table.
     *
     * The reason the fence names are namespaced.
     */
    public function test_an_ordinary_code_fence_is_not_treated_as_structure(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        Configure it like this:

        ```json
        {"columns": ["A"], "rows": [["1"]]}
        ```
        ANSWER);

        foreach ($blocks as $block) {
            $this->assertTrue($block->isProse());
        }
    }

    /**
     * An unterminated fence — which is what a streaming answer looks like
     * halfway through — loses nothing.
     */
    public function test_an_unterminated_fence_is_rendered_as_text(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        Here it comes:

        ```nexora-chart
        {"type": "bar", "labels":
        ANSWER);

        $this->assertNotEmpty($blocks);

        foreach ($blocks as $block) {
            $this->assertTrue($block->isProse());
        }
    }

    // -----------------------------------------------------------------
    // Nothing model-authored becomes code
    // -----------------------------------------------------------------

    /**
     * A label containing markup comes out as text.
     *
     * The SVG is generated from numbers and coordinates this application
     * computed; the only model-authored strings in it are labels, and they are
     * XML-escaped as text nodes.
     */
    public function test_a_hostile_chart_label_cannot_inject_markup(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "title": "</svg><script>alert(1)</script>",
         "labels": ["<img src=x onerror=alert(1)>"],
         "datasets": [{"label": "\"><script>bad()</script>", "data": [1]}]}
        ```
        ANSWER);

        $svg = app(ChartRenderer::class)->render($blocks[0]->chart);

        /*
         * The claim is that no tag and no attribute can come out of a label,
         * not that the word "onerror" cannot appear. It can, and does, as
         * escaped text inside a <text> node — which is inert, and is the
         * correct rendering of a label somebody wrote that way.
         *
         * So the assertions are about the angle brackets: with none of them
         * surviving unescaped there is no element to carry an attribute.
         */
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('<img', $svg);
        $this->assertStringNotContainsString('</svg><', $svg);
        $this->assertStringNotContainsString('\"><', $svg);

        // Escaped, not dropped: every label is still legible as text.
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $svg);

        // And exactly one closing tag, so the document was not broken out of.
        $this->assertSame(1, substr_count($svg, '</svg>'));
    }

    /**
     * A model cannot choose a colour, a size, or any other attribute.
     *
     * Colours come from a fixed palette indexed by series position, so a
     * "colour" in the JSON is simply not read.
     */
    public function test_a_model_supplied_colour_is_ignored(): void
    {
        $blocks = $this->parse(<<<'ANSWER'
        ```nexora-chart
        {"type": "bar", "labels": ["A"], "backgroundColor": "url(javascript:alert(1))",
         "datasets": [{"label": "S", "data": [1], "borderColor": "expression(alert(1))"}]}
        ```
        ANSWER);

        $svg = app(ChartRenderer::class)->render($blocks[0]->chart);

        $this->assertStringNotContainsString('javascript:', $svg);
        $this->assertStringNotContainsString('expression(', $svg);
        $this->assertStringContainsString('#4f46e5', $svg);
    }

    public function test_a_hostile_table_cell_is_escaped_when_rendered(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $fake = $this->fakeAiProvider();

        $fake->willReturn(<<<'ANSWER'
        ```nexora-table
        {"columns": ["Payload"], "rows": [["<script>alert(1)</script>"]]}
        ```
        ANSWER);

        $html = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Show me a table')
            ->call('send')
            ->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -----------------------------------------------------------------
    // On screen
    // -----------------------------------------------------------------

    public function test_a_table_is_rendered_in_the_transcript_with_its_controls(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $fake = $this->fakeAiProvider();

        $fake->willReturn(<<<'ANSWER'
        Throughput by month.

        ```nexora-table
        {"title": "Tickets by month", "columns": ["Month", "Tickets"], "rows": [["Jan", 120]]}
        ```
        ANSWER);

        $html = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Show me throughput')
            ->call('send')
            ->html();

        // A real table, in a box that scrolls on its own rather than making the
        // page scroll sideways.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Tickets by month', $html);
        $this->assertStringContainsString('overflow-x-auto', $html);

        // And the two things somebody does with a table.
        $this->assertStringContainsString('Copy', $html);
        $this->assertStringContainsString('Export CSV', $html);
    }

    public function test_a_chart_is_rendered_as_an_inline_svg_with_no_script(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $fake = $this->fakeAiProvider();

        $fake->willReturn(<<<'ANSWER'
        Revenue rose.

        ```nexora-chart
        {"type": "line", "title": "Revenue", "labels": ["Q1", "Q2"],
         "datasets": [{"label": "Revenue", "data": [100, 118]}]}
        ```
        ANSWER);

        $html = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Chart revenue')
            ->call('send')
            ->html();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Download PNG', $html);
        $this->assertStringContainsString('Export CSV', $html);

        // The chart's data is on the page too, because a chart is a picture.
        $this->assertStringContainsString('View data', $html);

        // Both appearances are drawn, because the colours inside an SVG are
        // attributes the stylesheet's dark remapping cannot reach.
        $this->assertStringContainsString('dark:hidden', $html);
        $this->assertStringContainsString('hidden dark:block', $html);
    }

    /**
     * The stored artefact is still the text the model wrote.
     *
     * Rendering is a view of it, computed per request, exactly as
     * ContentRenderer is a view of a ticket description. That is what keeps a
     * transcript readable, searchable and unaffected by a later change to how
     * tables are drawn.
     */
    public function test_the_stored_message_is_still_the_models_own_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $fake = $this->fakeAiProvider();

        $fake->willReturn("```nexora-table\n{\"columns\": [\"A\"], \"rows\": [[\"1\"]]}\n```");

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'table please')
            ->call('send');

        $stored = AiChatMessage::query()->where('role', 'assistant')->sole();

        $this->assertStringContainsString('nexora-table', (string) $stored->content);
    }

    public function test_looks_rich_is_a_cheap_pre_check(): void
    {
        $parser = app(RichResponseParser::class);

        $this->assertTrue($parser->looksRich("```nexora-chart\n{}\n```"));
        $this->assertTrue($parser->looksRich("| A | B |\n|---|---|\n| 1 | 2 |"));

        // The common case: ordinary prose is not parsed at all.
        $this->assertFalse($parser->looksRich('Two tickets are blocked on review.'));
        $this->assertFalse($parser->looksRich('The options are A | B | C.'));
        $this->assertFalse($parser->looksRich(null));
    }
}
