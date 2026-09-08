@props(["title" => null])

@php
    $user = auth()->user();
@endphp

<header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-slate-200 bg-surface/90 px-4 backdrop-blur sm:px-6 lg:px-8">
    <button
        type="button"
        class="-ml-1 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 lg:hidden"
        @click="mobileNav = true"
        aria-label="Open navigation"
    >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </button>

    <div class="min-w-0 flex-1">
        <h1 class="truncate text-sm font-semibold text-slate-900">
            {{ $title ?? config('workspace.name') }}
        </h1>
    </div>

    {{--
        The palette's visible entry point.

        A button rather than a text input, even though it looks like one on a
        wide screen: the real input lives inside the palette, and having two
        would mean deciding which one owns the term. The shortcut hint is
        rendered by the store, which knows whether this is a Mac.

        On a phone it collapses to the magnifier — there is no keyboard to hint
        at, and the row has a role badge, an assistant, a bell and an avatar to
        fit beside it.
    --}}
    @if ($user)
        <button
            type="button"
            x-on:click="$store.palette.show()"
            class="flex items-center gap-2 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 sm:w-56 sm:justify-start sm:border sm:border-slate-200 sm:px-2.5 sm:py-1.5 sm:text-sm lg:w-64"
            aria-label="Search Nexora"
        >
            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>

            <span class="hidden sm:inline">Search</span>

            <span
                class="ms-auto hidden rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-[10px] text-slate-400 sm:inline"
                x-text="$store.palette.shortcut"
            ></span>
        </button>
    @endif

    <div class="flex items-center gap-3">
        <x-ui.badge :variant="$user?->isCustomer() ? 'amber' : ($user?->isAdmin() ? 'brand' : 'slate')">
            {{ $user?->role->label() }}
        </x-ui.badge>

        {{--
            Between the role badge and the bell, as specified.

            Shown only to somebody who could use the assistant on at least one
            board. That is a usability decision — App\Livewire\Ai\Assistant
            re-authorizes on every request and the layout does not mount it at
            all for anybody else, so there is no component to address either.
        --}}
        @if (\App\Livewire\Ai\Assistant::eligibleFor($user))
            <x-app.ai-trigger />
        @endif

        @if ($user)
            <livewire:notifications.bell />
        @endif

        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
            <button
                type="button"
                class="flex items-center gap-2 rounded-lg py-1.5 pl-1.5 pr-2 transition hover:bg-slate-100"
                @click="open = ! open"
                :aria-expanded="open"
                aria-haspopup="true"
            >
                <x-ui.avatar :name="$user?->name ?? '?'" />
                <svg class="size-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition.origin.top.right
                @click.outside="open = false"
                class="absolute right-0 z-40 mt-2 w-60 origin-top-right rounded-xl border border-slate-200 bg-surface-raised py-1 shadow-lg"
            >
                <div class="border-b border-slate-100 px-4 py-3">
                    <p class="truncate text-sm font-medium text-slate-900">{{ $user?->name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $user?->email }}</p>
                </div>

                <a href="{{ route('profile.edit') }}" wire:navigate
                   class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                    Profile &amp; password
                </a>

                @can('administer-workspace')
                    <a href="{{ route('users.index') }}" wire:navigate
                       class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                        Manage users
                    </a>
                @endcan

                <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                    @csrf
                    <button type="submit"
                            class="block w-full px-4 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
