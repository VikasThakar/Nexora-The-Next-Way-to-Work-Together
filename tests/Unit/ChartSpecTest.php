<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RichResponse\ChartSpec;
use App\Support\RichResponse\TableSpec;
use Tests\TestCase;

/**
 * The schema validation that stands between a model and the browser.
 *
 * A model emits JSON; this turns it into a type carrying numbers, labels and a
 * type from a fixed list of six, or into null. There is no third outcome, and
 * nothing in between — which is what makes the claim "a model cannot inject
 * JavaScript into a chart" structural rather than a matter of escaping.
 */
class ChartSpecTest extends TestCase
{
    // -----------------------------------------------------------------
    // What is accepted
    // -----------------------------------------------------------------

    public function test_it_accepts_the_documented_shape(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'line',
            'title' => 'Tickets by month',
            'labels' => ['Jan', 'Feb'],
            'datasets' => [['label' => 'Tickets', 'data' => [120, 143]]],
            'x_label' => 'Month',
            'y_label' => 'Count',
        ]);

        $this->assertInstanceOf(ChartSpec::class, $chart);
        $this->assertSame('line', $chart->type);
        $this->assertSame('Tickets by month', $chart->title);
        $this->assertSame('Month', $chart->xLabel);
        $this->assertSame([120.0, 143.0], $chart->datasets[0]['values']);
    }

    public function test_it_accepts_the_single_series_shorthand(): void
    {
        // What a model writes when nobody insisted on the longer form.
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B'],
            'values' => [1, 2],
        ]);

        $this->assertInstanceOf(ChartSpec::class, $chart);
        $this->assertSame(1, $chart->seriesCount());
        $this->assertSame('Value', $chart->datasets[0]['label']);
    }

    public function test_camel_case_axis_labels_are_accepted_too(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A'],
            'datasets' => [['label' => 'S', 'data' => [1]]],
            'xLabel' => 'Category',
            'yLabel' => 'Amount',
        ]);

        $this->assertSame('Category', $chart->xLabel);
        $this->assertSame('Amount', $chart->yLabel);
    }

    public function test_a_series_without_a_name_is_numbered(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A'],
            'datasets' => [['data' => [1]], ['data' => [2]]],
        ]);

        $this->assertSame('Series 1', $chart->datasets[0]['label']);
        $this->assertSame('Series 2', $chart->datasets[1]['label']);
    }

    // -----------------------------------------------------------------
    // What is refused
    // -----------------------------------------------------------------

    public function test_a_chart_with_no_data_at_all_is_null(): void
    {
        $this->assertNull(ChartSpec::fromArray([]));
        $this->assertNull(ChartSpec::fromArray(['type' => 'bar', 'labels' => ['A', 'B']]));
        $this->assertNull(ChartSpec::fromArray(['type' => 'bar', 'datasets' => []]));
    }

    public function test_a_series_of_nothing_but_text_is_not_a_series(): void
    {
        $this->assertNull(ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B'],
            'datasets' => [['label' => 'S', 'data' => ['high', 'low']]],
        ]));
    }

    public function test_a_non_finite_number_becomes_a_gap(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'line',
            'labels' => ['A', 'B', 'C'],
            'datasets' => [['label' => 'S', 'data' => [1, INF, 3]]],
        ]);

        // Infinity has no position on an axis. A gap is the honest rendering;
        // a number would be an invention.
        $this->assertSame([1.0, null, 3.0], $chart->datasets[0]['values']);
    }

    public function test_an_unrecognised_type_becomes_a_bar_chart(): void
    {
        foreach (['', 'radar', 'treemap', 'svg', null, 42] as $type) {
            $chart = ChartSpec::fromArray([
                'type' => $type,
                'labels' => ['A'],
                'datasets' => [['label' => 'S', 'data' => [1]]],
            ]);

            $this->assertSame('bar', $chart->type);
        }
    }

    public function test_common_synonyms_are_understood(): void
    {
        $of = fn (string $type): string => ChartSpec::fromArray([
            'type' => $type,
            'labels' => ['A'],
            'datasets' => [['label' => 'S', 'data' => [1]]],
        ])->type;

        $this->assertSame('donut', $of('doughnut'));
        $this->assertSame('bar', $of('column'));
        $this->assertSame('bar', $of('histogram'));
        $this->assertSame('area', $of('stacked_area'));
    }

    /**
     * A model cannot choose anything but numbers, labels and one of six types.
     *
     * Anything else in the JSON is simply not read — colours, dimensions, fonts,
     * callbacks, plugin options. There is no passthrough, which is why this test
     * asserts on the *absence* of properties rather than on their sanitisation.
     */
    public function test_presentation_fields_are_never_read(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A'],
            'datasets' => [[
                'label' => 'S',
                'data' => [1],
                'backgroundColor' => 'url(javascript:alert(1))',
                'borderColor' => '#ff0000',
            ]],
            'options' => ['onClick' => 'alert(1)'],
            'plugins' => ['tooltip' => ['callbacks' => ['label' => 'alert(1)']]],
            'width' => 99999,
        ]);

        // The dataset carries exactly two keys, whatever else was sent.
        $this->assertSame(['label', 'values'], array_keys($chart->datasets[0]));

        // And the object has no property such a value could have landed in.
        $this->assertFalse(property_exists($chart, 'options'));
        $this->assertFalse(property_exists($chart, 'plugins'));
        $this->assertFalse(property_exists($chart, 'width'));
    }

    // -----------------------------------------------------------------
    // Coercion
    // -----------------------------------------------------------------

    public function test_missing_labels_are_numbered_rather_than_dropping_points(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['Jan', 'Feb'],
            'datasets' => [['label' => 'S', 'data' => [1, 2, 3, 4]]],
        ]);

        // A model that supplied two labels for four points has miscounted, and
        // a chart with unlabelled bars beats no chart.
        $this->assertSame(['Jan', 'Feb', '3', '4'], $chart->labels);
    }

    public function test_surplus_labels_are_trimmed(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B', 'C', 'D'],
            'datasets' => [['label' => 'S', 'data' => [1, 2]]],
        ]);

        $this->assertSame(['A', 'B'], $chart->labels);
    }

    /**
     * A pie of two series is a category error, and the first one is used.
     */
    public function test_a_circular_chart_keeps_only_its_first_series(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'pie',
            'labels' => ['A', 'B'],
            'datasets' => [
                ['label' => 'First', 'data' => [1, 2]],
                ['label' => 'Second', 'data' => [3, 4]],
            ],
        ]);

        $this->assertTrue($chart->isCircular());
        $this->assertSame(1, $chart->seriesCount());
        $this->assertSame('First', $chart->datasets[0]['label']);
    }

    public function test_too_many_series_are_capped(): void
    {
        $datasets = [];

        foreach (range(1, 20) as $index) {
            $datasets[] = ['label' => 'S'.$index, 'data' => [$index]];
        }

        $chart = ChartSpec::fromArray(['type' => 'line', 'labels' => ['A'], 'datasets' => $datasets]);

        // Beyond a handful a chart stops being readable anyway.
        $this->assertLessThanOrEqual(8, $chart->seriesCount());
    }

    public function test_a_long_label_is_shortened(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => [str_repeat('x', 400)],
            'datasets' => [['label' => str_repeat('y', 400), 'data' => [1]]],
        ]);

        $this->assertLessThanOrEqual(60, mb_strlen($chart->labels[0]));
        $this->assertLessThanOrEqual(120, mb_strlen($chart->datasets[0]['label']));
    }

    // -----------------------------------------------------------------
    // Scale
    // -----------------------------------------------------------------

    public function test_the_axis_starts_at_zero_for_positive_data(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B'],
            'datasets' => [['label' => 'S', 'data' => [100, 110]]],
        ]);

        // A baseline that is not zero exaggerates every difference on a bar
        // chart, and a bar chart is read as a proportion.
        $this->assertSame(0.0, $chart->minimum());
        $this->assertSame(110.0, $chart->maximum());
    }

    public function test_negative_data_extends_the_axis_below_zero(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'labels' => ['A', 'B'],
            'datasets' => [['label' => 'S', 'data' => [-40, 60]]],
        ]);

        $this->assertSame(-40.0, $chart->minimum());
    }

    /**
     * A stacked chart scales to the stack, not to the tallest series.
     *
     * Otherwise half the data is drawn above the top of the frame.
     */
    public function test_a_stacked_chart_scales_to_the_sum_of_its_series(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'bar',
            'stacked' => true,
            'labels' => ['A'],
            'datasets' => [
                ['label' => 'One', 'data' => [30]],
                ['label' => 'Two', 'data' => [40]],
            ],
        ]);

        $this->assertTrue($chart->stacked);
        $this->assertSame(70.0, $chart->maximum());
    }

    // -----------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------

    /**
     * A chart's export is the chart's data, by construction.
     *
     * Both the picture and the CSV come from this one object, so the
     * requirement that an export be the exact rendered data is met
     * structurally rather than by keeping two paths in step.
     */
    public function test_a_chart_converts_to_the_table_it_was_drawn_from(): void
    {
        $chart = ChartSpec::fromArray([
            'type' => 'line',
            'x_label' => 'Month',
            'labels' => ['Jan', 'Feb'],
            'datasets' => [
                ['label' => 'Tickets', 'data' => [120, 143]],
                ['label' => 'Completed', 'data' => [96, null]],
            ],
        ]);

        $table = $chart->toTable();

        $this->assertInstanceOf(TableSpec::class, $table);
        $this->assertSame(['Month', 'Tickets', 'Completed'], $table->columns);

        // A gap exports as an empty cell, not as a zero.
        $this->assertSame([['Jan', '120', '96'], ['Feb', '143', '']], $table->rows);
    }
}
