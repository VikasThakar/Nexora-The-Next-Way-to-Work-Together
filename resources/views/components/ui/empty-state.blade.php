@props([
    'title' => 'Nothing here yet',
    'description' => null,
    'actions' => null,
])

<div {{ $attributes->class('rounded-xl border border-dashed border-slate-300 bg-surface px-6 py-14 text-center') }}>
    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
        </svg>
    </div>

    <h3 class="mt-4 text-sm font-semibold text-slate-900">{{ $title }}</h3>

    @if ($description)
        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500">{{ $description }}</p>
    @endif

    @if ($actions)
        <div class="mt-6 flex items-center justify-center gap-3">{{ $actions }}</div>
    @endif
</div>
