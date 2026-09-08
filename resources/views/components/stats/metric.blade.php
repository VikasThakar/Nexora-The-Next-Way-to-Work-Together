@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'default',
])

@php
    $tones = [
        'default' => 'text-slate-900',
        'brand' => 'text-brand-700',
        'emerald' => 'text-emerald-700',
        'amber' => 'text-amber-700',
        'rose' => 'text-rose-700',
        'muted' => 'text-slate-400',
    ];
@endphp

<div {{ $attributes->class('rounded-xl border border-slate-200 bg-surface px-4 py-3.5 shadow-xs') }}>
    <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $tones[$tone] ?? $tones['default'] }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-0.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
