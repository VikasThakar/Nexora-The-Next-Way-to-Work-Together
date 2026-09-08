@props([
    'nodes',
    'board',
    'current' => null,
    'canManage' => false,
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

    Drag and drop uses wire:sort, the same Alpine Sort plugin the Kanban board
    uses. Every level shares one group name, so a page can be dragged between
    levels as well as within one, and each list bakes its own parent id into the
    handler — so the server is always told where the page landed rather than
    having to guess.

    Only <li> page elements may live directly inside the list: the index the
    browser reports is the child index, so any other node in here would shift
    every position by one.

    Expand and collapse is entirely in the browser — one Alpine store, backed by
    localStorage, in resources/js/doc-tree.js. It is deliberately not Livewire
    state: opening a branch is not worth a round trip, and it is not worth a
    column either. What the server contributes is openIds, above.
--}}
<ul
    @if ($sortable)
        wire:sort="$wire.movePage($item, $position, {{ $parentId === null ? 'null' : $parentId }})"
        wire:sort:group="{{ $sortGroup }}"
    @endif
    @class([
        'space-y-0.5',
        'ml-2.5 border-l border-slate-200 pl-2' => $parentId !== null,
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

        <li wire:key="doc-page-{{ $page->id }}" @if ($sortable) wire:sort:item="{{ $page->id }}" @endif>
            {{--
                The row: disclosure, then the link. Two controls side by side
                rather than nested, because a button inside an anchor is not
                valid HTML and behaves differently in every browser.
            --}}
            <div
                @class([
                    'group flex items-center gap-0.5 rounded-lg pr-1.5 transition',
                    'bg-brand-50' => $isCurrent,
                    'hover:bg-slate-100' => ! $isCurrent,
                ])
            >
                @if ($sortable)
                    {{--
                        The grab area. Naming it as a sort handle restricts
                        dragging to this glyph, which is what keeps a click on
                        the chevron or the title from being read as the start
                        of a drag.

                        Not a security control — DocPagePolicy::move is — but
                        it is only offered to people who may reorganise.
                    --}}
                    <span
                        wire:sort:handle
                        class="shrink-0 cursor-grab px-0.5 py-1.5 text-slate-300 group-hover:text-slate-400"
                        aria-hidden="true"
                    >
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
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
                        class="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-500"
                    >
                        <svg
                            class="size-3 transition-transform"
                            x-bind:class="$store.docTree.isOpen({{ $page->id }}, {{ $open ? 'true' : 'false' }}) ? 'rotate-90' : 'rotate-0'"
                            fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                @else
                    {{-- Keeps every title on the same left edge whether or not
                         the page has children below it. --}}
                    <span class="size-5 shrink-0" aria-hidden="true"></span>
                @endif

                <a
                    href="{{ route('docs.show', ['board' => $board, 'slug' => $page->slug]) }}"
                    wire:navigate
                    @class([
                        'flex min-w-0 flex-1 items-center gap-2 py-1.5 text-sm',
                        'font-medium text-brand-800' => $isCurrent,
                        'text-slate-700' => ! $isCurrent,
                    ])
                    @if ($isCurrent) aria-current="page" @endif
                >
                    <span class="min-w-0 flex-1 truncate">{{ $page->title }}</span>

                    @if ($page->customer_visible)
                        <span class="shrink-0 text-emerald-600" title="Visible to customers">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </span>
                    @elseif ($canManage)
                        <span class="shrink-0 text-slate-400" title="Internal only">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                        </span>
                    @endif
                </a>
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
                    :sortable="$sortable"
                    :sort-group="$sortGroup"
                    :parent-id="$page->id"
                    :open-ids="$openIds"
                />
            @endif
        </li>
    @endforeach
</ul>
