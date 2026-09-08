@props([
    /*
     * One or more named series over the same buckets:
     *
     *   [['key' => 'created', 'label' => 'Created', 'tone' => 'brand',
     *     'points' => [['label' => 'w1', 'value' => 4], ...]], ...]
     *
     * Every series must carry the same labels in the same order — the caller
     * aligns them, because only the caller knows which weeks exist.
     */
    'series' => [],
    'height' => 200,
    'empty' => 'Nothing to plot in this period.',

    // Filled area under each line, which is what makes two overlapping series
    // readable as volumes rather than as two wires.
    'area' => true,
])

@php
    /**
     * A line-and-area chart, drawn as one self-contained SVG.
     *
     * Self-contained is the requirement, not a preference. The axis labels and
     * the legend are <text> inside the SVG rather than HTML beside it, because
     * this element is what the PNG and SVG downloads serialise
     * (resources/js/chart-export.js) — labels in sibling divs would be on the
     * screen and missing from every exported file, which is the sort of bug
     * nobody notices until a chart is in a client's slide deck.
     *
     * Colours are Tailwind classes on the elements, and the exporter resolves
     * them through getComputedStyle before serialising. Named tones rather than
     * arbitrary values so the palette stays the product's.
     *
     * Coordinates are a plain 0..100 grid with preserveAspectRatio="none": the
     * server already knows every point, so there is no scale to compute in the
     * browser and no library to compute it with.
     */
    $rows = collect($series)
        ->filter(fn ($s) => filled($s['points'] ?? []))
        ->values();

    $labels = collect($rows->first()['points'] ?? [])->pluck('label')->values();
    $count = $labels->count();

    $peak = (int) max(1, $rows->flatMap(fn ($s) => collect($s['points'])->pluck('value'))->max() ?? 0);

    // A little headroom, so the tallest point is not welded to the top edge.
    $ceiling = (float) max(1, $peak * 1.12);

    // The plot area inside the SVG's 0..100 box, leaving room for the axis
    // labels along the bottom and the legend along the top.
    $top = 14.0;
    $bottom = 84.0;
    $left = 0.0;
    $right = 100.0;

    $x = fn (int $index): float => $count < 2
        ? ($left + $right) / 2
        : round($left + ($index / ($count - 1)) * ($right - $left), 2);

    $y = fn (float $value): float => round($bottom - ($value / $ceiling) * ($bottom - $top), 2);

    $tones = [
        'brand' => ['stroke' => 'text-brand-500', 'fill' => 'fill-brand-500', 'swatch' => 'fill-brand-500'],
        'emerald' => ['stroke' => 'text-emerald-500', 'fill' => 'fill-emerald-500', 'swatch' => 'fill-emerald-500'],
        'slate' => ['stroke' => 'text-slate-400', 'fill' => 'fill-slate-400', 'swatch' => 'fill-slate-400'],
        'rose' => ['stroke' => 'text-rose-500', 'fill' => 'fill-rose-500', 'swatch' => 'fill-rose-500'],
    ];

    // Thinned rather than rotated when a long range would crowd them: an
    // unreadable axis is worse than a sparse one.
    $every = (int) max(1, ceil(max(1, $count) / 8));

    $total = (int) $rows->flatMap(fn ($s) => collect($s['points'])->pluck('value'))->sum();
@endphp

@if ($rows->isEmpty() || $total === 0)
    <p class="py-10 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div data-chart>
        <svg
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            class="w-full"
            style="height: {{ $height }}px"
            role="img"
            aria-label="{{ $rows->pluck('label')->implode(' and ') }} across {{ $count }} buckets, peak {{ $peak }}"
        >
            {{-- Three gridlines and a baseline. vector-effect keeps them hairlines
                 despite the non-uniform scale the viewBox imposes. --}}
            @foreach ([0.0, 0.5, 1.0] as $fraction)
                <line
                    x1="0" y1="{{ $y($ceiling * $fraction) }}"
                    x2="100" y2="{{ $y($ceiling * $fraction) }}"
                    stroke="currentColor"
                    class="text-slate-200"
                    stroke-width="0.4"
                    vector-effect="non-scaling-stroke"
                />
            @endforeach

            @foreach ($rows as $index => $line)
                @php
                    $tone = $tones[$line['tone'] ?? 'brand'] ?? $tones['brand'];
                    $points = collect($line['points'])->values();

                    $coords = $points->map(fn ($point, $i) => $x($i).','.$y((float) ($point['value'] ?? 0)));
                @endphp

                @if ($area && $count > 1)
                    {{-- The area is drawn first so the lines sit on top of it,
                         and at low opacity so two overlapping series stay
                         legible where they cross. --}}
                    <polygon
                        points="{{ $x(0) }},{{ $y(0) }} {{ $coords->implode(' ') }} {{ $x($count - 1) }},{{ $y(0) }}"
                        class="{{ $tone['fill'] }}"
                        fill-opacity="0.12"
                        stroke="none"
                    />
                @endif

                @if ($count > 1)
                    <polyline
                        points="{{ $coords->implode(' ') }}"
                        fill="none"
                        stroke="currentColor"
                        class="{{ $tone['stroke'] }}"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                    />
                @endif

                @foreach ($points as $i => $point)
                    <circle
                        cx="{{ $x($i) }}"
                        cy="{{ $y((float) ($point['value'] ?? 0)) }}"
                        r="{{ $count > 26 ? 1.4 : 2.2 }}"
                        class="{{ $tone['fill'] }}"
                        vector-effect="non-scaling-stroke"
                    >
                        <title>{{ $line['label'] }} · {{ $point['label'] }}: {{ number_format((int) ($point['value'] ?? 0)) }}</title>
                    </circle>
                @endforeach

                {{-- The legend, inside the SVG so it survives an export. --}}
                <circle cx="{{ 1.5 + $index * 26 }}" cy="5" r="2" class="{{ $tone['swatch'] }}" />
                <text
                    x="{{ 5 + $index * 26 }}"
                    y="6.6"
                    class="fill-slate-500"
                    style="font-size: 4.6px; font-family: inherit"
                >{{ $line['label'] }}</text>
            @endforeach

            {{-- The value axis: just the peak, at the top gridline. Two more
                 numbers would crowd a chart this size without saying more. --}}
            <text x="0.5" y="{{ $y($ceiling) + 3 }}" class="fill-slate-400"
                  style="font-size: 4.2px; font-family: inherit">{{ number_format($peak) }}</text>

            {{-- The time axis. --}}
            @foreach ($labels as $index => $label)
                @if ($index % $every === 0)
                    <text
                        x="{{ $x($index) }}"
                        y="94"
                        class="fill-slate-400"
                        style="font-size: 4.2px; font-family: inherit"
                        text-anchor="{{ $index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle') }}"
                    >{{ $label }}</text>
                @endif
            @endforeach
        </svg>
    </div>
@endif
