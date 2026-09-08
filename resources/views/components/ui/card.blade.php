@props([
    'title' => null,
    'description' => null,
    'actions' => null,
    'padded' => true,
])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-slate-200 bg-surface shadow-xs') }}>
    @if ($title || $description || $actions)
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
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

    <div @class(['px-5 py-5' => $padded])>
        {{ $slot }}
    </div>
</section>
