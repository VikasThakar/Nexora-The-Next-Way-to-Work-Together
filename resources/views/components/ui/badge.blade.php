@props([
    'variant' => 'slate',
])

@php
    /*
     * `internal` is deliberately quiet. It marks content a customer cannot see,
     * which used to be amber — the same colour the product uses for "something
     * needs your attention". Two meanings on one hue made both weaker, and on a
     * ticket page the amber was most of the screen. Amber now means only
     * attention; internal content is slate plus a lock. See x-ui.internal-badge.
     */
    $variants = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'internal' => 'bg-slate-100 text-slate-600 ring-slate-300',
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ring-1 ring-inset',
    $variants[$variant] ?? $variants['slate'],
]) }}>
    {{ $slot }}
</span>
