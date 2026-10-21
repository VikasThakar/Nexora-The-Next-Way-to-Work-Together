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

    {{--
        And the panel widths, for the same reason and in the same breath. Only
        on this layout: the sign-in pages have neither panel to size.
    --}}
    <x-app.panel-boot />

    <x-app.favicons />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    {{--
        Inside the Gritch admin dashboard's iframe the page is the Gritch board
        and nothing around it: no sidebar, no workspace links, no profile or
        sign-out. Only cosmetic — App\Http\Middleware\EmbedScope is what keeps
        the embed on the board, and BoardPolicy what keeps it off the settings.
    --}}
    @php($embedded = \App\Services\Gritch\GritchEmbed::active())

    <div x-data="{ mobileNav: false }" class="flex min-h-full">

        @unless ($embedded)
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

            The width is a custom property rather than a utility so somebody can
            drag it — see resources/js/panel-resize.js. `--nav-width` has a real
            default in resources/css/app.css, so this column is 16rem with no
            JavaScript at all, exactly as it was before.

            The handle is a sibling here rather than part of the sidebar
            component, because that component is rendered twice: once in this
            column and once inside the off-canvas drawer above. A handle inside
            it would put two separators in the document, both claiming the same
            `aria-controls` target, one of them in a drawer that cannot be
            resized.
        --}}
        <div
            id="app-navigation"
            class="hidden shrink-0 lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-[var(--nav-width)] lg:self-start"
        >
            <x-app.sidebar />

            <x-app.resize-handle
                panel="nav"
                label="Resize navigation"
                controls="app-navigation"
                side="right"
            />
        </div>
        @endunless

        {{--
            `app-content` is what the AI panel insets on desktop. The panel is
            fixed and never participates in layout, so one CSS rule on <body>
            shifts this column instead — see resources/js/ai-panel.js.

            That class now carries the column's padding transition too, which
            used to be a `transition-[padding]` utility here. It had to move
            into the stylesheet so that a panel drag can suspend it: a cascade
            layer beats specificity, and a rule in `components` cannot out-rank
            a utility whatever its selector. The reasoning is written out beside
            the rule in resources/css/app.css.
        --}}
        <div class="app-content flex min-w-0 flex-1 flex-col">
            <x-app.topbar :title="$title ?? null" :embedded="$embedded" />

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto max-w-7xl">
                    <x-ui.flash />

                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-slate-200 bg-surface px-6 py-4 text-xs text-slate-500">
                @if ($embedded)
                    {{ config('workspace.name') }} &middot; Signed in through Gritch as {{ auth()->user()?->name }}
                @else
                    {{ config('workspace.name') }} &middot; Signed in as {{ auth()->user()?->email }}
                @endif
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
