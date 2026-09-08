@props([
    // [['label' => string, 'value' => int, 'variant' => ?string], ...]
    'series' => [],
    'empty' => 'Nothing to show yet.',
    'caption' => null,
])

@php
    use Illuminate\Support\Str;

    /**
     * A proportion ring, drawn with one SVG circle per slice and a
     * stroke-dasharray offset. No library, and no path arithmetic either — a
     * ring is a stroked circle, and the only maths is "what fraction of the
     * circumference is this".
     *
     * The legend is <text> inside the same SVG rather than an HTML list beside
     * it, because this element is what the PNG and SVG downloads serialise
     * (resources/js/chart-export.js) and a ring exported without its labels is
     * a picture of some coloured arcs.
     *
     * For the same reason the ring is turned by an SVG transform rather than by
     * Tailwind's `-rotate-90`: a CSS class is resolved by the page's stylesheet,
     * which is not coming with the file, so a class-rotated ring would export
     * with its first slice a quarter-turn out of place.
     */
    $rows = collect($series)->filter(fn ($row) => (int) ($row['value'] ?? 0) > 0)->values();
    $total = (int) $rows->sum('value');

    /*
     * Whole class names, not `stroke-{$tone}-500` assembled from a fragment.
     * Tailwind generates a utility only when it can see the literal string in
     * a source file, so an interpolated class name compiles to nothing at all
     * and the slice renders black.
     */
    $stroke = [
        'slate' => 'stroke-slate-300',
        'brand' => 'stroke-brand-500',
        'emerald' => 'stroke-emerald-500',
        'amber' => 'stroke-amber-500',
        'rose' => 'stroke-rose-500',
    ];

    $swatch = [
        'slate' => 'fill-slate-300',
        'brand' => 'fill-brand-500',
        'emerald' => 'fill-emerald-500',
        'amber' => 'fill-amber-500',
        'rose' => 'fill-rose-500',
    ];

    // r = 15.9155 makes the circumference exactly 100, so a dasharray is a
    // percentage and needs no conversion. The ring is drawn in its own 40x40
    // space and then placed, so that stays true whatever size it renders at.
    $radius = 15.9155;
    $offset = 25.0;

    /*
     * A nominal coordinate space, scaled uniformly to the card's width: the
     * ring on the left at 2.5x its own box, the legend in the space to its
     * right. Uniform because `preserveAspectRatio="none"` would stretch the
     * legend type along with the ring.
     *
     * Wide enough that a label of the length these actually reach — "Shared
     * with customer" — clears the figure right-aligned beside it. At 300 the
     * two ran into each other.
     */
    $width = 380.0;
    $ringScale = 2.5;
    $ringInset = 8.0;
    $ringCentre = $ringInset + 20.0 * $ringScale;

    $legendX = 126.0;
    $rowHeight = 20.0;

    $legendHeight = $rows->count() * $rowHeight;
    $bodyHeight = max($ringInset * 2 + 40.0 * $ringScale, $legendHeight + 16.0);
    $canvas = $bodyHeight + ($caption ? 20.0 : 0.0);

    // Centred against the ring, so two slices do not sit at the top of a tall
    // box and five do not overflow it.
    $legendTop = round($bodyHeight / 2 - $legendHeight / 2, 2);
@endphp

@if ($total === 0)
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div data-chart>
        <svg
            viewBox="0 0 {{ $width }} {{ $canvas }}"
            class="mx-auto h-auto w-full"
            style="max-width: {{ $width }}px"
            role="img"
            aria-label="{{ $rows->map(fn ($r) => $r['label'].': '.$r['value'])->implode(', ') }}"
        >
            <g transform="translate({{ $ringInset }} {{ $ringInset }}) scale({{ $ringScale }})">
                <g transform="rotate(-90 20 20)">
                    <circle cx="20" cy="20" r="{{ $radius }}" fill="none" class="stroke-slate-100" stroke-width="5" />

                    @php $cursor = 0.0; @endphp
                    @foreach ($rows as $row)
                        @php
                            $share = round((int) $row['value'] / $total * 100, 3);
                            $dash = $share.' '.round(100 - $share, 3);
                        @endphp
                        <circle
                            cx="20" cy="20" r="{{ $radius }}" fill="none" stroke-width="5"
                            class="{{ $stroke[$row['variant'] ?? ''] ?? 'stroke-brand-500' }}"
                            stroke-dasharray="{{ $dash }}"
                            stroke-dashoffset="{{ round($offset - $cursor, 3) }}"
                        >
                            <title>{{ $row['label'] }}: {{ number_format((int) $row['value']) }} · {{ round($share) }}%</title>
                        </circle>
                        @php $cursor += $share; @endphp
                    @endforeach
                </g>
            </g>

            {{-- The total in the middle of the ring, which is the figure people
                 look for and the one a legend cannot give them. --}}
            <text
                x="{{ $ringCentre }}" y="{{ $ringCentre }}"
                text-anchor="middle" dominant-baseline="middle"
                class="fill-slate-900"
                style="font-size: 20px; font-weight: 600; font-family: inherit"
            >{{ number_format($total) }}</text>

            {{-- The legend. --}}
            @foreach ($rows as $index => $row)
                @php
                    $middle = round($legendTop + $index * $rowHeight + $rowHeight / 2, 2);
                @endphp

                <circle
                    cx="{{ $legendX }}" cy="{{ $middle }}" r="4"
                    class="{{ $swatch[$row['variant'] ?? ''] ?? 'fill-brand-500' }}"
                />

                <text
                    x="{{ $legendX + 11 }}" y="{{ $middle }}"
                    dominant-baseline="middle"
                    class="fill-slate-600"
                    style="font-size: 12px; font-family: inherit"
                >{{ Str::limit($row['label'], 22) }}</text>

                <text
                    x="{{ $width }}" y="{{ $middle }}"
                    text-anchor="end" dominant-baseline="middle"
                    class="fill-slate-500"
                    style="font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace"
                >{{ number_format((int) $row['value']) }} · {{ round((int) $row['value'] / $total * 100) }}%</text>
            @endforeach

            @if ($caption)
                <text
                    x="{{ $width / 2 }}" y="{{ $canvas - 6 }}"
                    text-anchor="middle"
                    class="fill-slate-500"
                    style="font-size: 11px; font-family: inherit"
                >{{ $caption }}</text>
            @endif
        </svg>
    </div>
@endif
