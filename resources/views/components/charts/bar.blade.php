@props([
    // [['label' => string, 'value' => int, 'variant' => ?string, 'color' => ?string], ...]
    'series' => [],
    'empty' => 'Nothing to show yet.',
    // Show a value that is zero, or hide the row entirely.
    'showZero' => true,

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
    use Illuminate\Support\Str;

    /**
     * A horizontal bar list, drawn as one self-contained inline SVG.
     *
     * It used to be a list of divs with widths, and the numbers were real HTML
     * text — selectable, searchable, readable by a screen reader. None of that
     * is given up here: SVG <text> is real text too, and it is inside the one
     * element the PNG and SVG downloads serialise
     * (resources/js/chart-export.js). Labels in sibling HTML would be on the
     * screen and absent from every exported file.
     *
     * The server still knows every width. The arithmetic below is the same
     * percentage the divs used, expressed in user units, so there is no scale
     * computed in the browser and no charting library to compute it with.
     *
     * The one thing SVG will not do for free is ellipsis: there is no
     * `overflow: hidden` for a <text>, so a long label is cut here instead and
     * the full one is kept in a <title>.
     */
    $rows = collect($series)
        ->filter(fn ($row) => $showZero || ($row['value'] ?? 0) > 0)
        ->values();

    $max = (int) $rows->max('value');
    $total = (int) $rows->sum('value');

    // Named tones rather than arbitrary values, so the palette stays the
    // product's. `fill-` here rather than `bg-`, because these are now shapes.
    $palette = [
        'slate' => 'fill-slate-400',
        'brand' => 'fill-brand-500',
        'emerald' => 'fill-emerald-500',
        'amber' => 'fill-amber-500',
        'rose' => 'fill-rose-500',
    ];

    /*
     * A nominal coordinate space, scaled uniformly to the card's width (w-full,
     * h-auto, default preserveAspectRatio). The three columns are the same
     * proportions the CSS grid had: a fixed label gutter, the track taking what
     * is left, and the figure right-aligned in its own column.
     */
    $width = (float) $width;
    $rowHeight = 26.0;

    $labelWidth = 144.0;
    $trackLeft = 156.0;
    $trackRight = 388.0;
    $trackWidth = $trackRight - $trackLeft;

    $barHeight = 7.0;

    // Roughly the number of characters that fits the gutter at this type size.
    $labelLimit = 24;

    $canvas = max($rowHeight, $rows->count() * $rowHeight);
@endphp

@if ($rows->isEmpty())
    <p class="py-6 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div data-chart>
        <svg
            viewBox="0 0 {{ $width }} {{ $canvas }}"
            class="mx-auto h-auto w-full"
            style="max-width: {{ $width }}px"
            role="img"
            aria-label="{{ $rows->map(fn ($r) => $r['label'].': '.$r['value'])->implode(', ') }}"
        >
            @foreach ($rows as $index => $row)
                @php
                    $value = (int) ($row['value'] ?? 0);

                    // Widths are relative to the largest bar, not to the total:
                    // a breakdown where every slice is under 10% would
                    // otherwise be five invisible slivers.
                    $filled = $max > 0
                        ? round(max($trackWidth * 0.02, $value / $max * $trackWidth), 2)
                        : 0.0;

                    $share = $total > 0 ? round($value / $total * 100) : 0;
                    $fill = $palette[$row['variant'] ?? ''] ?? 'fill-brand-500';

                    $top = $index * $rowHeight;
                    $middle = round($top + $rowHeight / 2, 2);

                    // A per-row swatch, for series whose colours are data
                    // rather than a tone — board labels carry their own hex.
                    $swatch = ! empty($row['color']);
                    $labelX = $swatch ? 12.0 : 0.0;
                @endphp

                <g>
                    <title>{{ $row['label'] }}: {{ number_format($value) }}{{ $total > 0 ? ' ('.$share.'%)' : '' }}</title>

                    @if ($swatch)
                        <circle cx="4" cy="{{ $middle }}" r="3.5" fill="{{ $row['color'] }}" />
                    @endif

                    <text
                        x="{{ $labelX }}" y="{{ $middle }}"
                        dominant-baseline="middle"
                        class="fill-slate-600"
                        style="font-size: 11.5px; font-family: inherit"
                    >{{ Str::limit($row['label'], $labelLimit) }}</text>

                    {{-- The track, then the fill. Both fully rounded, as the
                         rounded-full pair they replace were. --}}
                    <rect
                        x="{{ $trackLeft }}" y="{{ round($middle - $barHeight / 2, 2) }}"
                        width="{{ $trackWidth }}" height="{{ $barHeight }}"
                        rx="{{ $barHeight / 2 }}"
                        class="fill-slate-100"
                    />

                    @if ($filled > 0)
                        <rect
                            x="{{ $trackLeft }}" y="{{ round($middle - $barHeight / 2, 2) }}"
                            width="{{ $filled }}" height="{{ $barHeight }}"
                            rx="{{ $barHeight / 2 }}"
                            class="{{ $fill }}"
                        />
                    @endif

                    <text
                        x="{{ $width }}" y="{{ $middle }}"
                        text-anchor="end"
                        dominant-baseline="middle"
                        style="font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace"
                    >
                        <tspan class="fill-slate-500">{{ number_format($value) }}</tspan>
                        @if ($total > 0)
                            <tspan class="fill-slate-400" dx="4">{{ $share }}%</tspan>
                        @endif
                    </text>
                </g>
            @endforeach
        </svg>
    </div>
@endif
