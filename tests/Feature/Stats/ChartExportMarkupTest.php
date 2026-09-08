<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The markup the PNG and SVG downloads depend on.
 *
 * resources/js/chart-export.js takes a picture of a chart by serialising
 * `[data-chart] svg` off the page. That makes two things load-bearing in every
 * chart component, and neither of them is visible when the page looks right:
 *
 *   the `data-chart` wrapper, without which the buttons find nothing and say
 *   "There is no chart to export" — which is what the line chart did, silently,
 *   for as long as it had export buttons and no wrapper;
 *   the labels being <text> inside that SVG rather than HTML beside it, because
 *   a sibling div is on the screen and absent from the file.
 *
 * Neither shows up in a screenshot, and neither would fail a test of the page
 * that only checked its figures. So they are asserted here, per component,
 * against the shapes the statistics screens actually pass in.
 */
class ChartExportMarkupTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function chartProvider(): array
    {
        return [
            'columns' => ['charts.columns', [
                'series' => [
                    ['label' => 'w1', 'value' => 3],
                    ['label' => 'w2', 'value' => 7],
                ],
            ]],
            'columns stacked' => ['charts.columns', [
                'series' => [
                    ['label' => 'w1', 'completed' => 2, 'failed' => 1],
                    ['label' => 'w2', 'completed' => 5, 'failed' => 0],
                ],
                'stacked' => true,
            ]],
            'bar' => ['charts.bar', [
                'series' => [
                    ['label' => 'High', 'value' => 4, 'variant' => 'rose'],
                    ['label' => 'Low', 'value' => 1],
                ],
            ]],
            'bar with data colours' => ['charts.bar', [
                'series' => [
                    ['label' => 'Bug', 'value' => 2, 'color' => '#ff0000'],
                ],
            ]],
            'donut' => ['charts.donut', [
                'series' => [
                    ['label' => 'Open', 'value' => 3, 'variant' => 'brand'],
                    ['label' => 'Closed', 'value' => 9, 'variant' => 'emerald'],
                ],
            ]],
            'line' => ['charts.line', [
                'series' => [
                    ['label' => 'w1', 'value' => 12.5],
                    ['label' => 'w2', 'value' => 4.0, 'measured' => false],
                    ['label' => 'w3', 'value' => 30.0],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('chartProvider')]
    public function test_a_chart_renders_an_svg_the_exporter_can_find(string $component, array $data): void
    {
        $html = $this->render($component, $data);

        $this->assertStringContainsString('data-chart', $html, $component.' has no data-chart wrapper.');
        $this->assertStringContainsString('<svg', $html, $component.' rendered no SVG.');

        // The wrapper has to be an ancestor of the SVG, not a sibling of it:
        // the exporter's selector is `[data-chart] svg`.
        $this->assertMatchesRegularExpression(
            '/data-chart[^>]*>\s*<svg/',
            $html,
            $component.' does not wrap its SVG in the data-chart element.'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('chartProvider')]
    public function test_a_charts_labels_are_inside_the_svg(string $component, array $data): void
    {
        $html = $this->render($component, $data);

        $svg = $this->svgOf($html);

        $this->assertStringContainsString('<text', $svg, $component.' has no <text> in its SVG.');

        // Every label the caller supplied has to appear inside the SVG, not
        // beside it — a chart exported without them is a picture of some
        // unlabelled shapes.
        foreach ($data['series'] as $row) {
            $this->assertStringContainsString(
                (string) $row['label'],
                $svg,
                $component.' left the label "'.$row['label'].'" outside its SVG.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('chartProvider')]
    public function test_a_chart_carries_its_figures_as_hover_titles(string $component, array $data): void
    {
        // <title> rather than a title attribute, because an attribute on a div
        // is not part of the serialised SVG and a tooltip is often the only
        // place an exact figure appears.
        $this->assertStringContainsString(
            '<title>',
            $this->svgOf($this->render($component, $data)),
            $component.' has no <title> tooltips.'
        );
    }

    /**
     * An empty series is a sentence, not an empty chart.
     *
     * Worth pinning down because the export buttons are rendered by the
     * wrapper rather than by the chart: if an empty chart still emitted a
     * `data-chart` element, PNG would produce a blank rectangle instead of the
     * honest "There is no chart to export".
     *
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('chartProvider')]
    public function test_an_empty_chart_offers_nothing_to_export(string $component, array $data): void
    {
        $html = $this->render($component, ['series' => [], 'empty' => 'Nothing here.']);

        $this->assertStringContainsString('Nothing here.', $html);
        $this->assertStringNotContainsString('data-chart', $html);
    }

    public function test_the_donut_is_turned_by_a_transform_not_a_css_class(): void
    {
        $html = $this->render('charts.donut', [
            'series' => [['label' => 'Open', 'value' => 1, 'variant' => 'brand']],
        ]);

        // The exporter strips every class before serialising, because a class
        // means nothing without the page's stylesheet. A ring rotated by
        // `-rotate-90` would therefore export a quarter-turn out of place.
        $this->assertStringContainsString('rotate(-90', $html);
        $this->assertStringNotContainsString('-rotate-90', $html);
    }

    /**
     * Tailwind generates a utility only when it can see the literal class name
     * in a source file, so a class assembled from a fragment compiles to
     * nothing and the shape renders black. Easy to write, invisible until
     * somebody looks at the chart.
     */
    public function test_no_chart_builds_a_colour_class_from_a_fragment(): void
    {
        foreach (glob(resource_path('views/components/charts/*.blade.php')) as $file) {
            $source = (string) file_get_contents($file);

            foreach (['fill-', 'stroke-', 'bg-', 'text-'] as $prefix) {
                $this->assertDoesNotMatchRegularExpression(
                    '/class="[^"]*'.preg_quote($prefix, '/').'\{\{/',
                    $source,
                    basename($file).' interpolates a '.$prefix.' class name.'
                );
            }
        }
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $component, array $data): string
    {
        return Blade::render(
            '<x-dynamic-component :component="$component" '.$this->attributes($data).' />',
            array_merge($data, ['component' => $component])
        );
    }

    /**
     * The props as Blade attribute bindings, so each is passed as PHP rather
     * than stringified into the template.
     *
     * @param  array<string, mixed>  $data
     */
    private function attributes(array $data): string
    {
        return collect(array_keys($data))
            ->map(fn (string $key): string => ':'.Str::kebab($key).'="$'.$key.'"')
            ->implode(' ');
    }

    private function svgOf(string $html): string
    {
        $start = strpos($html, '<svg');
        $end = strrpos($html, '</svg>');

        $this->assertNotFalse($start, 'No SVG was rendered.');
        $this->assertNotFalse($end, 'No SVG was rendered.');

        return substr($html, $start, $end - $start);
    }
}
