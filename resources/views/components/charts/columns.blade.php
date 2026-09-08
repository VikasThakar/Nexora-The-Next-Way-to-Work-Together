@props([
    // [['label' => string, 'value' => int], ...] or, when $stacked, each row
    // may carry ['completed' => int, 'failed' => int].
    'series' => [],
    'height' => 132,
    'empty' => 'No activity in this period.',
    'stacked' => false,
    'unit' => '',

    /*
     * The nominal width of the coordinate space, which is also the widest the
     * chart will render.
     *
     * The SVG scales uniformly to the space it is given, so width and height
     * move together — which is right in a half-width card and wrong in a
     * full-width one, where an uncapped chart would render three times as tall
     * as it was drawn for. Capping it keeps every chart on the page the same
     * size; a chart that genuinely has the room to be wider is given a larger
     * number here.
     */
    'width' => 480,
])

@php
    use Illuminate\Support\Str;

    /**
     * A vertical bar chart for a weekly series, drawn as one self-contained
     * inline SVG.
     *
     * Self-contained is the requirement rather than a preference, and it is why
     * this is no longer a row of divs with heights. The axis labels and the
     * peak are <text> inside the SVG because this element is what the PNG and
     * SVG downloads serialise (resources/js/chart-export.js): a label in a
     * sibling div is on the screen and missing from every exported file, which
     * is the sort of bug nobody notices until a chart is in a client's deck.
     *
     * The server still knows every height, so there is no scale computed in the
     * browser and no charting library to compute it with — the geometry below
     * is the same arithmetic the divs used, expressed in user units.
     *
     * Colours are Tailwind classes on the elements, resolved through
     * getComputedStyle before serialising, so the palette stays the product's.
     *
     * The coordinate space is scaled uniformly to the card's width, capped at
     * the nominal width above: `preserveAspectRatio="none"` would stretch the
     * <text> along with the bars, and a chart is not worth exporting if its
     * labels are distorted.
     *
     * Labels are thinned rather than rotated when a long range would crowd
     * them: an unreadable axis is worse than a sparse one.
     */
    $rows = collect($series)->values();

    $valueOf = fn ($row) => $stacked
        ? (int) ($row['completed'] ?? 0) + (int) ($row['failed'] ?? 0)
        : (int) ($row['value'] ?? 0);

    $max = max(1, (int) $rows->map($valueOf)->max());
    $count = max(1, $rows->count());
    $every = (int) max(1, ceil($count / 12));

    // "1 tickets" is not a number of tickets. Str::plural($unit, $count) does
    // not settle this on its own: handed a word that is already plural it
    // returns it unchanged whatever the count, so the singular is asked for.
    $unitFor = fn (int $n): string => $unit === ''
        ? ''
        : ' '.($n === 1 ? Str::singular($unit) : Str::plural($unit));

    $width = (float) $width;
    $plotTop = 13.0;
    $plotBottom = $plotTop + (float) $height;
    $canvas = $plotBottom + 19.0;

    // One slot per bucket, with a quarter of it given to the gap — capped, so a
    // short series is columns rather than slabs.
    $slot = $width / $count;
    $gap = min(5.0, $slot * 0.25);
    $bar = max(1.0, $slot - $gap);

    $barX = fn (int $index): float => round($index * $slot + $gap / 2, 2);

    // Heights are relative to the tallest bar, as they were before. A non-zero
    // value keeps a visible sliver so "a little" never reads as "nothing".
    $barHeight = function (int $value) use ($max, $height): float {
        if ($value <= 0) {
            return 1.0;
        }

        return round(max($value / $max * (float) $height, 3.0), 2);
    };
@endphp

@if ($rows->isEmpty() || $rows->sum($valueOf) === 0)
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div data-chart>
        <svg
            viewBox="0 0 {{ $width }} {{ $canvas }}"
            class="mx-auto h-auto w-full"
            style="max-width: {{ $width }}px"
            role="img"
            aria-label="{{ $rows->count() }} weekly {{ Str::plural('bucket', $rows->count()) }}, peak {{ $max }}{{ $unitFor($max) }}"
        >
            {{-- The value axis: just the peak. Two more numbers would crowd a
                 chart this size without saying more. --}}
            <text x="1" y="9" class="fill-slate-400" style="font-size: 10px; font-family: inherit">
                {{ number_format($max) }}{{ $unitFor($max) }}
            </text>

            @foreach ($rows as $index => $row)
                @php
                    $total = $valueOf($row);
                    $x = $barX($index);
                @endphp

                <g>
                    {{-- A <title> is the SVG tooltip, and unlike a title
                         attribute on a div it survives into the exported file. --}}
                    <title>{{ $row['label'] }}: {{ $stacked
                        ? ((int) ($row['completed'] ?? 0)).' completed, '.((int) ($row['failed'] ?? 0)).' failed'
                        : number_format($total).$unitFor($total) }}</title>

                    @if ($stacked)
                        @php
                            $failed = (int) ($row['failed'] ?? 0);
                            $completed = (int) ($row['completed'] ?? 0);

                            // Stacked from the baseline up: completed first,
                            // failed above it, so the red sits at the top of
                            // the column as the card's caption promises.
                            $completedHeight = $completed > 0 ? $barHeight($completed) : 0.0;
                            $failedHeight = $failed > 0 ? $barHeight($failed) : 0.0;
                        @endphp

                        @if ($completedHeight > 0)
                            <rect
                                x="{{ $x }}" y="{{ round($plotBottom - $completedHeight, 2) }}"
                                width="{{ round($bar, 2) }}" height="{{ $completedHeight }}"
                                class="fill-brand-500"
                            />
                        @endif

                        @if ($failedHeight > 0)
                            <rect
                                x="{{ $x }}"
                                y="{{ round($plotBottom - $completedHeight - $failedHeight, 2) }}"
                                width="{{ round($bar, 2) }}" height="{{ $failedHeight }}"
                                class="fill-rose-400" rx="1.5"
                            />
                        @endif
                    @else
                        @php $barSize = $barHeight($total); @endphp

                        <rect
                            x="{{ $x }}" y="{{ round($plotBottom - $barSize, 2) }}"
                            width="{{ round($bar, 2) }}" height="{{ $barSize }}"
                            class="{{ $total > 0 ? 'fill-brand-500' : 'fill-slate-200' }}"
                            rx="1.5"
                        />
                    @endif
                </g>
            @endforeach

            <line
                x1="0" y1="{{ $plotBottom }}" x2="{{ $width }}" y2="{{ $plotBottom }}"
                stroke="currentColor" class="text-slate-200" stroke-width="1"
                vector-effect="non-scaling-stroke"
            />

            {{-- The time axis. Anchored to the ends at the extremes so a label
                 cannot hang off the edge of the picture. --}}
            @foreach ($rows as $index => $row)
                @if ($index % $every === 0)
                    <text
                        x="{{ round($barX($index) + $bar / 2, 2) }}"
                        y="{{ $plotBottom + 13 }}"
                        class="fill-slate-400"
                        style="font-size: 10px; font-family: inherit"
                        text-anchor="{{ $index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle') }}"
                    >{{ $row['label'] }}</text>
                @endif
            @endforeach
        </svg>
    </div>
@endif
