<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ config('workspace.name') }}</title>

    <x-app.favicons />

    @vite(['resources/css/app.css'])
</head>
{{--
    What the Gritch iframe shows when it cannot carry on: the session ended,
    the assertion was refused, or the account may not be used.

    No Livewire, no layout, no navigation — nothing on this page leads anywhere
    in Nexora. Its one job is the postMessage below, which tells the Gritch page
    to sign the admin in again. It is addressed to each allowed origin by name,
    so the browser delivers it only if the frame's parent really is one of
    them; a page that framed this from anywhere else hears nothing.
--}}
<body class="h-full bg-surface">
    <div class="flex min-h-full items-center justify-center px-6 py-12">
        <div class="max-w-sm text-center">
            <x-app.logo class="mx-auto mb-4 size-9" />

            @if ($reason === 'no_access')
                <h1 class="text-base font-semibold text-slate-900">Nexora can't be opened</h1>
                <p class="mt-2 text-sm text-slate-600">{{ $message }}</p>
            @elseif ($reason === 'refused')
                <h1 class="text-base font-semibold text-slate-900">Sign-in from Gritch was refused</h1>
                <p class="mt-2 text-sm text-slate-600">Reload the page in Gritch Admin to try again.</p>
            @else
                <h1 class="text-base font-semibold text-slate-900">Your Nexora session has ended</h1>
                <p class="mt-2 text-sm text-slate-600">Gritch Admin will sign you in again. If nothing happens, reload the page.</p>
            @endif
        </div>
    </div>

    <script>
        (function () {
            if (window.parent === window) {
                return;
            }

            var message = { type: 'nexora:session-ended', reason: @json($reason) };

            @json($origins).forEach(function (origin) {
                window.parent.postMessage(message, origin);
            });
        })();
    </script>
</body>
</html>
