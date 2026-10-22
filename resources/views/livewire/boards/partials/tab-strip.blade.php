{{--
    The tab strip: one board, several boards' worth of work.

    Like browser tabs — click one and the columns below become that tab's
    columns and tickets, without leaving the board. Folder-tab styling, the
    same as the ticket conversation's Customer / Internal tabs.

    Accessibility. A real tablist: role="tab" buttons with aria-selected, one
    in the tab order at a time, arrow keys / Home / End to move between them
    (resources/js/board-tabs.js). Activation is manual — Enter or Space opens
    the focused tab — because every switch is a round trip to the server, and
    arrowing along the strip should not fetch each tab on the way. The tab
    menu sits after the list, not inside it: a tablist may only contain tabs.

    Each count is the tickets in that tab this person can see under the
    current filters, so a customer's counts never include internal work, and a
    search shows at a glance which tabs hold matches.

    Dragging:
      - managers drag tabs to reorder them (x-sort on the list, in no group, so
        a card can never be dropped among the tabs);
      - staff drop a ticket card onto another tab to move it there. Each
        non-active tab carries an overlay that takes only cards (put:
        ['board-{id}'], never pull), and only while a drag is in progress
        (pointer-events in app.css). The card SortableJS leaves inside the
        overlay is hidden by CSS and gone at the next render; where it lands is
        App\Support\TabColumnMapping's decision. A keyboard or screen-reader
        user moves a ticket with the Tab select on its page instead.
--}}
@php
    $reorderTabs = $canConfigureBoard && $tabs->count() > 1;
    $activeIndex = $activeTab ? $tabs->search(fn ($candidate) => $candidate->is($activeTab)) : false;
@endphp

<div class="mb-3 flex items-end gap-2 border-b border-slate-200">
    <div
        x-data="boardTabs"
        x-on:board-tab-changed.window="reveal()"
        role="tablist"
        aria-label="{{ $board->name }} tabs"
        @if ($reorderTabs)
            x-sort="$wire.reorderTab($item, $position)"
            x-sort:config="{
                forceFallback: true,
                fallbackOnBody: true,
                fallbackTolerance: 6,
                supportPointer: false,
                delay: 180,
                delayOnTouchOnly: true,
                touchStartThreshold: 6,
                direction: 'horizontal',
            }"
        @endif
        class="scrollbar-none -mb-px flex min-w-0 flex-1 items-end gap-1 overflow-x-auto"
    >
        @foreach ($tabs as $stripTab)
            @php($isActive = $activeTab !== null && $stripTab->is($activeTab))

            <div
                wire:key="board-tab-{{ $stripTab->id }}"
                role="presentation"
                class="relative shrink-0"
                @if ($reorderTabs) x-sort:item="{{ $stripTab->id }}" @endif
            >
                <button
                    type="button"
                    role="tab"
                    id="board-tab-{{ $stripTab->id }}"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    aria-controls="board-tabpanel"
                    tabindex="{{ $isActive ? '0' : '-1' }}"
                    title="{{ $stripTab->name }}"
                    @unless ($isActive) wire:click="selectTab({{ $stripTab->id }})" @endunless
                    x-on:keydown.right.prevent="step(1)"
                    x-on:keydown.left.prevent="step(-1)"
                    x-on:keydown.home.prevent="edge(0)"
                    x-on:keydown.end.prevent="edge(-1)"
                    @class([
                        '-mb-px flex max-w-56 items-center gap-2 rounded-t-lg border border-b-0 px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500',
                        'border-slate-200 bg-surface text-slate-900' => $isActive,
                        'border-transparent text-slate-500 hover:text-slate-800' => ! $isActive,
                    ])
                >
                    <span class="truncate">{{ $stripTab->name }}</span>
                    <span class="shrink-0 rounded-full bg-slate-100 px-1.5 text-[10px] text-slate-600">
                        {{ $tabCounts[$stripTab->id] ?? 0 }}<span class="sr-only"> {{ $filters->isEmpty() ? 'tickets' : 'matching tickets' }}</span>
                    </span>
                </button>

                @if ($canReorder && ! $isActive)
                    <div
                        data-tab-drop
                        aria-hidden="true"
                        class="absolute inset-0 rounded-t-lg"
                        x-sort="$wire.moveTicketToTab($item, {{ $stripTab->id }})"
                        x-sort:config="{
                            group: { name: 'tab-drop', pull: false, put: ['{{ $sortGroup }}'] },
                            sort: false,
                            emptyInsertThreshold: 0,
                        }"
                    ></div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($canConfigureBoard)
        <div class="flex shrink-0 items-center gap-1 pb-1">
            {{-- The menu for the tab on screen. --}}
            @if ($activeTab)
            <div
                x-data="{ open: false }"
                x-on:click.outside="open = false"
                x-on:keydown.escape.stop="open = false"
                class="relative"
            >
                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-haspopup="menu"
                    aria-controls="board-tab-actions"
                    aria-label="Manage the {{ $activeTab->name }} tab"
                    title="Manage the {{ $activeTab->name }} tab"
                    class="rounded-md p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>
                </button>

                <div
                    id="board-tab-actions"
                    role="menu"
                    x-show="open"
                    x-cloak
                    x-transition.origin.top.right
                    class="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-lg border border-slate-200 bg-surface-raised py-1 text-[13px] shadow-lg"
                >
                    <button
                        type="button"
                        role="menuitem"
                        class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                        x-on:click="open = false; $dialog.ask({ title: 'Rename tab', inputLabel: 'Tab name', value: @js($activeTab->name), confirmText: 'Rename' }).then((name) => { if (name) $wire.renameTab({{ $activeTab->id }}, name) })"
                    >Rename…</button>

                    @if ($activeIndex !== false && $activeIndex > 0)
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                            x-on:click="open = false"
                            wire:click="moveTab({{ $activeTab->id }}, -1)"
                        >Move left</button>
                    @endif

                    @if ($activeIndex !== false && $activeIndex < $tabs->count() - 1)
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                            x-on:click="open = false"
                            wire:click="moveTab({{ $activeTab->id }}, 1)"
                        >Move right</button>
                    @endif

                    <a
                        role="menuitem"
                        href="{{ route('boards.settings', array_filter(['board' => $board, 'tab' => $tabParam])) }}"
                        wire:navigate
                        class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                    >Edit columns</a>

                    @if ($tabs->count() > 1)
                        <div class="my-1 border-t border-slate-100" role="separator"></div>

                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-1.5 text-left text-rose-600 hover:bg-rose-50"
                            x-on:click="open = false"
                            wire:click="startDeletingTab({{ $activeTab->id }})"
                        >Delete tab…</button>
                    @endif
                </div>
            </div>
            @endif

            <button
                type="button"
                aria-label="Add a tab"
                title="Add a tab"
                x-on:click="$dialog.ask({ title: 'New tab', body: @js($activeTab ? 'It starts with the same columns as '.$activeTab->name.'.' : 'It starts with the standard columns.'), inputLabel: 'Tab name', placeholder: 'e.g. Backend API', confirmText: 'Add tab' }).then((name) => { if (name) $wire.createTab(name) })"
                class="rounded-md p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </button>
        </div>
    @endif
</div>
