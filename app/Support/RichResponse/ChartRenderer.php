<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

/**
 * Draws a validated ChartSpec as an SVG, server-side.
 *
 * Why not a charting library
 * --------------------------
 * The requirement is that the model must not be able to inject JavaScript, and
 * the strongest way to satisfy it is for there to be no JavaScript to inject
 * into. A client-side library would mean handing model-derived configuration to
 * a library whose options include callbacks and formatters — every one of which
 * is a place where a string becomes code, in a library nobody here has audited
 * for that. Drawing numbers into path coordinates in PHP has no such surface.
 *
 * It also avoids adding a front-end dependency to a project that has none, and
 * it means a chart renders in an email, a print, or a page with JavaScript off.
 *
 * What the model can and cannot influence
 * ---------------------------------------
 * It supplies numbers, labels and a type from a fixed list of six. It does not
 * supply colours — those come from the palette below and are chosen by index —
 * dimensions, fonts, or any attribute value that is not a coordinate this class
 * computed. Labels are the only model-authored strings that reach the output,
 * and they are escaped as XML text.
 *
 * The output is a fragment, not a document: no doctype, no XML declaration, no
 * script element, no foreignObject. It is inlined into the page by Blade with
 * `{!! !!}`, which is safe precisely because every part of it was generated
 * here rather than passed through.
 *
 * Geometry
 * --------
 * A fixed 720×360 viewBox with a responsive width, so the chart scales to its
 * container without recomputation and stays legible on a phone. Nothing here
 * measures text — a label's width is estimated from its length, which is
 * approximate and entirely sufficient for deciding whether to rotate the axis.
 */
class ChartRenderer
{
    private const WIDTH = 720;

    private const HEIGHT = 360;

    /**
     * The series palette.
     *
     * Nexora's brand hue first, then a sequence chosen to stay distinguishable
     * both next to each other and in greyscale — a chart that is exported and
     * printed should not lose its legend. Fixed, and indexed by series
     * position: a model cannot name a colour.
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#4f46e5',
        '#0ea5e9',
        '#10b981',
        '#f59e0b',
        '#ec4899',
        '#8b5cf6',
        '#14b8a6',
        '#f43f5e',
    ];

    /**
     * The chrome, in both appearances.
     *
     * Two palettes rather than `currentColor`, and that is the load-bearing
     * decision here. The SVG has to stay self-contained: the PNG export
     * serialises it and rasterises it through a canvas, at which point no CSS
     * from the page applies — so a chart whose text colour came from an
     * inherited property would export with black or invisible labels. Explicit
     * `fill` attributes are what make the exported image look like the one on
     * screen.
     *
     * The series palette above is NOT duplicated, deliberately. resources/css/
     * app.css leaves accent shades 300–500 alone in dark mode precisely because
     * they are "focus rings, chart marks and status dots, where a vivid colour
     * is correct on either ground" — so the marks stay as they are and only the
     * grid, the axis and the text move.
     *
     * @var array<string, array{grid: string, axis: string, text: string, ink: string}>
     */
    private const CHROME = [
        'light' => [
            'grid' => '#e2e8f0',
            'axis' => '#94a3b8',
            'text' => '#475569',
            'ink' => '#0f172a',
        ],
        'dark' => [
            // Mirrors the neutral remapping in app.css: the grid sinks into the
            // dark surface, the secondary text stays at slate-400 (which that
            // stylesheet notes is already correct on a dark ground), and the
            // title lifts to near-white.
            'grid' => '#30415a',
            'axis' => '#64748b',
            'text' => '#94a3b8',
            'ink' => '#e2e8f0',
        ],
    ];

    /**
     * Which appearance the chart being drawn is for.
     *
     * Instance state on a container singleton, which is safe because a render
     * is synchronous and self-contained: it is set on entry to render() and
     * every method that reads it runs before render() returns. Threading a
     * theme argument through fourteen private methods would be more correct and
     * much less readable, and the class draws one chart at a time.
     */
    private string $appearance = 'light';

