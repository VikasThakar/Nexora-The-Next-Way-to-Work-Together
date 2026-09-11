@props([
    'nodes',
    'board',
    'current' => null,
    'canManage' => false,
    'canCreate' => false,
    'sortable' => false,
    'sortGroup' => 'docs',
    'parentId' => null,

    /*
     * Page ids whose children start expanded: the ancestor chain of the page
     * being read, plus that page itself. Built in App\Livewire\Docs\Show,
     * because only the server knows the chain — and an ancestor a viewer may
     * not see is not in it.
     */
    'openIds' => [],
])

{{--
    One level of the documentation tree.

    Recursive: each node renders this component again for its children, so the
    nesting in the markup matches the nesting in the data.

    Drag and drop uses x-sort, the same Alpine Sort plugin the Kanban board
    uses. Every level shares one group name, so a page can be dragged between
    levels as well as within one, and each list bakes its own parent id into the
    handler — so the server is always told where the page landed rather than
    having to guess.

    x-sort and not Livewire's wire:sort wrapper: wire:sort runs the expression
    through Livewire's evaluator, which rewrites the plugin's own $item and
    $position into $wire.$item and $wire.$position and so delivers a move as
    movePage(null, null, 3). See resources/views/livewire/boards/show.blade.php
    for the long version.

    Only <li> page elements may live directly inside the list: the index the
    browser reports is the child index, so any other node in here would shift
    every position by one.

    Expand and collapse is entirely in the browser — one Alpine store, backed by
    localStorage, in resources/js/doc-tree.js. It is deliberately not Livewire
    state: opening a branch is not worth a round trip, and it is not worth a
    column either. What the server contributes is openIds, above.

    Nothing here carries a `dark:` variant, and nothing should. The palette in
    resources/css/app.css is remapped wholesale for the dark appearance, so
    `text-slate-700` is the right foreground in both.
--}}
<ul
    @if ($sortable)
        x-sort="$wire.movePage($item, $position, {{ $parentId === null ? 'null' : $parentId }})"
        x-sort:group="{{ $sortGroup }}"
    @endif
    @class([
        'space-y-px',
        // Indent is deliberately small: four levels of it still has to leave
        // room for a title in a 15rem column.
        'ml-3 pl-1.5' => $parentId !== null,
        /*
         * The guide line down a branch, and only down a branch that has
         * something in it. A childless page still renders an empty list as a
         * drop target, so without this condition every leaf trailed a two-pixel
         * stub of border below it — which read as a rendering fault rather than
         * as somewhere to drop a page.
         */
        'border-l border-slate-200' => $parentId !== null && $nodes->isNotEmpty(),
        'min-h-2' => $sortable,
    ])
