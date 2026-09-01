@props([
    'title',
    'description' => null,
    'actions' => null,
    'breadcrumb' => null,
])

<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div class="min-w-0">
        @if ($breadcrumb)
            <div class="mb-1 text-xs text-slate-500">{{ $breadcrumb }}</div>
        @endif

        <h1 class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>

        @if ($description)
            <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>

    @if ($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endif
</div>