    /**
     * Draw a chart.
     *
     * `$dark` picks the chrome. Both appearances are rendered into the page by
     * the chart component and CSS shows one — see
     * resources/views/components/ai/rich-chart.blade.php for why that is
     * preferred over restyling one SVG.
     */
    public function render(ChartSpec $chart, bool $dark = false): string
    {
        $this->appearance = $dark ? 'dark' : 'light';

        $body = match (true) {
            $chart->isCircular() => $this->circular($chart),
            $chart->type === 'hbar' => $this->horizontal($chart),
            default => $this->cartesian($chart),
        };

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" '
            .'preserveAspectRatio="xMidYMid meet" role="img" aria-label="%s" '
            .'style="max-width:100%%;height:auto;display:block">%s</svg>',
            self::WIDTH,
            self::HEIGHT,
            $this->escape($this->description($chart)),
            $body
        );
    }

    /**
     * The accessible description.
     *
     * A chart is a picture, so a screen reader gets a sentence instead. The
     * data itself is also on the page as an exportable table, which is the
     * better answer for anybody who cannot see the picture.
     */
    private function description(ChartSpec $chart): string
    {
        $series = implode(', ', array_map(
            static fn (array $dataset): string => $dataset['label'],
            $chart->datasets
        ));

        return trim(sprintf(
            '%s chart%s. %d points. Series: %s.',
            ucfirst($chart->type),
            $chart->title === null ? '' : ': '.$chart->title,
            count($chart->labels),
            $series === '' ? 'unnamed' : $series
        ));
    }

    // -----------------------------------------------------------------
    // Bars, lines, areas, scatter
    // -----------------------------------------------------------------

    private function cartesian(ChartSpec $chart): string
    {
        $left = 64;
        $right = 16;
        $top = $chart->title === null ? 20 : 44;
        $bottom = $this->rotateLabels($chart) ? 76 : 52;

        $plotWidth = self::WIDTH - $left - $right;
        $plotHeight = self::HEIGHT - $top - $bottom;

        $max = $chart->maximum();
        $min = $chart->minimum();

        // A flat series still needs a scale, or every point lands on the axis.
        if ($max === $min) {
            $max = $max === 0.0 ? 1.0 : $max * 1.2;
        }

        [$niceMin, $niceMax, $step] = $this->scale($min, $max);

        $parts = [];

        if ($chart->title !== null) {
            $parts[] = $this->text($left, 24, $chart->title, 15, $this->chrome('ink'), 'start', 600);
        }

        $parts[] = $this->grid($left, $top, $plotWidth, $plotHeight, $niceMin, $niceMax, $step);

        $parts[] = match ($chart->type) {
            'line' => $this->lines($chart, $left, $top, $plotWidth, $plotHeight, $niceMin, $niceMax, false),
            'area' => $this->lines($chart, $left, $top, $plotWidth, $plotHeight, $niceMin, $niceMax, true),
            'scatter' => $this->points($chart, $left, $top, $plotWidth, $plotHeight, $niceMin, $niceMax),
            default => $this->bars($chart, $left, $top, $plotWidth, $plotHeight, $niceMin, $niceMax),
        };

        $parts[] = $this->xAxis($chart, $left, $top + $plotHeight, $plotWidth);

        if ($chart->seriesCount() > 1) {
            $parts[] = $this->legend($chart, $left, self::HEIGHT - 12);
        }

        if ($chart->yLabel !== null) {
            $parts[] = sprintf(
                '<text x="14" y="%d" transform="rotate(-90 14 %d)" text-anchor="middle" '
                .'font-size="11" fill="%s" font-family="system-ui, sans-serif">%s</text>',
                (int) ($top + $plotHeight / 2),
                (int) ($top + $plotHeight / 2),
                $this->chrome('text'),
                $this->escape($chart->yLabel)
            );
        }

        return implode('', $parts);
    }

    /**
     * Horizontal gridlines and the value axis.
     */
    private function grid(
        int $left,
        int $top,
        int $width,
        int $height,
        float $min,
        float $max,
        float $step,
    ): string {
        $parts = [];

        for ($value = $min; $value <= $max + 0.0001; $value += $step) {
            $y = $this->y($value, $min, $max, $top, $height);

            $parts[] = sprintf(
                '<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="%s" stroke-width="1" />',
                $left,
                $y,
                $left + $width,
                $y,
                $value === 0.0 ? $this->chrome('axis') : $this->chrome('grid')
            );

            $parts[] = $this->text($left - 8, $y + 4, $this->number($value), 11, $this->chrome('text'), 'end');
        }

        return implode('', $parts);
    }

    private function bars(
        ChartSpec $chart,
        int $left,
        int $top,
        int $width,
        int $height,
        float $min,
        float $max,
    ): string {
        $count = max(1, count($chart->labels));
        $slot = $width / $count;

        // A gap of a fifth of the slot, so bars are separated without becoming
        // spindly when there are many of them.
        $inner = $slot * 0.8;
        $series = $chart->seriesCount();
        $barWidth = $chart->stacked ? $inner : $inner / max(1, $series);

        $parts = [];
        $baseline = $this->y(max(0.0, $min), $min, $max, $top, $height);

        foreach ($chart->labels as $index => $label) {
            $slotLeft = $left + $slot * $index + ($slot - $inner) / 2;
            $stackTop = $baseline;

            foreach ($chart->datasets as $seriesIndex => $dataset) {
                $value = $dataset['values'][$index] ?? null;

                if ($value === null) {
                    continue;
                }

                $colour = $this->colour($seriesIndex);

                if ($chart->stacked) {
                    $barHeight = abs($this->y($value, $min, $max, $top, $height) - $baseline);
                    $stackTop -= $barHeight;

                    $parts[] = $this->rect($slotLeft, $stackTop, $barWidth, $barHeight, $colour, $label, $dataset['label'], $value);

                    continue;
                }

                $y = $this->y($value, $min, $max, $top, $height);
                $barTop = min($y, $baseline);
                $barHeight = max(1.0, abs($baseline - $y));

                $parts[] = $this->rect(
                    $slotLeft + $barWidth * $seriesIndex,
                    $barTop,
                    $barWidth,
                    $barHeight,
                    $colour,
                    $label,
                    $dataset['label'],
                    $value
                );
            }
        }

        return implode('', $parts);
    }

    private function lines(
        ChartSpec $chart,
        int $left,
        int $top,
        int $width,
        int $height,
        float $min,
        float $max,
        bool $filled,
    ): string {
        $count = max(1, count($chart->labels));

        // A single point has no interval, so it is centred rather than pinned
        // to the left edge.
        $step = $count > 1 ? $width / ($count - 1) : 0.0;
        $offset = $count > 1 ? 0.0 : $width / 2;

        $parts = [];

        foreach ($chart->datasets as $seriesIndex => $dataset) {
            $colour = $this->colour($seriesIndex);

            /*
             * Runs, not one path.
             *
             * A null in a series is a gap, and drawing through it would invent
             * a value. Each unbroken run becomes its own path, so a missing
             * month is a break in the line.
             *
             * @var list<list<array{0: float, 1: float}>> $runs
             */
            $runs = [];
            $current = [];

            foreach ($dataset['values'] as $index => $value) {
                if ($value === null) {
                    if ($current !== []) {
                        $runs[] = $current;
                        $current = [];
                    }

                    continue;
                }

                $current[] = [
                    $left + $offset + $step * $index,
                    $this->y($value, $min, $max, $top, $height),
                ];
            }

            if ($current !== []) {
                $runs[] = $current;
            }

            foreach ($runs as $run) {
                if (count($run) === 1) {
                    // A run of one cannot be a line, so it is drawn as a dot —
                    // otherwise a series of isolated readings renders as
                    // nothing at all.
                    $parts[] = sprintf(
                        '<circle cx="%.2f" cy="%.2f" r="3.5" fill="%s" />',
                        $run[0][0],
                        $run[0][1],
                        $colour
                    );

                    continue;
                }

                $points = implode(' ', array_map(
                    static fn (array $point): string => sprintf('%.2f,%.2f', $point[0], $point[1]),
                    $run
                ));

                if ($filled) {
                    $baseline = $this->y(max(0.0, $min), $min, $max, $top, $height);

                    $parts[] = sprintf(
                        '<polygon points="%.2f,%.2f %s %.2f,%.2f" fill="%s" fill-opacity="0.16" />',
                        $run[0][0],
                        $baseline,
                        $points,
                        $run[count($run) - 1][0],
                        $baseline,
                        $colour
                    );
                }

                $parts[] = sprintf(
                    '<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5" '
                    .'stroke-linejoin="round" stroke-linecap="round" />',
                    $points,
                    $colour
                );
            }
        }

        return implode('', $parts);
    }

    private function points(
        ChartSpec $chart,
        int $left,
        int $top,
        int $width,
        int $height,
        float $min,
        float $max,
    ): string {
        $count = max(1, count($chart->labels));
        $step = $count > 1 ? $width / ($count - 1) : 0.0;
        $offset = $count > 1 ? 0.0 : $width / 2;

        $parts = [];

        foreach ($chart->datasets as $seriesIndex => $dataset) {
            $colour = $this->colour($seriesIndex);

            foreach ($dataset['values'] as $index => $value) {
                if ($value === null) {
                    continue;
                }

                $parts[] = sprintf(
                    '<circle cx="%.2f" cy="%.2f" r="4" fill="%s" fill-opacity="0.75">'
                    .'<title>%s: %s</title></circle>',
                    $left + $offset + $step * $index,
                    $this->y($value, $min, $max, $top, $height),
                    $colour,
                    $this->escape(($chart->labels[$index] ?? '').' · '.$dataset['label']),
                    $this->escape($this->number($value))
                );
            }
        }

        return implode('', $parts);
    }

    // -----------------------------------------------------------------
    // Pie and donut
    // -----------------------------------------------------------------

    private function circular(ChartSpec $chart): string
    {
        $values = $chart->datasets[0]['values'] ?? [];

        $total = 0.0;

        foreach ($values as $value) {
            $total += max(0.0, (float) ($value ?? 0.0));
        }

        if ($total <= 0.0) {
            return $this->text(
                self::WIDTH / 2,
                self::HEIGHT / 2,
                'No positive values to chart',
                13,
                $this->chrome('text'),
                'middle'
            );
        }

        $centreX = 240;
        $centreY = self::HEIGHT / 2 + ($chart->title === null ? 0 : 10);
        $radius = 130;
        $inner = $chart->type === 'donut' ? $radius * 0.58 : 0.0;

        $parts = [];

        if ($chart->title !== null) {
            $parts[] = $this->text(32, 24, $chart->title, 15, $this->chrome('ink'), 'start', 600);
        }

        $angle = -M_PI / 2;

        foreach ($values as $index => $value) {
            $value = max(0.0, (float) ($value ?? 0.0));

            if ($value <= 0.0) {
                continue;
            }

            $sweep = ($value / $total) * 2 * M_PI;
            $end = $angle + $sweep;
            $label = $chart->labels[$index] ?? (string) ($index + 1);

            $parts[] = sprintf(
                '<path d="%s" fill="%s"><title>%s: %s (%s)</title></path>',
                $this->slice($centreX, $centreY, $radius, $inner, $angle, $end),
                $this->colour($index),
                $this->escape($label),
                $this->escape($this->number($value)),
                $this->escape($this->percentage($value / $total))
            );

            $angle = $end;
        }

        // A legend rather than labels around the rim: slice labels collide as
        // soon as two slices are small, and this data always has names.
        $parts[] = $this->verticalLegend($chart, $values, $total, 420, 60);

        return implode('', $parts);
    }

    /**
     * One slice, as a path.
     *
     * Split at half a turn because an SVG arc cannot express a sweep of more
     * than 180° in one segment — a single slice covering three quarters of a
     * pie would otherwise be drawn as the quarter that is left.
     */
    private function slice(
        float $centreX,
        float $centreY,
        float $radius,
        float $inner,
        float $from,
        float $to,
    ): string {
        $large = ($to - $from) > M_PI ? 1 : 0;

        $outerStart = $this->polar($centreX, $centreY, $radius, $from);
        $outerEnd = $this->polar($centreX, $centreY, $radius, $to);

        if ($inner <= 0.0) {
            return sprintf(
                'M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z',
                $centreX,
                $centreY,
                $outerStart[0],
                $outerStart[1],
                $radius,
                $radius,
                $large,
                $outerEnd[0],
                $outerEnd[1]
            );
        }

        $innerEnd = $this->polar($centreX, $centreY, $inner, $to);
        $innerStart = $this->polar($centreX, $centreY, $inner, $from);

        return sprintf(
            'M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z',
            $outerStart[0],
            $outerStart[1],
            $radius,
            $radius,
            $large,
            $outerEnd[0],
            $outerEnd[1],
            $innerEnd[0],
            $innerEnd[1],
            $inner,
            $inner,
            $large,
            $innerStart[0],
            $innerStart[1]
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function polar(float $centreX, float $centreY, float $radius, float $angle): array
    {
        return [$centreX + $radius * cos($angle), $centreY + $radius * sin($angle)];
    }

    // -----------------------------------------------------------------
    // Axes and legends
    // -----------------------------------------------------------------

    private function xAxis(ChartSpec $chart, int $left, int $baseline, int $width): string
    {
        $count = max(1, count($chart->labels));
        $rotate = $this->rotateLabels($chart);

        $parts = [sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" />',
            $left,
            $baseline,
            $left + $width,
            $baseline,
            $this->chrome('axis')
        )];

        /*
         * Every label when they fit, otherwise every nth.
         *
         * Overlapping text is worse than absent text: a chart of ninety days
         * with a label per day is an illegible grey smear along the bottom.
         */
        $stride = (int) max(1, ceil($count / ($rotate ? 24 : 12)));

        $isBar = $chart->type === 'bar';
        $slot = $width / $count;
        $step = $count > 1 ? $width / ($count - 1) : 0.0;

        foreach ($chart->labels as $index => $label) {
            if ($index % $stride !== 0 || trim($label) === '') {
                continue;
            }

            $x = $isBar
                ? $left + $slot * $index + $slot / 2
                : $left + ($count > 1 ? $step * $index : $width / 2);

            $parts[] = $rotate
                ? sprintf(
                    '<text x="%.2f" y="%d" transform="rotate(-40 %.2f %d)" text-anchor="end" '
                    .'font-size="11" fill="%s" font-family="system-ui, sans-serif">%s</text>',
                    $x,
                    $baseline + 16,
                    $x,
                    $baseline + 16,
                    $this->chrome('text'),
                    $this->escape($label)
                )
                : $this->text($x, $baseline + 18, $label, 11, $this->chrome('text'), 'middle');
        }

        if ($chart->xLabel !== null) {
            $parts[] = $this->text(
                $left + $width / 2,
                self::HEIGHT - ($chart->seriesCount() > 1 ? 26 : 4),
                $chart->xLabel,
                11,
                $this->chrome('text'),
                'middle'
            );
        }

        return implode('', $parts);
    }

    /**
     * Would the labels collide if drawn flat?
     *
     * Estimated from character count rather than measured: nothing here can
     * measure a font, and the estimate only has to be right about whether to
     * rotate.
     */
    private function rotateLabels(ChartSpec $chart): bool
    {
        $count = max(1, count($chart->labels));
        $slot = (self::WIDTH - 80) / $count;

        $longest = 0;

        foreach ($chart->labels as $label) {
            $longest = max($longest, mb_strlen($label));
        }

        // Roughly six pixels per character at 11px.
        return $longest * 6 > $slot;
    }

    private function legend(ChartSpec $chart, int $left, int $y): string
    {
        $parts = [];
        $x = $left;

        foreach ($chart->datasets as $index => $dataset) {
            $parts[] = sprintf(
                '<rect x="%d" y="%d" width="10" height="10" rx="2" fill="%s" />',
                $x,
                $y - 9,
                $this->colour($index)
            );

            $parts[] = $this->text($x + 15, $y, $dataset['label'], 11, $this->chrome('text'), 'start');

            $x += 26 + (int) (mb_strlen($dataset['label']) * 6.2);
        }

        return implode('', $parts);
    }

    /**
     * The pie legend: swatch, name, value and share, one per line.
     *
     * @param  list<float|null>  $values
     */
    private function verticalLegend(ChartSpec $chart, array $values, float $total, int $x, int $y): string
    {
        $parts = [];
        $line = $y;

        foreach ($values as $index => $value) {
            $value = max(0.0, (float) ($value ?? 0.0));

            if ($value <= 0.0) {
                continue;
            }

            // Eleven lines is what fits; the rest are summarised.
            if ($line > self::HEIGHT - 40) {
                $remaining = count($values) - $index;

                $parts[] = $this->text($x + 18, $line, '+'.$remaining.' more', 11, $this->chrome('text'), 'start');

                break;
            }

            $parts[] = sprintf(
                '<rect x="%d" y="%d" width="10" height="10" rx="2" fill="%s" />',
                $x,
                $line - 9,
                $this->colour($index)
            );

            $parts[] = $this->text(
                $x + 18,
                $line,
                mb_substr($chart->labels[$index] ?? (string) ($index + 1), 0, 24)
                    .' — '.$this->number($value).' ('.$this->percentage($value / $total).')',
                11,
                $this->chrome('text'),
                'start'
            );

            $line += 22;
        }

        return implode('', $parts);
    }

    // -----------------------------------------------------------------
    // Primitives
    // -----------------------------------------------------------------

    private function rect(
        float $x,
        float $y,
        float $width,
        float $height,
        string $colour,
        string $label,
        string $series,
        float $value,
    ): string {
        return sprintf(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="2">'
            .'<title>%s: %s</title></rect>',
            $x,
            $y,
            max(1.0, $width - 1.5),
            max(1.0, $height),
            $colour,
            $this->escape(trim($label.' · '.$series, ' ·')),
            $this->escape($this->number($value))
        );
    }

    private function text(
        float $x,
        float $y,
        string $content,
        int $size,
        string $fill,
        string $anchor = 'start',
        int $weight = 400,
    ): string {
        return sprintf(
            '<text x="%.2f" y="%.2f" text-anchor="%s" font-size="%d" font-weight="%d" fill="%s" '
            .'font-family="system-ui, -apple-system, sans-serif">%s</text>',
            $x,
            $y,
            $anchor,
            $size,
            $weight,
            $fill,
            $this->escape($content)
        );
    }

    /**
     * A value's vertical position in the plot.
     */
    private function y(float $value, float $min, float $max, int $top, int $height): float
    {
        $span = $max - $min;

        if ($span <= 0.0) {
            return $top + $height;
        }

        return $top + $height - (($value - $min) / $span) * $height;
    }

    /**
     * A scale with round numbers on it.
     *
     * A gridline at 8,333 tells nobody anything. The step is rounded up to the
     * nearest 1, 2 or 5 times a power of ten, which is what makes an axis read
     * as 0 / 50 / 100 rather than as arbitrary thirds of the maximum.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function scale(float $min, float $max): array
    {
        $target = 5;
        $span = $max - $min;

        if ($span <= 0.0) {
            return [$min, $min + 1.0, 1.0];
        }

        $rough = $span / $target;
        $magnitude = 10 ** floor(log10($rough));
        $normalised = $rough / $magnitude;

        $step = match (true) {
            $normalised <= 1 => 1,
            $normalised <= 2 => 2,
            $normalised <= 5 => 5,
            default => 10,
        } * $magnitude;

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    private function colour(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * One chrome colour for the appearance being drawn.
     */
    private function chrome(string $role): string
    {
        return self::CHROME[$this->appearance][$role] ?? self::CHROME['light'][$role];
    }

    // -----------------------------------------------------------------
    // Horizontal bars
    // -----------------------------------------------------------------

    /**
     * A bar chart on its side: categories down the left, values across.
     *
     * Its own layout rather than a transposed `cartesian()`, because almost
     * nothing carries over. The wide margin is on the left instead of the
     * bottom, the gridlines are vertical, the scale runs along the x axis, and
     * the labels need no rotation — which is the entire reason to draw one.
     *
     * The left margin is measured from the longest label rather than fixed, so
     * "Alexandra Bell-Hopkins" is not clipped and "Bug" does not leave a third
     * of the frame empty. Clamped at both ends: a runaway label would otherwise
     * squeeze the plot to nothing.
     */
    private function horizontal(ChartSpec $chart): string
    {
        $longest = 0;

        foreach ($chart->labels as $label) {
            $longest = max($longest, mb_strlen($label));
        }

        $left = (int) max(80, min(240, $longest * 6.6 + 16));
        $right = 24;
        $top = $chart->title === null ? 20 : 48;
        $bottom = $chart->seriesCount() > 1 ? 46 : 30;

        $plotWidth = self::WIDTH - $left - $right;
        $plotHeight = self::HEIGHT - $top - $bottom;

        $max = $chart->maximum();
        $min = min(0.0, $chart->minimum());

        if ($max === $min) {
            $max = $max === 0.0 ? 1.0 : $max * 1.2;
        }

        [$niceMin, $niceMax, $step] = $this->scale($min, $max);

        $parts = [];

        if ($chart->title !== null) {
            $parts[] = $this->text(24, 26, $chart->title, 15, $this->chrome('ink'), 'start', 600);
        }

        // Vertical gridlines with the value scale underneath them.
        for ($value = $niceMin; $value <= $niceMax + $step / 2; $value += $step) {
            $x = $this->across($value, $niceMin, $niceMax, $left, $plotWidth);

            $parts[] = sprintf(
                '<line x1="%.2f" y1="%d" x2="%.2f" y2="%d" stroke="%s" stroke-width="1" />',
                $x,
                $top,
                $x,
                $top + $plotHeight,
                $value === 0.0 ? $this->chrome('axis') : $this->chrome('grid')
            );

            $parts[] = $this->text($x, $top + $plotHeight + 16, $this->number($value), 11, $this->chrome('text'), 'middle');
        }

        $count = max(1, count($chart->labels));
        $slot = $plotHeight / $count;
        $inner = $slot * 0.76;
        $series = $chart->seriesCount();
        $barHeight = $chart->stacked ? $inner : $inner / max(1, $series);
        $baseline = $this->across(max(0.0, $niceMin), $niceMin, $niceMax, $left, $plotWidth);

        foreach ($chart->labels as $index => $label) {
            $slotTop = $top + $slot * $index + ($slot - $inner) / 2;
            $stackLeft = $baseline;

            // The category label, centred on its slot and beside its bar.
            $parts[] = $this->text(
                $left - 10,
                $top + $slot * $index + $slot / 2 + 4,
                $this->truncate($label, (int) floor(($left - 20) / 6.4)),
                11,
                $this->chrome('text'),
                'end'
            );

            foreach ($chart->datasets as $seriesIndex => $dataset) {
                $value = $dataset['values'][$index] ?? null;

                if ($value === null) {
                    continue;
                }

                $x = $this->across($value, $niceMin, $niceMax, $left, $plotWidth);

                if ($chart->stacked) {
                    $width = abs($x - $baseline);

                    $parts[] = $this->rect(
                        $stackLeft,
                        $slotTop,
                        $width,
                        $barHeight,
                        $this->colour($seriesIndex),
                        $label,
                        $dataset['label'],
                        $value
                    );

                    $stackLeft += $width;

                    continue;
                }

                $parts[] = $this->rect(
                    min($x, $baseline),
                    $slotTop + $barHeight * $seriesIndex,
                    max(1.0, abs($x - $baseline)),
                    $barHeight,
                    $this->colour($seriesIndex),
                    $label,
                    $dataset['label'],
                    $value
                );
            }
        }

        if ($chart->seriesCount() > 1) {
            $parts[] = $this->legend($chart, $left, self::HEIGHT - 10);
        }

        return implode('', $parts);
    }

    /**
     * Where a value sits along the horizontal value axis.
     *
     * The mirror of y(): left to right rather than bottom to top, and without
     * the inversion, because SVG's x already runs the way a value axis does.
     */
    private function across(float $value, float $min, float $max, int $left, int $width): float
    {
        $span = $max - $min;

        if ($span <= 0.0) {
            return $left;
        }

        return $left + (($value - $min) / $span) * $width;
    }

    /**
     * A label cut to fit, with an ellipsis rather than a hard edge.
     */
    private function truncate(string $label, int $characters): string
    {
        $characters = max(6, $characters);

        return mb_strlen($label) <= $characters
            ? $label
            : mb_substr($label, 0, $characters - 1).'…';
    }

    /**
     * A number as an axis or a tooltip shows it.
     *
     * Abbreviated above a thousand, because an axis of 1,000,000 / 2,000,000 is
     * mostly commas. Small values keep their decimals: a chart of conversion
     * rates whose axis rounded to whole numbers would be a chart of zeroes.
     */
    private function number(float $value): string
    {
        $absolute = abs($value);

        if ($absolute >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.').'M';
        }

        if ($absolute >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'k';
        }

        if ($absolute > 0 && $absolute < 1) {
            return rtrim(rtrim(number_format($value, 3), '0'), '.');
        }

        return $value === floor($value)
            ? number_format($value)
            : rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private function percentage(float $fraction): string
    {
        return number_format($fraction * 100, $fraction < 0.01 ? 1 : 0).'%';
    }

    /**
     * XML-escape a model-authored string.
     *
     * The only place model output reaches the SVG, and it reaches it as a text
     * node. `ENT_XML1` rather than `ENT_HTML5`, because this is XML: an
     * HTML-flavoured escape leaves `&apos;` unencoded, which is valid HTML and
     * not valid XML.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
