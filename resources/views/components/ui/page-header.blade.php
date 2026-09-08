@props([
    'title',
    'description' => null,
    'actions' => null,

    // The navigation trail, from App\Support\Breadcrumbs. Screens used to pass
    // a slot of hand-written anchors here instead, which is why the trails were
    // shallow and inconsistent; there is now one builder and one renderer, and
    // tests/Feature/UI/NavigationTest scans for anything going back.
    //
    // Do not write a component tag in this comment: Blade compiles component
    // tags out of the raw template text, PHP comments included, so one here
    // would be compiled into a component call inside this props array.
    'trail' => [],
])

<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div class="min-w-0">
        @if (! empty($trail))
            <x-ui.breadcrumbs :trail="$trail" />
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
