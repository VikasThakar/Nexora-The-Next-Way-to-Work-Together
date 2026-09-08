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

    /*
     * Deterministic hue per name so the same person keeps the same colour.
     *
     * Tokens rather than palette classes, and that is the one exception to the
     * remap in resources/css/app.css: the accent 600 shades are lifted for
     * dark mode, because that is what `text-brand-600` resolves to in link
     * text. A disc carrying white initials needs the opposite of a lift, so
     * these six are pinned and identical in both appearances.
     */
    $palette = [
        'bg-avatar-brand',
        'bg-avatar-emerald',
        'bg-avatar-violet',
        'bg-avatar-amber',
        'bg-avatar-rose',
        'bg-avatar-cyan',
    ];
    $colour = $palette[crc32($name) % count($palette)];
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none',
    $sizes[$size] ?? $sizes['md'],
    $colour,
]) }} aria-hidden="true">
    {{ $initials !== '' ? $initials : '?' }}
</span>
