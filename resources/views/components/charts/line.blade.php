@props([
    // [['label' => string, 'value' => float, 'measured' => bool], ...]
    'series' => [],
    'height' => 120,
    'empty' => 'Not enough history to plot a trend.',
    'formatter' => null,

    /*
     * The nominal width of the coordinate space, which is also the widest the
     * chart will render.
     *
     * The SVG scales uniformly to the space it is given, so width and height
     * move together — right in a half-width card, wrong in a full-width one,
     * where an uncapped chart renders far taller than it was drawn for.
     * Capping it keeps every chart on the page the same size.
     */
    'width' => 480,
])

@php
    /**
     * A trend line, drawn as one self-contained inline SVG.
     *
     * Weeks that measured nothing are gaps, not zeroes. A median cycle time of
     * "0" would render as a week where everything closed instantly, which is
     * the opposite of what an empty week means — so the path breaks and resumes.
     *
     * The axis labels and the peak are <text> inside the SVG. They used to be
     * HTML divs underneath it, which meant the PNG and SVG downloads
     * (resources/js/chart-export.js) serialised a line with no scale and no
     * dates on it — and the wrapper's buttons never fired at all, because the
     * exporter looks for `[data-chart] svg` and there was no `data-chart` here.
     *
     * The coordinate space is nominal and scaled uniformly to the card's width
     * (w-full, h-auto, default preserveAspectRatio) rather than the 0..100 box
     * with `preserveAspectRatio="none"` it used before: stretching the box
     * stretches the type in it, and a chart with distorted labels is not worth
     * exporting.
     */
    $rows = collect($series)->values();
    $measured = $rows->filter(fn ($row) => ($row['measured'] ?? true) === true);
    $max = (float) max(0.001, $measured->max('value') ?? 0);

    $count = max(1, $rows->count());

    $width = (float) $width;
    $plotTop = 15.0;
    $plotBottom = $plotTop + (float) $height;
    $canvas = $plotBottom + 19.0;

    // Inset, so a marker on the first or last week is not half outside the
    // picture.
    $left = 4.0;
    $right = $width - 4.0;

    $x = fn (int $index): float => $count < 2
        ? ($left + $right) / 2
        : round($left + ($index / ($count - 1)) * ($right - $left), 2);

    $y = fn (float $value): float => round($plotBottom - ($value / $max) * ($plotBottom - $plotTop), 2);

    // Build the path in segments so an unmeasured week interrupts the line
    // rather than being interpolated through.
    $segments = [];
    $current = [];

    foreach ($rows as $index => $row) {
        if (($row['measured'] ?? true) !== true) {
            if (count($current) > 0) {
                $segments[] = $current;
                $current = [];
            }

            continue;
        }

        $current[] = [$x($index), $y((float) ($row['value'] ?? 0)), (string) ($row['label'] ?? ''), (float) ($row['value'] ?? 0)];
    }

    if (count($current) > 0) {
        $segments[] = $current;
    }

    $format = $formatter ?: fn ($value) => (string) round((float) $value, 1);
    $every = (int) max(1, ceil($count / 8));
@endphp

@if ($measured->isEmpty())
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div data-chart>
        <svg
            viewBox="0 0 {{ $width }} {{ $canvas }}"
            class="mx-auto h-auto w-full"
            style="max-width: {{ $width }}px"
            role="img"
            aria-label="Trend across {{ $rows->count() }} weeks, peak {{ $format($max) }}"
        >
            {{-- The value axis: just the peak, at the top of the plot. --}}
            <text x="1" y="10" class="fill-slate-400" style="font-size: 10px; font-family: inherit">
                Peak {{ $format($max) }}
            </text>

            <line
                x1="0" y1="{{ $plotBottom }}" x2="{{ $width }}" y2="{{ $plotBottom }}"
                stroke="currentColor" class="text-slate-200" stroke-width="1"
                vector-effect="non-scaling-stroke"
            />

            @foreach ($segments as $points)
                @if (count($points) > 1)
                    <polyline
                        points="{{ collect($points)->map(fn ($p) => $p[0].','.$p[1])->implode(' ') }}"
                        fill="none"
                        stroke="currentColor"
                        class="text-brand-500"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                    />
                @endif

                @foreach ($points as $point)
                    <circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="{{ $count > 26 ? 2 : 3 }}" class="fill-brand-500">
                        <title>{{ $point[2] }}: {{ $format($point[3]) }}</title>
                    </circle>
                @endforeach
            @endforeach

            {{-- The time axis, thinned rather than rotated when a long range
                 would crowd it, and anchored to the ends at the extremes so a
                 label cannot hang off the edge of the picture. --}}
            @foreach ($rows as $index => $row)
                @if ($index % $every === 0)
                    <text
                        x="{{ $x($index) }}"
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
