@props([
    'nodes',
    'board',
    'current' => null,
    'canManage' => false,
    'sortable' => false,
    'sortGroup' => 'docs',
    'parentId' => null,
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
--}}
<ul
    @if ($sortable)
        wire:sort="$wire.movePage($item, $position, {{ $parentId === null ? 'null' : $parentId }})"
        wire:sort:group="{{ $sortGroup }}"
    @endif
    @class([
        'space-y-0.5',
        'ml-3 border-l border-slate-200 pl-2' => $parentId !== null,
        'min-h-2' => $sortable,
    ])
>
    @foreach ($nodes as $node)
        @php
            $page = $node->page;
            $isCurrent = $current !== null && $current->id === $page->id;
        @endphp

        <li wire:key="doc-page-{{ $page->id }}" @if ($sortable) wire:sort:item="{{ $page->id }}" @endif>
            <a
                href="{{ route('docs.show', ['board' => $board, 'slug' => $page->slug]) }}"
                wire:navigate
                @class([
                    'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition',
                    'bg-brand-50 font-medium text-brand-800' => $isCurrent,
                    'text-slate-700 hover:bg-slate-100' => ! $isCurrent,
                ])
            >
                @if ($canManage)
                    {{-- Drag handle. Only rendered for people who may
                         reorganise; the server checks the ability again. --}}
                    <svg class="size-3.5 shrink-0 cursor-grab text-slate-300 group-hover:text-slate-400"
                         fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                    </svg>
                @endif

                <span class="min-w-0 flex-1 truncate">{{ $page->title }}</span>

                @if ($page->customer_visible)
                    <span class="shrink-0 text-emerald-600" title="Visible to customers">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </span>
                @elseif ($canManage)
                    <span class="shrink-0 text-amber-500" title="Internal only">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </span>
                @endif
            </a>

            @if ($node->children->isNotEmpty() || $sortable)
                <x-docs.tree
                    :nodes="$node->children"
                    :board="$board"
                    :current="$current"
                    :can-manage="$canManage"
                    :sortable="$sortable"
                    :sort-group="$sortGroup"
                    :parent-id="$page->id"
                />
            @endif
        </li>
    @endforeach
</ul>
