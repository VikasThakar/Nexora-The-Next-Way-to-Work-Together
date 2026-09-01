@props([
    'href' => '#',
    'active' => false,
    'icon' => null,
    'trailing' => null,
])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
        'bg-brand-600 text-white shadow-sm' => $active,
        'text-slate-300 hover:bg-slate-800 hover:text-white' => ! $active,
    ]) }}
>
    @if ($icon)
        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
            {{ $icon }}
        </svg>
    @endif

    <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>

    @if ($trailing)
        <span class="shrink-0">{{ $trailing }}</span>
    @endif
</a>
