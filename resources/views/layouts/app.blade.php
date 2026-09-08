<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ isset($title) ? $title.' · '.config('workspace.short_name') : config('workspace.name') }}</title>

    {{--
        The appearance, before anything paints. First in <head> on purpose:
        everything below it arrives over the network.
    --}}
    <x-app.theme-boot :preference="auth()->user()?->theme_preference" />

    <x-app.favicons />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <div x-data="{ mobileNav: false }" class="flex min-h-full">

        {{-- Off-canvas navigation for narrow viewports --}}
        <div
            x-show="mobileNav"
            x-cloak
            @keydown.escape.window="mobileNav = false"
            class="fixed inset-0 z-40 lg:hidden"
        >
            <div class="absolute inset-0 bg-scrim/50" @click="mobileNav = false"></div>
            <div class="relative flex h-full w-72 flex-col">
                <x-app.sidebar />
            </div>
        </div>

        {{--
            Persistent sidebar on desktop.

            Pinned to the viewport rather than sized by the page, so the user
            card at the foot of the sidebar stays in the bottom-left corner on a
            long board or ticket instead of being pushed down to the end of the
            document. `h-dvh` gives the column its own height, which lets the
            <nav> inside scroll on its own; `self-start` is what makes `sticky`
            work at all here, since a flex item otherwise stretches to the full
            height of the row and has nothing to stick within.
        --}}
        <div class="hidden w-64 shrink-0 lg:sticky lg:top-0 lg:flex lg:h-dvh lg:self-start">
            <x-app.sidebar />
        </div>

        {{--
            `app-content` is what the AI panel insets on desktop. The panel is
            fixed and never participates in layout, so one CSS rule on <body>
            shifts this column instead — see resources/js/ai-panel.js.
        --}}
        <div class="app-content flex min-w-0 flex-1 flex-col transition-[padding] duration-200">
            <x-app.topbar :title="$title ?? null" />

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto max-w-7xl">
                    <x-ui.flash />

                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-slate-200 bg-surface px-6 py-4 text-xs text-slate-500">
                {{ config('workspace.name') }} &middot; Signed in as {{ auth()->user()?->email }}
            </footer>
        </div>
    </div>

    {{--
        The global AI assistant.

        Mounted at the top level rather than inside the layout's flex tree:
        @persist reinserts the element at the same DOM position during a
        wire:navigate morph, and this is the position least likely to move. That
        is what keeps a conversation — and a streaming answer — alive while the
        person navigates.

        Not rendered at all for anybody who could not use it, so a customer's
        page contains no panel and no component id to address.
    --}}
    @if (\App\Livewire\Ai\Assistant::eligibleFor(auth()->user()))
        @persist('ai-panel')
            <livewire:ai.assistant />
        @endpersist
    @endif

    {{--
        The command palette.

        Alongside the assistant rather than in the layout's flex tree, for the
        same reason: it is an overlay and has no business participating in the
        page's layout. Persisted across wire:navigate so the component is not
        rebuilt on every page — the palette is the fastest way through the
        application and remounting it on arrival would put a Livewire boot in
        front of the first keystroke.

        Only for a signed-in viewer. Everything it can find is board-scoped, so
        for a guest there would be nothing to search and nothing to address.
    --}}
    @auth
        @persist('command-palette')
            <livewire:search.palette />
        @endpersist
    @endauth

    {{-- The one modal in the document; see resources/js/dialog.js. --}}
    <x-ui.dialog />
</body>
</html>
