@props([
    // [['label' => string, 'value' => int], ...] or, when $stacked, each row
    // may carry ['completed' => int, 'failed' => int].
    'series' => [],
    'height' => 132,
    'empty' => 'No activity in this period.',
    'stacked' => false,
    'unit' => '',
])

@php
    /**
     * A vertical bar chart for a weekly series.
     *
     * Same reasoning as the horizontal bars: a column chart is a row of divs
     * with heights, and the server already knows every height. No canvas, no
     * library, no client-side copy of the data — which also means a customer's
     * chart cannot accidentally ship a fuller dataset to the browser than the
     * one it draws, because there is no dataset in the browser at all.
     *
     * Labels are thinned rather than rotated when a long range would crowd
     * them: an unreadable axis is worse than a sparse one.
     */
    $rows = collect($series);

    $valueOf = fn ($row) => $stacked
        ? (int) ($row['completed'] ?? 0) + (int) ($row['failed'] ?? 0)
        : (int) ($row['value'] ?? 0);

    $max = max(1, (int) $rows->map($valueOf)->max());
    $count = max(1, $rows->count());
    $every = (int) max(1, ceil($count / 12));
@endphp

@if ($rows->isEmpty() || $rows->sum($valueOf) === 0)
    <p class="py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <div>
        <div class="flex items-end gap-1" style="height: {{ $height }}px" role="img"
             aria-label="{{ $rows->count() }} weekly buckets, peak {{ $max }}{{ $unit ? ' '.$unit : '' }}">
            @foreach ($rows as $row)
                @php
                    $total = $valueOf($row);
                    $pct = (int) round($total / $max * 100);
                @endphp

                <div
                    class="group relative flex h-full flex-1 flex-col justify-end"
                    title="{{ $row['label'] }}: {{ $stacked
                        ? ((int) ($row['completed'] ?? 0)).' completed, '.((int) ($row['failed'] ?? 0)).' failed'
                        : number_format($total).($unit ? ' '.$unit : '') }}"
                >
                    @if ($stacked)
                        @php
                            $failed = (int) ($row['failed'] ?? 0);
                            $completed = (int) ($row['completed'] ?? 0);
                            $failedPct = $total > 0 ? (int) round($failed / $max * 100) : 0;
                            $completedPct = max(0, $pct - $failedPct);
                        @endphp
                        <div class="w-full rounded-t-sm bg-rose-400" style="height: {{ $failedPct }}%"></div>
                        <div class="w-full bg-brand-500" style="height: {{ $completedPct }}%"></div>
                    @else
                        <div
                            @class(['w-full rounded-t-sm', $pct > 0 ? 'bg-brand-500' : 'bg-slate-200'])
                            style="height: {{ max($pct, $total > 0 ? 3 : 1) }}%"
                        ></div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-2 flex gap-1 border-t border-slate-100 pt-1.5">
            @foreach ($rows as $index => $row)
                <div class="flex-1 truncate text-center text-[10px] text-slate-400">
                    {{ $index % $every === 0 ? $row['label'] : '' }}
                </div>
            @endforeach
        </div>
    </div>
@endif
