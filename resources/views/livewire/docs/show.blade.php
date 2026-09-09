@php
    $sortGroup = 'docs-'.$board->id;
@endphp

{{--
    A board's documentation workspace.

    Three columns on a wide screen — page tree, document, contents — and one
    column with the tree as a drawer on a narrow one. The middle column is the
    only one that scrolls with the page; the two beside it are sticky, because a
    navigation list that scrolls away is a navigation list you have to scroll
    back to.

    The document is the point, so the chrome around it is deliberately quiet:
    one border on the tree, no card around the prose, and a header that is a
    title and a line of grey text rather than a panel.

    No `dark:` anywhere. resources/css/app.css remaps the whole palette for the
    dark appearance, so every token used here is already correct in both.

    `$breadcrumb` is the ancestor collection from DocPageFinder::ancestors(),
    which is empty when any page above this one is hidden from the viewer.
    Breadcrumbs::docs() takes it as given and never looks up a parent itself.
--}}
<div
    x-data="{ treeOpen: false }"
    x-on:keydown.escape.window="treeOpen = false"
    class="-mt-2 lg:flex lg:items-start lg:gap-6"
>
    {{-- Dims the page behind the drawer. Mobile only; on desktop the tree is
         part of the layout and there is nothing to dim. --}}
    <div
        x-show="treeOpen"
        x-cloak
        x-on:click="treeOpen = false"
        class="fixed inset-0 z-30 bg-scrim/40 lg:hidden"
        aria-hidden="true"
    ></div>

    {{-- ------------------------------------------------------------- --}}
    {{-- Page tree                                                      --}}
    {{-- ------------------------------------------------------------- --}}
    {{--
        One element, two presentations. Rendered once rather than twice for
        desktop and mobile, because two copies would mean two sets of
        wire:sort:item elements carrying the same page ids — and the drag
        plugin would have no way to tell which list a drop belonged to.

        The transform is bound by Alpine and overridden by lg:translate-x-0
        rather than driven by x-show, which would set display:none and hide the
        tree on desktop as well.
    --}}
    <aside
        id="doc-tree"
        x-init="$store.docTree.use(@js($board->id), @js($openIds))"
        x-bind:class="treeOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed top-0 bottom-0 left-0 z-40 flex w-72 max-w-[85vw] flex-col border-r border-slate-200 bg-surface-raised shadow-xl transition-transform duration-200 lg:sticky lg:top-6 lg:bottom-auto lg:left-auto lg:z-auto lg:h-[calc(100dvh-5rem)] lg:w-60 lg:max-w-none lg:shrink-0 lg:translate-x-0 lg:rounded-xl lg:border lg:border-slate-200 lg:bg-surface lg:shadow-xs"
        aria-label="Documentation pages"
    >
        {{-- Header: whose documentation this is, and the one control that
             creates something. Fixed while the tree below it scrolls. --}}
        <div class="shrink-0 border-b border-slate-100 px-3 py-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-slate-900">{{ $board->name }}</p>
                    <p class="truncate text-[11px] text-slate-500">Documentation</p>
                </div>

                <button
                    type="button"
                    class="-mr-1 shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 lg:hidden"
                    x-on:click="treeOpen = false"
                    aria-label="Close the page list"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="relative mt-2.5">
                <svg class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>

                <input
                    type="search"
                    placeholder="Search documentation…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search documentation"
                    class="w-full rounded-md border border-slate-200 bg-surface-sunken py-1.5 pr-2 pl-8 text-[13px] text-slate-800 placeholder:text-slate-400 focus:border-brand-400 focus:bg-surface focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                >
            </div>

            @if ($canCreate)
                <button
                    type="button"
                    wire:click="startCreating"
                    class="mt-2 flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px] font-medium text-brand-700 transition hover:bg-brand-50"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New page
                </button>
            @endif
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2 py-2">
            @if ($creating)
                {{-- The create form sits in the tree rather than in a modal:
                     where a page will appear is half the decision, and the
                     tree is the only thing that shows it. --}}
                <form wire:submit="createPage" class="mb-2 rounded-lg border border-brand-200 bg-brand-50/50 p-2">
                    <p class="mb-1.5 text-[11px] text-slate-600">
                        {{ $newParentId ? 'New page inside the selected page' : 'New top-level page' }}
                    </p>

                    <div class="flex items-start gap-1.5">
                        <x-docs.icon-picker :current="$newIcon" property="newIcon" />

                        <div class="min-w-0 flex-1">
                            <input
                                type="text"
                                wire:model="newTitle"
                                placeholder="Page title"
                                maxlength="200"
                                autofocus
                                @class([
                                    'w-full rounded-md border bg-surface px-2 py-1.5 text-[13px] focus:ring-2 focus:ring-brand-500/20 focus:outline-none',
                                    'border-slate-200 focus:border-brand-400' => ! $errors->has('newTitle'),
                                    'border-rose-300 focus:border-rose-400' => $errors->has('newTitle'),
                                ])
                            >

                            @error('newTitle')
                                <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-2 flex items-center gap-1.5">
                        <button type="submit" class="rounded-md bg-brand-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-brand-700">
                            Create
                        </button>
                        <button type="button" wire:click="cancelCreating" class="rounded-md px-2 py-1 text-xs text-slate-600 transition hover:bg-slate-100">
                            Cancel
                        </button>
                    </div>

                    <p class="mt-1.5 text-[11px] text-slate-500">Internal until you publish it.</p>
                </form>
            @endif

            @error('tree')
                <p class="mb-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5 text-[11px] text-rose-700">{{ $message }}</p>
            @enderror

            @if ($searching)
                {{-- ----------------------------------------------------- --}}
                {{-- Search results                                        --}}
                {{-- ----------------------------------------------------- --}}
                {{--
                    Not the tree. A result is shown with the path above it and a
                    window of its text, because documentation titles repeat —
                    every product has three pages called "Overview" — and the
                    path is what tells them apart. Reordering is not offered
                    here: a flat result list is not the tree, so an index in it
                    would mean nothing.
                --}}
                <p class="px-1 pb-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase">
                    {{ $results->count() }} {{ Str::plural('result', $results->count()) }}
                </p>

                @forelse ($results as $result)
                    <a
                        href="{{ route('docs.show', ['board' => $board, 'slug' => $result->page->slug]) }}"
                        wire:navigate
                        wire:key="doc-result-{{ $result->page->id }}"
                        @class([
                            'mb-0.5 block rounded-md px-2 py-1.5 transition',
                            'bg-brand-50' => $page !== null && $page->id === $result->page->id,
                            'hover:bg-slate-100' => $page === null || $page->id !== $result->page->id,
                        ])
                    >
                        <span class="flex items-center gap-1.5">
                            <span class="w-4 shrink-0 text-center text-[13px]" aria-hidden="true">
                                @if (filled($result->page->icon))
                                    {{ $result->page->icon }}
                                @else
                                    <svg class="mx-auto size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                    </svg>
                                @endif
                            </span>

                            <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-slate-800">
                                {{ $result->page->title }}
                            </span>
                        </span>

                        @if ($result->path !== [])
                            <span class="mt-0.5 block truncate pl-[1.375rem] text-[11px] text-slate-500">
                                {{ implode(' → ', $result->path) }}
                            </span>
                        @endif

                        @if ($result->snippet !== '')
                            <span class="mt-0.5 line-clamp-2 block pl-[1.375rem] text-[11px] leading-4 text-slate-500">
                                {{ $result->snippet }}
                            </span>
                        @endif
                    </a>
                @empty
                    <p class="px-2 py-6 text-center text-[13px] text-slate-400">
                        Nothing matches &ldquo;{{ $search }}&rdquo;.
                    </p>
                @endforelse
            @elseif ($tree->isEmpty())
                <p class="px-2 py-6 text-center text-[13px] text-slate-400">No pages yet.</p>
            @else
                <x-docs.tree
                    :nodes="$tree"
                    :board="$board"
                    :current="$page"
                    :can-manage="$canManage"
                    :can-create="$canCreate"
                    :sortable="$canManage"
                    :sort-group="$sortGroup"
                    :open-ids="$openIds"
                />
            @endif
        </div>
    </aside>

    {{-- ------------------------------------------------------------- --}}
    {{-- Document                                                       --}}
    {{-- ------------------------------------------------------------- --}}
    <main class="min-w-0 flex-1 pt-2 lg:pt-0">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0 flex-1">
                <x-ui.breadcrumbs :trail="\App\Support\Breadcrumbs::docs($board, $breadcrumb, $page)" />
            </div>

            <button
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-md border border-slate-200 bg-surface px-2 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50 lg:hidden"
                x-on:click="treeOpen = true"
                aria-controls="doc-tree"
            >
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                Pages
            </button>
        </div>

        @if ($page === null)
            {{-- ----------------------------------------------------- --}}
            {{-- Empty state                                            --}}
            {{-- ----------------------------------------------------- --}}
            <div class="rounded-xl border border-slate-200 bg-surface px-6 py-16 text-center shadow-xs">
                <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-xl bg-brand-50 text-2xl" aria-hidden="true">
                    📚
                </div>

                @if ($tree->isEmpty() && ! $searching)
                    <h1 class="text-base font-semibold text-slate-900">Your documentation starts here</h1>
                    <p class="mx-auto mt-1.5 max-w-sm text-[13px] leading-5 text-slate-500">
                        @if ($canCreate)
                            Create the first page to begin building this board&rsquo;s knowledge base.
                            Every page is internal until you publish it.
                        @else
                            Nothing has been written for this board yet.
                        @endif
                    </p>

                    @if ($canCreate)
                        <button
                            type="button"
                            wire:click="startCreating"
                            class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-1.5 text-[13px] font-medium text-white transition hover:bg-brand-700"
                        >
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Create your first page
                        </button>
                    @endif
                @else
                    <h1 class="text-base font-semibold text-slate-900">{{ $board->name }} documentation</h1>
                    <p class="mx-auto mt-1.5 max-w-sm text-[13px] leading-5 text-slate-500">
                        Choose a page from the list to start reading.
                    </p>
                @endif
            </div>
        @else
            @if ($hasDraft && ! $recoveredDraft)
                {{--
                    Somebody left unpublished changes behind. Amber because it
                    is a warning about work that is not yet part of the page —
                    the one meaning amber still carries here.
                --}}
                <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] text-amber-900">
                    <span class="min-w-0 flex-1">
                        Unpublished changes
                        @if ($page->draftBy)
                            by {{ $page->draftBy->name }}
                        @endif
                        from {{ $page->draft_saved_at->diffForHumans() }}.
                    </span>

                    <button type="button" wire:click="startEditing" class="rounded-md bg-amber-600 px-2 py-1 text-xs font-medium text-white transition hover:bg-amber-700">
                        Resume
                    </button>

                    <button
                        type="button"
                        wire:click="discardDraft"
                        class="rounded-md px-2 py-1 text-xs font-medium text-amber-900 transition hover:bg-amber-100"
                        x-confirm="@js([
                            'title' => 'Discard the unpublished changes?',
                            'body' => 'The page keeps what was last published. Everything typed since then is thrown away, and there is no revision history to get it back from.',
                            'confirmText' => 'Discard changes',
                            'tone' => 'danger',
                        ])"
                    >
                        Discard
                    </button>
                </div>
            @endif

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-surface shadow-xs">
                {{-- ------------------------------------------------- --}}
                {{-- Document header                                    --}}
                {{-- ------------------------------------------------- --}}
                <header class="border-b border-slate-100 px-5 py-4 sm:px-8">
                    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                        <div class="flex min-w-0 flex-1 items-start gap-2">
                            @if ($canEditPage)
                                <x-docs.icon-picker :current="$icon" method="setIcon" size="lg" class="-ml-1.5" />
                            @elseif (filled($page->icon))
                                <span class="text-3xl leading-none" aria-hidden="true">{{ $page->icon }}</span>
                            @endif

                            <div class="min-w-0 flex-1">
                                @if ($canEditPage)
                                    {{--
                                        The title is the document's title, so it
                                        is styled as one rather than as a
                                        labelled field in a stack. The label is
                                        still there for a screen reader.

                                        .blur rather than .live: leaving the
                                        field is what commits a rename, so it
                                        costs one request per rename instead of
                                        one per keystroke.
                                    --}}
                                    <label for="page-title" class="sr-only">Title</label>
                                    <input
                                        id="page-title"
                                        type="text"
                                        wire:model.blur="title"
                                        placeholder="Untitled page"
                                        maxlength="200"
                                        @class([
                                            'w-full border-0 bg-transparent p-0 text-2xl font-semibold tracking-tight placeholder:text-slate-300 focus:ring-0 focus:outline-none',
                                            'text-slate-900' => ! $errors->has('title'),
                                            'text-rose-700' => $errors->has('title'),
                                        ])
                                        @if ($errors->has('title')) aria-invalid="true" @endif
                                    >
                                @else
                                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $page->title }}</h1>
                                @endif

                                @error('title')
                                    <p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>
                                @enderror

                                {{-- The byline. Only what the table already
                                     holds: who made it, who last changed it,
                                     and when. --}}
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-500">
                                    <span>
                                        Updated
                                        <time
                                            datetime="{{ $page->updated_at->toIso8601String() }}"
                                            title="{{ $page->updated_at->format('j M Y \a\t H:i') }}"
                                        >{{ $page->updated_at->diffForHumans() }}</time>
                                        @if ($page->editor)
                                            by {{ $page->editor->name }}
                                        @endif
                                    </span>

                                    @if ($page->creator)
                                        <span aria-hidden="true">&middot;</span>
                                        <span>Created by {{ $page->creator->name }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($page->customer_visible)
                                <x-ui.badge variant="emerald">Published</x-ui.badge>
                            @elseif ($canSeeInternal)
                                <x-ui.internal-badge label="Internal" />
                            @endif

                            @if ($canPublish || $canCreate || $canDeletePage)
                                <div x-data="{ menu: false }" x-on:keydown.escape.stop="menu = false" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="menu = ! menu"
                                        x-bind:aria-expanded="menu ? 'true' : 'false'"
                                        class="flex items-center gap-1 rounded-md border border-slate-200 bg-surface px-2 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                    >
                                        More
                                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>

                                    <div
                                        x-show="menu"
                                        x-cloak
                                        x-on:click.outside="menu = false"
                                        x-transition.opacity.duration.100ms
                                        class="absolute right-0 z-30 mt-1 w-64 overflow-hidden rounded-lg border border-slate-200 bg-surface-raised py-1 text-[13px] shadow-lg"
                                    >
                                        @if ($canCreate)
                                            <button
                                                type="button"
                                                wire:click="startCreating({{ $page->id }})"
                                                x-on:click="menu = false"
                                                class="block w-full px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                                            >
                                                New page inside this one
                                            </button>
                                        @endif

                                        @if ($canPublish)
                                            <div class="border-t border-slate-100 px-3 pt-2 pb-1">
                                                <p class="text-[11px] leading-4 text-slate-500">
                                                    @if ($page->customer_visible)
                                                        Customers on this board can read this page. Making it
                                                        internal also hides every page filed under it.
                                                    @else
                                                        Internal. Customers never receive it &mdash; not in the
                                                        tree, not by URL, not through a link in a ticket.
                                                    @endif
                                                </p>
                                            </div>

                                            @error('visibility')
                                                <p class="mx-3 mb-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] text-rose-700">
                                                    {{ $message }}
                                                </p>
                                            @enderror

                                            <button
                                                type="button"
                                                wire:click="toggleVisibility"
                                                x-on:click="menu = false"
                                                class="block w-full px-3 py-1.5 text-left font-medium text-slate-800 hover:bg-slate-100"
                                                {{-- Same asymmetry as a ticket's visibility; see tickets/show.blade.php. --}}
                                                x-confirm="@js($page->customer_visible
                                                    ? [
                                                        'title' => 'Make this page internal again?',
                                                        'body' => 'Customers stop seeing it, and every page filed under it is hidden too — even the ones published individually.',
                                                        'confirmText' => 'Make internal',
                                                        'tone' => 'brand',
                                                    ]
                                                    : [
                                                        'title' => 'Publish this page to customers?',
                                                        'body' => 'Everyone on this board with customer access will be able to read it. Check it says nothing internal before publishing — this cannot be un-seen.',
                                                        'confirmText' => 'Publish page',
                                                        'tone' => 'warning',
                                                    ])"
                                            >
                                                {{ $page->customer_visible ? 'Make internal' : 'Publish to customers' }}
                                            </button>
                                        @endif

                                        @if ($canEditPage)
                                            <button
                                                type="button"
                                                wire:click="toggleMarkdownMode"
                                                x-on:click="menu = false"
                                                class="block w-full border-t border-slate-100 px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100"
                                            >
                                                {{ $markdownMode ? 'Switch to rich text' : 'Edit as Markdown' }}
                                            </button>
                                        @endif

                                        @if ($canDeletePage)
                                            <button
                                                type="button"
                                                wire:click="destroyPage"
                                                x-on:click="menu = false"
                                                class="block w-full border-t border-slate-100 px-3 py-1.5 text-left text-rose-600 hover:bg-rose-50"
                                                x-confirm="@js([
                                                    'title' => 'Delete “'.$page->title.'”?',
                                                    'body' => 'Every page filed under it, and their attachments, go with it. There is no revision history to bring them back from.',
                                                    'confirmText' => 'Delete page',
                                                    'tone' => 'danger',
                                                ])"
                                            >
                                                Delete page
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </header>

                @if ($recoveredDraft)
                    <p class="border-b border-amber-200 bg-amber-50 px-5 py-2 text-[11px] text-amber-900 sm:px-8">
                        Picked up from unpublished changes{{ $recoveredFrom === '' ? '' : ' — '.$recoveredFrom }}.
                        Publish to make them part of the page.
                    </p>
                @endif

                {{-- ------------------------------------------------- --}}
                {{-- Body                                               --}}
                {{-- ------------------------------------------------- --}}
                <div id="doc-content" class="px-5 py-5 sm:px-8 sm:py-6">
                    @if ($canEditPage)
                        {{--
                            The editor, live, with no Edit step in front of it.
                            Wrapped in a form so the rich editor can flush its
                            document on submit — see resources/js/editor.js —
                            and so Publish is a submit rather than a click that
                            races the editor's own sync.
                        --}}
                        <form wire:submit="save">
                            @if ($markdownMode)
                                {{--
                                    Preview belongs to Markdown mode and only to
                                    it. The rich surface already shows the
                                    document as it will read, so previewing that
                                    would be a copy of what is on screen.
                                --}}
                                <div class="mb-3 flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="togglePreview"
                                        class="rounded-md border border-slate-200 bg-surface px-2 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                    >
                                        {{ $previewing ? 'Write' : 'Preview' }}
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="toggleMarkdownMode"
                                        class="rounded-md px-2 py-1 text-xs text-slate-600 transition hover:bg-slate-100"
                                    >
                                        Rich text
                                    </button>
                                </div>
                            @endif

                            @if ($previewing && $markdownMode)
                                <div class="markdown mx-auto min-h-96 max-w-3xl rounded-lg border border-slate-200 bg-surface-sunken p-4">
                                    {{-- Safe unescaped: App\Support\Markdown strips raw HTML and
                                         unsafe schemes, and the post-processors escape what they add. --}}
                                    {!! $bodyHtml ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                                </div>
                            @elseif ($markdownMode)
                                <x-ui.field
                                    label="Content"
                                    for="page-body"
                                    :error="$errors->first('bodyMd')"
                                    hint="Markdown: headings, lists, checklists, tables, code blocks, images and links. Raw HTML is stripped. Ticket keys such as {{ $board->ticket_prefix }}-1 link themselves."
                                >
                                    <x-ui.textarea id="page-body" rows="26" class="font-mono text-xs"
                                                   wire:model="bodyMd" :invalid="$errors->has('bodyMd')">{{ $bodyMd }}</x-ui.textarea>
                                </x-ui.field>
                            @else
                                {{--
                                    wire:key differs between the two branches on
                                    purpose. The editor lives inside wire:ignore,
                                    so Livewire's morph has to be made to replace
                                    the element rather than patch around it —
                                    otherwise switching back from Markdown would
                                    leave the old, stale editor in place.
                                --}}
                                <div wire:key="rich-{{ $page->id }}">
                                    <x-ui.rich-editor
                                        property="descriptionHtml"
                                        :html="$editorHtml"
                                        :uploader="$canAttachInEditor ? 'pendingUploads' : null"
                                        autosave="autosave"
                                        {{-- What the autosave actually did, in
                                             the indicator's own words. --}}
                                        :saved-label="$autosavesLive ? 'Saved' : 'Draft saved'"
                                        label="Page content"
                                        min-height="tall"
                                        placeholder="Write the page. Use the toolbar, or Markdown shortcuts like ## and - [ ]."
                                        :invalid="$errors->has('bodyMd')"
                                    />
                                </div>

                                @error('bodyMd')
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror

                                @error('pendingUploads.*')
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror
                            @endif

                            {{--
                                What happens to what you just typed.

                                The two branches are the whole autosave design in
                                one line each: an internal page is simply kept,
                                and a published one waits for a decision. Saying
                                which is which here is what stops somebody
                                assuming a customer is already reading their
                                half-finished paragraph.
                            --}}
                            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                                @if ($autosavesLive)
                                    <p class="text-[11px] text-slate-500">
                                        Changes are saved automatically. This page is internal, so only the
                                        delivery team can read it.
                                    </p>
                                @else
                                    <button type="submit" class="rounded-md bg-brand-600 px-3 py-1.5 text-[13px] font-medium text-white transition hover:bg-brand-700">
                                        Publish changes
                                    </button>

                                    @if ($hasDraft)
                                        <button
                                            type="button"
                                            wire:click="cancelEditing"
                                            class="rounded-md px-2 py-1.5 text-[13px] text-slate-600 transition hover:bg-slate-100"
                                            x-confirm="@js([
                                                'title' => 'Discard the unpublished changes?',
                                                'body' => 'The page keeps what was last published. Everything typed since then is thrown away.',
                                                'confirmText' => 'Discard changes',
                                                'tone' => 'danger',
                                            ])"
                                        >
                                            Discard
                                        </button>
                                    @endif

                                    <p class="text-[11px] text-slate-500">
                                        Saved as you type, but customers keep seeing the published version
                                        until you publish these changes.
                                    </p>
                                @endif
                            </div>
                        </form>
                    @else
                        @if (trim((string) $page->body_md) === '')
                            <p class="text-sm text-slate-400">This page is empty.</p>
                        @else
                            {{-- max-w-3xl is the measure, not the container: a line
                                 of prose the full width of a desktop is hard to
                                 read, and documentation is read more than anything
                                 else in this product. --}}
                            <div class="markdown mx-auto max-w-3xl">{!! $bodyHtml !!}</div>
                        @endif
                    @endif
                </div>
            </article>

            @if ($showAttachments)
                <div class="mt-4">
                    <livewire:docs.components.attachments :page="$page" :key="'doc-files-'.$page->id" />
                </div>
            @endif
        @endif
    </main>

    {{-- ------------------------------------------------------------- --}}
    {{-- On this page                                                   --}}
    {{-- ------------------------------------------------------------- --}}
    {{--
        Only on the widest screens, and only when the document has enough
        headings to be worth navigating — the component renders nothing below
        three, so a short page gets its space back rather than a stub list.

        2xl, not xl. The layout gives this section 80rem beside a 16rem global
        sidebar, so at xl a third column would leave the document itself about
        33rem wide — narrower than the editor's own toolbar wants, and the
        document is the thing people came for.

        Headings come from the DOM, so this tracks a document being typed as
        well as one being read. See resources/js/doc-toc.js.
    --}}
    @if ($page !== null)
        <aside
            x-data="docToc('#doc-content')"
            x-show="headings.length > 0"
            x-cloak
            class="sticky top-6 hidden w-52 shrink-0 2xl:block"
            aria-label="On this page"
        >
            <p class="mb-2 px-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase">On this page</p>

            {{--
                Buttons, not links. There is no anchor to point at: headings
                carry no ids, because inside the editor ProseMirror strips
                attributes this page did not put there — see
                resources/js/doc-toc.js. A control that scrolls and has no URL
                is a button, and calling it one keeps the keyboard and a screen
                reader honest about what it does.
            --}}
            <ul class="space-y-px border-l border-slate-200">
                <template x-for="heading in headings" :key="heading.index">
                    <li>
                        <button
                            type="button"
                            :style="indent(heading.level)"
                            x-on:click="go(heading.index)"
                            :class="activeIndex === heading.index
                                ? 'border-brand-500 text-brand-700 font-medium'
                                : 'border-transparent text-slate-500 hover:text-slate-800'"
                            class="-ml-px block w-full border-l-2 py-1 pr-2 pl-2.5 text-left text-[12px] leading-4 transition-colors"
                            x-text="heading.text"
                        ></button>
                    </li>
                </template>
            </ul>
        </aside>
    @endif
</div>
