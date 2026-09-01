@props([
    // [['label' => string, 'value' => int, 'variant' => ?string], ...]
    'series' => [],
    'empty' => 'Nothing to show yet.',
    'caption' => null,
])

@php
    /**
     * A proportion ring, drawn with one SVG circle per slice and a
     * stroke-dasharray offset. No library, and no path arithmetic either — a
     * ring is a stroked circle, and the only maths is "what fraction of the
     * circumference is this".
     */
    $rows = collect($series)->filter(fn ($row) => (int) ($row['value'] ?? 0) > 0)->values();
    $total = (int) $rows->sum('value');

    $stroke = [
        'slate' => 'stroke-slate-300',
        'brand' => 'stroke-brand-500',
        'emerald' => 'stroke-emerald-500',
        'amber' => 'stroke-amber-500',
        'rose' => 'stroke-rose-500',
    ];

    // r = 15.9155 makes the circumference exactly 100, so a dasharray is a
    // percentage and needs no conversion.
    $radius = 15.9155;
    $offset = 25.0;
@endphp

@if ($total === 0)
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div class="flex flex-wrap items-center justify-center gap-6">
        <svg viewBox="0 0 40 40" class="size-32 shrink-0 -rotate-90" role="img"
             aria-label="{{ $rows->map(fn ($r) => $r['label'].': '.$r['value'])->implode(', ') }}">
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
                />
                @php $cursor += $share; @endphp
            @endforeach
        </svg>

        <ul class="space-y-1.5 text-sm" role="list">
            @foreach ($rows as $row)
                <li class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full {{ str_replace('stroke-', 'bg-', $stroke[$row['variant'] ?? ''] ?? 'stroke-brand-500') }}"></span>
                    <span class="text-slate-600">{{ $row['label'] }}</span>
                    <span class="font-mono text-xs text-slate-500">
                        {{ number_format((int) $row['value']) }} · {{ round((int) $row['value'] / $total * 100) }}%
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($caption)
        <p class="mt-3 text-center text-xs text-slate-500">{{ $caption }}</p>
    @endif
@endif
