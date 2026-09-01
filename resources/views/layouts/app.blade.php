<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ isset($title) ? $title.' · '.config('workspace.short_name') : config('workspace.name') }}</title>

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
            <div class="absolute inset-0 bg-slate-900/50" @click="mobileNav = false"></div>
            <div class="relative flex h-full w-72 flex-col">
                <x-app.sidebar />
            </div>
        </div>

        {{-- Persistent sidebar on desktop --}}
        <div class="hidden w-64 shrink-0 lg:flex">
            <x-app.sidebar />
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <x-app.topbar :title="$title ?? null" />

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto max-w-7xl">
                    <x-ui.flash />

                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-slate-200 bg-white px-6 py-4 text-xs text-slate-500">
                {{ config('workspace.name') }} &middot; Signed in as {{ auth()->user()?->email }}
            </footer>
        </div>
    </div>

    {{-- The one modal in the document; see resources/js/dialog.js. --}}
    <x-ui.dialog />
</body>
</html>
