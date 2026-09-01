@props([
    'name' => '?',
    'size' => 'md',
])

@php
    $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    $sizes = [
        'sm' => 'size-7 text-[11px]',
        'md' => 'size-9 text-xs',
        'lg' => 'size-12 text-sm',
    ];

    // Deterministic hue per name so the same person keeps the same colour.
    $palette = ['bg-brand-600', 'bg-emerald-600', 'bg-violet-600', 'bg-amber-600', 'bg-rose-600', 'bg-cyan-600'];
    $colour = $palette[crc32($name) % count($palette)];
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none',
    $sizes[$size] ?? $sizes['md'],
    $colour,
]) }} aria-hidden="true">
    {{ $initials !== '' ? $initials : '?' }}
</span>
