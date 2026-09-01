@props([
    'variant' => 'slate',
])

@php
    $variants = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
    $variants[$variant] ?? $variants['slate'],
]) }}>
    {{ $slot }}
</span>
