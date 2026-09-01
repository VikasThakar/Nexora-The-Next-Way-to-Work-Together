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
<body class="h-full bg-slate-950">
    <div class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <div class="mb-8 flex items-center justify-center gap-3">
                <x-app.logo class="size-9" />
                <span class="text-lg font-semibold text-white">{{ config('workspace.name') }}</span>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-white p-8 shadow-2xl">
                <x-ui.flash />

                {{ $slot }}
            </div>

            <p class="mt-6 text-center text-xs text-slate-500">
                Shared development workspace &middot; access is granted per board
            </p>
        </div>
    </div>

    {{-- The one modal in the document; see resources/js/dialog.js. --}}
    <x-ui.dialog />
</body>
</html>
