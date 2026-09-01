@props([
    // [['label' => string, 'value' => float, 'measured' => bool], ...]
    'series' => [],
    'height' => 120,
    'empty' => 'Not enough history to plot a trend.',
    'formatter' => null,
])

@php
    /**
     * A trend line, drawn as one inline SVG path.
     *
     * Weeks that measured nothing are gaps, not zeroes. A median cycle time of
     * "0" would render as a week where everything closed instantly, which is
     * the opposite of what an empty week means — so the path breaks and resumes.
     */
    $rows = collect($series)->values();
    $measured = $rows->filter(fn ($row) => ($row['measured'] ?? true) === true);
    $max = (float) max(0.001, $measured->max('value') ?? 0);

    $width = 100;
    $step = $rows->count() > 1 ? $width / ($rows->count() - 1) : 0;

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

        $x = round($index * $step, 2);
        $y = round(100 - ((float) $row['value'] / $max * 88) - 6, 2);
        $current[] = [$x, $y];
    }

    if (count($current) > 0) {
        $segments[] = $current;
    }

    $format = $formatter ?: fn ($value) => (string) round((float) $value, 1);
    $every = (int) max(1, ceil(max(1, $rows->count()) / 8));
@endphp

@if ($measured->isEmpty())
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div>
        <svg
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            class="w-full"
            style="height: {{ $height }}px"
            role="img"
            aria-label="Trend across {{ $rows->count() }} weeks, peak {{ $format($max) }}"
        >
            <line x1="0" y1="94" x2="100" y2="94" stroke="currentColor" class="text-slate-200" stroke-width="0.4" vector-effect="non-scaling-stroke" />

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
                        vector-effect="non-scaling-stroke"
                    />
                @endif

                @foreach ($points as $point)
                    <circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="2.5" class="fill-brand-500"
                            vector-effect="non-scaling-stroke" />
                @endforeach
            @endforeach
        </svg>

        <div class="mt-1 flex gap-1">
            @foreach ($rows as $index => $row)
                <div class="flex-1 truncate text-center text-[10px] text-slate-400">
                    {{ $index % $every === 0 ? $row['label'] : '' }}
                </div>
            @endforeach
        </div>

        <p class="mt-2 text-center text-[11px] text-slate-400">Peak {{ $format($max) }}</p>
    </div>
@endif