>
    @foreach ($nodes as $node)
        @php
            $page = $node->page;
            $isCurrent = $current !== null && $current->id === $page->id;
            $hasChildren = $node->children->isNotEmpty();

            // Whether this branch starts open. Alpine takes over from here, and
            // may disagree if this person has collapsed the branch before.
            $open = in_array((int) $page->id, array_map('intval', $openIds), true);
        @endphp

        <li wire:key="doc-page-{{ $page->id }}" @if ($sortable) x-sort:item="{{ $page->id }}" @endif>
            {{--
                The row: disclosure, then the link, then the actions. Siblings
                rather than nested, because a button inside an anchor is not
                valid HTML and behaves differently in every browser.

                `x-data` is on the row so the context menu below has somewhere
                local to keep its open state — one small component per row
                rather than one store for the whole tree, because two menus are
                never open at once and nothing outside the row needs to know.
            --}}
            <div
                x-data="{ menu: false }"
                x-on:keydown.escape.stop="menu = false"
                @class([
                    'group/row relative flex items-center gap-0.5 rounded-md pr-1 transition-colors',
                    'bg-brand-50 text-brand-900' => $isCurrent,
                    'hover:bg-slate-100' => ! $isCurrent,
                ])
            >
                @if ($sortable)
                    {{--
                        The grab area. Naming it as a sort handle restricts
                        dragging to this glyph, which is what keeps a click on
                        the chevron or the title from being read as the start
                        of a drag.

                        Hidden until the row is hovered: a column of grip dots
                        beside every title is visual noise on a list that is
                        read far more often than it is rearranged.

                        Not a security control — DocPagePolicy::move is — but
                        it is only offered to people who may reorganise.
                    --}}
                    <span
                        x-sort:handle
                        class="-ml-1 shrink-0 cursor-grab px-0.5 py-1 text-slate-300 opacity-0 transition-opacity group-hover/row:opacity-100"
                        aria-hidden="true"
                    >
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                        </svg>
                    </span>
                @endif

                @if ($hasChildren)
                    <button
                        type="button"
                        x-on:click="$store.docTree.toggle({{ $page->id }}, {{ $open ? 'true' : 'false' }})"
                        x-bind:aria-expanded="$store.docTree.isOpen({{ $page->id }}, {{ $open ? 'true' : 'false' }}) ? 'true' : 'false'"
                        aria-controls="doc-children-{{ $page->id }}"
                        aria-label="Pages under {{ $page->title }}"
                        class="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-500"
                    >
                        <svg
                            class="size-3 transition-transform duration-150"
                            x-bind:class="$store.docTree.isOpen({{ $page->id }}, {{ $open ? 'true' : 'false' }}) ? 'rotate-90' : 'rotate-0'"
                            fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                @else
                    {{-- Keeps every title on the same left edge whether or not
                         the page has children below it. --}}
                    <span class="size-4 shrink-0" aria-hidden="true"></span>
                @endif

                <a
                    href="{{ route('docs.show', ['board' => $board, 'slug' => $page->slug]) }}"
                    wire:navigate
                    @class([
                        'flex min-w-0 flex-1 items-center gap-1.5 py-1 text-[13px] leading-5',
                        'font-medium text-brand-900' => $isCurrent,
                        'text-slate-700' => ! $isCurrent,
                    ])
                    @if ($isCurrent) aria-current="page" @endif
                >
                    {{--
                        The icon. An emoji when the page has one, and a plain
                        document glyph when it does not — never an empty gap,
                        or titles with and without icons would not line up.

                        aria-hidden on both: the emoji is decoration beside a
                        title that already says what the page is, and a screen
                        reader announcing "rocket" before every heading is
                        noise rather than information.
                    --}}
                    <span class="w-4 shrink-0 text-center text-[13px] leading-5" aria-hidden="true">
                        @if (filled($page->icon))
                            {{ $page->icon }}
                        @else
                            <svg class="mx-auto size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                        @endif
                    </span>

                    <span class="min-w-0 flex-1 truncate">{{ $page->title }}</span>
                </a>

                {{--
                    Audience, and then the actions.

                    The visibility glyph fades out while the row is hovered and
                    the actions take its place: they occupy the same corner, and
                    showing both would either shuffle the title or need a wider
                    column than the tree has.
                --}}
                @if ($page->customer_visible)
                    <span
                        class="pointer-events-none shrink-0 text-emerald-600 transition-opacity @if ($canCreate || $canManage) group-hover/row:opacity-0 @endif"
                        title="Visible to customers"
                    >
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </span>
                @elseif ($canManage)
                    <span
                        class="pointer-events-none shrink-0 text-slate-300 transition-opacity @if ($canCreate || $canManage) group-hover/row:opacity-0 @endif"
                        title="Internal only"
                    >
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </span>
                @endif

                @if ($canCreate || $canManage)
                    <div class="absolute right-1 flex items-center gap-0.5 opacity-0 transition-opacity group-hover/row:opacity-100 focus-within:opacity-100">
                        @if ($canCreate)
                            <button
                                type="button"
                                wire:click="startCreating({{ $page->id }})"
                                class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-500"
                                title="Add a page inside {{ $page->title }}"
                                aria-label="Add a page inside {{ $page->title }}"
                            >
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        @endif

                        @if ($canManage)
                            <div class="relative">
                                <button
                                    type="button"
                                    x-on:click="menu = ! menu"
                                    x-bind:aria-expanded="menu ? 'true' : 'false'"
                                    class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-500"
                                    title="More actions for {{ $page->title }}"
                                    aria-label="More actions for {{ $page->title }}"
                                >
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                                    </svg>
                                </button>

                                <div
                                    x-show="menu"
                                    x-cloak
                                    x-on:click.outside="menu = false"
                                    x-transition.opacity.duration.100ms
                                    class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-lg border border-slate-200 bg-surface-raised py-1 text-[13px] shadow-lg"
                                >
                                    <a
                                        href="{{ route('docs.show', ['board' => $board, 'slug' => $page->slug]) }}"
                                        wire:navigate
                                        class="block px-3 py-1.5 text-slate-700 hover:bg-slate-100"
                                    >
                                        Open
                                    </a>

                                    @if ($canCreate)
                                        <button
                                            type="button"
                                            wire:click="startCreating({{ $page->id }})"
                                            x-on:click="menu = false"
                                            class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                                        >
                                            New page inside
                                        </button>
                                    @endif

                                    <p class="px-3 pt-1.5 pb-1 text-[11px] text-slate-400">
                                        Rename by editing the title on the page. Drag a row to move it.
                                    </p>

                                    <button
                                        type="button"
                                        wire:click="deletePage({{ $page->id }})"
                                        x-on:click="menu = false"
                                        class="block w-full border-t border-slate-100 px-3 py-1.5 text-left text-rose-600 hover:bg-rose-50"
                                        x-confirm="@js([
                                            'title' => 'Delete “'.$page->title.'”?',
                                            'body' => 'The page and everything filed beneath it are deleted. There is no revision history to bring them back from.',
                                            'confirmText' => 'Delete page',
                                            'tone' => 'danger',
                                        ])"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($hasChildren)
                {{--
                    x-show plus a server-rendered inline style, not a `hidden`
                    class. Alpine's x-show writes el.style.display, so an inline
                    style is the same channel and the two cannot fight; a class
                    would win over x-show and the branch would never open.

                    Rendering the closed state server-side is what stops a
                    collapsed tree flashing fully expanded before Alpine starts.
                --}}
                <div
                    id="doc-children-{{ $page->id }}"
                    x-show="$store.docTree.isOpen({{ $page->id }}, {{ $open ? 'true' : 'false' }})"
                    @if (! $open) style="display: none" @endif
                >
                    <x-docs.tree
                        :nodes="$node->children"
                        :board="$board"
                        :current="$current"
                        :can-manage="$canManage"
                        :can-create="$canCreate"
                        :sortable="$sortable"
                        :sort-group="$sortGroup"
                        :parent-id="$page->id"
                        :open-ids="$openIds"
                    />
                </div>
            @elseif ($sortable)
                {{-- An empty level, kept as a drop target so a page can be
                     filed under one that has no children yet. Nothing to
                     collapse, so no disclosure and no x-show. --}}
                <x-docs.tree
                    :nodes="$node->children"
                    :board="$board"
                    :current="$current"
                    :can-manage="$canManage"
                    :can-create="$canCreate"
                    :sortable="$sortable"
                    :sort-group="$sortGroup"
                    :parent-id="$page->id"
                    :open-ids="$openIds"
                />
            @endif
        </li>
    @endforeach
</ul>
