@props([
    'title' => null,
    'description' => null,
    'actions' => null,
    'padded' => true,
])

{{--
    rounded-lg rather than rounded-xl, and tighter inside.

    A card is the product's main container, so its radius sets the whole
    interface's softness and its padding sets the information density. Both
    came down one step: sharper corners read as more precise, and 16px of
    padding instead of 20 puts noticeably more on a screen without the
    content touching the edge.
--}}
<section {{ $attributes->class('overflow-hidden rounded-lg border border-slate-200 bg-surface shadow-xs') }}>
    @if ($title || $description || $actions)
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-xs text-slate-500">{{ $description }}</p>
                @endif
            </div>

            @if ($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endif
        </header>
    @endif

    <div @class(['px-4 py-4' => $padded])>
        {{ $slot }}
    </div>
</section>
