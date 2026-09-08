@php
    /**
     * Navigation is rendered from server-side ability checks.
     *
     * Hiding a link is a usability decision, never a security one: every
     * destination below re-authorizes on the server.
     */
    $user = auth()->user();
    $canAdminister = $user?->can('administer-workspace') ?? false;
    $canSeeInternal = $user?->can('view-internal-content') ?? false;
    $boards = app(\App\Services\BoardAccess::class)
        ->query($user)
        ->notArchived()
        ->orderBy('name')
        ->limit(8)
        ->get();

    $routeBoard = request()->route('board');
    $currentBoardSlug = $routeBoard instanceof \App\Models\Board ? $routeBoard->slug : $routeBoard;

    /*
     * Documentation is a permanent entry, but the pages themselves live under a
     * board — there is no workspace-wide docs route. So the link follows the
     * board in scope, falls back to the first board the viewer has, and is
     * omitted entirely when they have none. Every board below already came out
     * of BoardAccess, and DocPageFinder applies the visibility rules on arrival.
     */
    $docsBoard = $routeBoard instanceof \App\Models\Board
        ? $routeBoard
        : ($currentBoardSlug !== null ? $boards->firstWhere('slug', $currentBoardSlug) : null);

    $docsBoard ??= $boards->first();
@endphp

<aside class="flex h-full w-full flex-col border-r border-sidebar-border bg-sidebar">
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-sidebar-border px-5">
        <x-app.logo class="size-8" />
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-sidebar-ink">{{ config('workspace.short_name') }}</p>
            <p class="truncate text-xs text-sidebar-ink-dim">Workspace</p>
        </div>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
        <div class="space-y-1">
            <x-app.nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                <x-slot:icon>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                </x-slot:icon>
                Dashboard
            </x-app.nav-link>

            <x-app.nav-link :href="route('boards.index')" :active="request()->routeIs('boards.*')">
                <x-slot:icon>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v12a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18V6zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v6A2.25 2.25 0 0118 14.25h-2.25A2.25 2.25 0 0113.5 12V6z" />
                </x-slot:icon>
                Boards
            </x-app.nav-link>

            {{--
                Staff go to the delivery metrics; customers go to their own
                summary. Hiding the other link is a usability decision only —
                /stats is gated by role middleware and re-authorized inside the
                component, so typing the URL achieves nothing.
            --}}
            <x-app.nav-link
                :href="$canSeeInternal ? route('stats') : route('stats.customer')"
                :active="request()->routeIs('stats') || request()->routeIs('stats.customer')"
            >
                <x-slot:icon>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </x-slot:icon>
                Statistics
            </x-app.nav-link>

            @if ($docsBoard)
                <x-app.nav-link
                    :href="route('docs.index', $docsBoard)"
                    :active="request()->routeIs('docs.*')"
                >
                    <x-slot:icon>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </x-slot:icon>
                    Documentation
                </x-app.nav-link>
            @endif

            {{--
                The workspace activity feed. Staff only, so the link is absent
                for a customer — and as everywhere else in this file that is a
                usability decision, not the security one: /activity is behind
                `role:admin,team`, re-checks on every render, and its query
                refuses a non-staff viewer outright.
            --}}
            @if ($canSeeInternal)
                <x-app.nav-link :href="route('activity')" :active="request()->routeIs('activity')">
                    <x-slot:icon>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </x-slot:icon>
                    Activity
                </x-app.nav-link>
            @endif

            {{--
                Settings is a directory of screens that already exist, so it is
                shown to everyone: a customer finds their profile and password
                there, and the internal sections are omitted by the component
                itself rather than by hiding this link.
            --}}
            <x-app.nav-link :href="route('settings')" :active="request()->routeIs('settings')">
                <x-slot:icon>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.077-.124.072-.044.146-.087.22-.128.331-.183.581-.495.644-.869l.213-1.281z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </x-slot:icon>
                Settings
            </x-app.nav-link>
        </div>

        @if ($boards->isNotEmpty())
            <div>
                <p class="px-3 pb-2 text-xs font-semibold tracking-wide text-sidebar-ink-faint uppercase">Your boards</p>
                <div class="space-y-1">
                    @foreach ($boards as $board)
                        <x-app.nav-link
                            :href="route('boards.show', $board)"
                            :active="request()->routeIs('boards.show') && $currentBoardSlug === $board->slug"
                        >
                            <x-slot:icon>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                            </x-slot:icon>
                            <span class="truncate">{{ $board->name }}</span>
                            <x-slot:trailing>
                                <span class="font-mono text-[10px] text-sidebar-ink-faint">{{ $board->ticket_prefix }}</span>
                            </x-slot:trailing>
                        </x-app.nav-link>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($canAdminister)
            <div>
                <p class="px-3 pb-2 text-xs font-semibold tracking-wide text-sidebar-ink-faint uppercase">Administration</p>
                <div class="space-y-1">
                    <x-app.nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                        <x-slot:icon>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </x-slot:icon>
                        Users
                    </x-app.nav-link>

                    <x-app.nav-link :href="route('boards.create')" :active="request()->routeIs('boards.create')">
                        <x-slot:icon>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </x-slot:icon>
                        New board
                    </x-app.nav-link>
                </div>
            </div>
        @endif
    </nav>

    {{--
        Appearance, in the gap between the navigation and the user card.

        Below the <nav> rather than inside it, so it does not scroll away with
        a long board list, and above the user card because it belongs with the
        other things that are about the person rather than about the workspace.
    --}}
    <x-app.theme-switcher />

    <div class="border-t border-sidebar-border p-3">
        <a
            href="{{ route('profile.edit') }}"
            wire:navigate
            class="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-sidebar-hover"
        >
            <x-ui.avatar :name="$user?->name ?? '?'" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-sidebar-ink">{{ $user?->name }}</p>
                <p class="truncate text-xs text-sidebar-ink-dim">{{ $user?->role->label() }}</p>
            </div>
        </a>
    </div>
</aside>
