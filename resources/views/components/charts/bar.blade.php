@props([
    // [['label' => string, 'value' => int, 'variant' => ?string, 'color' => ?string], ...]
    'series' => [],
    'empty' => 'Nothing to show yet.',
    // Show a value that is zero, or hide the row entirely.
    'showZero' => true,
])

@php
    /**
     * A horizontal bar list.
     *
     * Rendered as plain elements rather than a canvas: the numbers are already
     * on the server, the widths are one percentage each, and shipping a
     * charting library to draw a div of a given width would be a dependency
     * bought for nothing. It also means the figures are real text — selectable,
     * searchable, and readable by a screen reader, which a canvas is not.
     */
    $rows = collect($series)->filter(fn ($row) => $showZero || ($row['value'] ?? 0) > 0)->values();
    $max = (int) $rows->max('value');
    $total = (int) $rows->sum('value');

    $palette = [
        'slate' => 'bg-slate-400',
        'brand' => 'bg-brand-500',
        'emerald' => 'bg-emerald-500',
        'amber' => 'bg-amber-500',
        'rose' => 'bg-rose-500',
    ];
@endphp

@if ($rows->isEmpty())
    <p class="py-6 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <ul class="space-y-2.5" role="list">
        @foreach ($rows as $row)
            @php
                $value = (int) ($row['value'] ?? 0);
                // Widths are relative to the largest bar, not to the total: a
                // breakdown where every slice is under 10% would otherwise be
                // five invisible slivers.
                $width = $max > 0 ? max(2, (int) round($value / $max * 100)) : 0;
                $share = $total > 0 ? round($value / $total * 100) : 0;
                $bar = $palette[$row['variant'] ?? ''] ?? null;
            @endphp

            <li class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3">
                <span class="truncate text-xs text-slate-600" title="{{ $row['label'] }}">
                    @if (! empty($row['color']))
                        <span class="mr-1 inline-block size-2 rounded-full align-middle" style="background-color: {{ $row['color'] }}"></span>
                    @endif
                    {{ $row['label'] }}
                </span>

                <span class="h-2 overflow-hidden rounded-full bg-slate-100">
                    <span
                        @class(['block h-full rounded-full', $bar ?? 'bg-brand-500'])
                        style="width: {{ $width }}%"
                    ></span>
                </span>

                <span class="w-20 text-right font-mono text-xs text-slate-500">
                    {{ number_format($value) }}<span class="ml-1 text-slate-400">{{ $total > 0 ? $share.'%' : '' }}</span>
                </span>
            </li>
        @endforeach
    </ul>
@endif
