@php
    $sortGroup = 'docs-'.$board->id;
@endphp

{{--
    A board's documentation: page tree on the left, one document on the right.

    Laid out as a document workspace rather than as another card screen — the
    tree is a persistent, scrolling table of contents, and the page itself gets
    a real title, a byline and a measured column of prose. On a narrow screen
    the tree becomes a drawer, because a sidebar that keeps a third of a phone
    is a sidebar that stops the writing.

    `$breadcrumb` is the ancestor collection from DocPageFinder::ancestors(),
    which is empty when any page above this one is hidden from the viewer.
    Breadcrumbs::docs() takes it as given and never looks up a parent itself.
--}}
<div x-data="{ treeOpen: false }" x-on:keydown.escape.window="treeOpen = false">
    {{-- ------------------------------------------------------------- --}}
    {{-- Section bar                                                    --}}
    {{-- ------------------------------------------------------------- --}}
    {{--
        No <h1> here on purpose: the document below carries it, which is what
        makes this read as a document rather than as a page about a document.
    --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <x-ui.breadcrumbs :trail="\App\Support\Breadcrumbs::docs($board, $breadcrumb, $page)" />
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <x-ui.button
                type="button"
                variant="secondary"
                size="sm"
                class="lg:hidden"
                x-on:click="treeOpen = true"
                aria-controls="doc-tree"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
                Pages
            </x-ui.button>

            @unless ($canSeeInternal)
                <x-ui.badge variant="amber">Customer view</x-ui.badge>
            @endunless

            @if ($canCreate)
                <x-ui.button type="button" size="sm" wire:click="startCreating">New page</x-ui.button>
            @endif
        </div>
    </div>

    @error('tree')
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>
    @enderror

    <div class="lg:flex lg:items-start lg:gap-8">
        {{-- Dims the page behind the drawer. Mobile only; on desktop the tree
             is part of the layout and there is nothing to dim. --}}
        <div
            x-show="treeOpen"
            x-cloak
            x-on:click="treeOpen = false"
            class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
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
            rather than driven by x-show, which would set display:none and hide
            the tree on desktop as well.
        --}}
        <aside
            id="doc-tree"
            x-init="$store.docTree.use(@js($board->id), @js($openIds))"
            x-bind:class="treeOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed top-0 bottom-0 left-0 z-40 flex w-80 max-w-[85vw] flex-col overflow-y-auto border-r border-slate-200 bg-white p-4 shadow-xl transition-transform duration-200 lg:sticky lg:top-8 lg:bottom-auto lg:left-auto lg:z-auto lg:max-h-[calc(100dvh-6rem)] lg:w-64 lg:max-w-none lg:shrink-0 lg:translate-x-0 lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none"
            aria-label="Documentation pages"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <p class="truncate text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
                    {{ $board->name }}
                </p>

                <button
                    type="button"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 lg:hidden"
                    x-on:click="treeOpen = false"
                    aria-label="Close the page list"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <x-ui.input
                type="search"
                placeholder="Search pages…"
                class="mb-3"
                wire:model.live.debounce.300ms="search"
                aria-label="Search documentation"
            />

            @if ($creating)
                <form wire:submit="createPage" class="mb-3 rounded-lg border border-brand-300 bg-brand-50/40 p-2">
                    <p class="mb-1.5 text-[11px] text-slate-600">
                        {{ $newParentId ? 'New child page' : 'New top-level page' }} &middot; internal until published
                    </p>
                    <x-ui.input wire:model="newTitle" placeholder="Page title" autofocus
                                :invalid="$errors->has('newTitle')" />
                    @error('newTitle')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                    <div class="mt-2 flex items-center gap-2">
                        <x-ui.button type="submit" size="sm">Create</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelCreating">Cancel</x-ui.button>
                    </div>
                </form>
            @endif

            @if ($tree->isEmpty())
                <p class="px-2 py-6 text-center text-sm text-slate-400">
                    {{ $searching ? 'No pages match.' : 'No pages yet.' }}
                </p>
            @else
                @if ($searching)
                    <p class="mb-2 px-2 text-[11px] tracking-wide text-slate-400 uppercase">Search results</p>
                @endif

                {{-- Reordering is disabled while searching: the flat result
                     list is not the tree, so an index in it means nothing. --}}
                <x-docs.tree
                    :nodes="$tree"
                    :board="$board"
                    :current="$page"
                    :can-manage="$canManage"
                    :sortable="$canManage && ! $searching"
                    :sort-group="$sortGroup"
                    :open-ids="$openIds"
                />
            @endif
        </aside>

        {{-- ------------------------------------------------------------- --}}
        {{-- Document                                                       --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="min-w-0 flex-1 space-y-6">
            @if ($page === null)
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">Documentation</h1>

                <x-ui.empty-state
                    title="Board documentation"
                    :description="$tree->isEmpty()
                        ? 'Nothing has been written for this board yet.'
                        : 'Choose a page from the list to start reading.'"
                >
                    @if ($canCreate)
                        <x-slot:actions>
                            <x-ui.button type="button" wire:click="startCreating">Write the first page</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @else
                @if ($hasDraft && ! $editing)
                    {{--
                        Somebody left the editor without saving. Amber because
                        it is a warning about work that is not yet part of the
                        page — the one meaning amber still carries here.
                    --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <span class="min-w-0 flex-1">
                            Unsaved changes
                            @if ($page->draftBy)
                                by {{ $page->draftBy->name }}
                            @endif
                            from {{ $page->draft_saved_at->diffForHumans() }}. The page below still reads
                            as it was last saved.
                        </span>

                        <x-ui.button type="button" size="sm" wire:click="startEditing">Resume editing</x-ui.button>

                        <x-ui.button
                            type="button"
                            variant="ghost"
                            size="sm"
                            wire:click="discardDraft"
                            :confirm="[
                                'title' => 'Discard the unsaved changes?',
                                'body' => 'The page keeps what was last saved. Everything typed since then is thrown away, and there is no revision history to get it back from.',
                                'confirmText' => 'Discard changes',
                                'tone' => 'danger',
                            ]"
                        >
                            Discard
                        </x-ui.button>
                    </div>
                @endif

                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    @if ($editing)
                        <form wire:submit="save">
                            <header class="border-b border-slate-100 px-5 py-4 sm:px-8">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
                                        Editing
                                    </p>

                                    <div class="flex items-center gap-1">
                                        @if ($markdownMode)
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                                                {{ $previewing ? 'Write' : 'Preview' }}
                                            </x-ui.button>
                                        @endif

                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleMarkdownMode">
                                            {{ $markdownMode ? 'Rich text' : 'Markdown' }}
                                        </x-ui.button>
                                    </div>
                                </div>

                                {{--
                                    The title is the document's title, so it is
                                    styled as one rather than as a labelled form
                                    field in a stack. The label is still there
                                    for a screen reader.

                                    .blur rather than deferred: leaving the field
                                    is what puts a rename into the draft, so an
                                    interrupted session does not lose it.
                                --}}
                                <label for="page-title" class="sr-only">Title</label>
                                <input
                                    id="page-title"
                                    type="text"
                                    wire:model.blur="title"
                                    placeholder="Untitled page"
                                    maxlength="200"
                                    @class([
                                        'mt-2 w-full border-0 bg-transparent p-0 text-2xl font-semibold tracking-tight placeholder:text-slate-300 focus:ring-0 focus:outline-none',
                                        'text-slate-900' => ! $errors->has('title'),
                                        'text-rose-700' => $errors->has('title'),
                                    ])
                                    @if ($errors->has('title')) aria-invalid="true" @endif
                                >

                                @error('title')
                                    <p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>
                                @enderror
                            </header>

                            @if ($recoveredDraft)
                                <p class="border-b border-amber-200 bg-amber-50 px-5 py-2.5 text-xs text-amber-900 sm:px-8">
                                    Picked up from unsaved changes{{ $recoveredFrom === '' ? '' : ' — '.$recoveredFrom }}.
                                    Save to make them part of the page; cancel to throw them away.
                                </p>
                            @endif

                            <div class="px-5 py-5 sm:px-8">
                                @if ($previewing && $markdownMode)
                                    <div class="markdown mx-auto min-h-96 max-w-3xl rounded-lg border border-slate-200 bg-slate-50 p-4">
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
                                        wire:key differs between the two branches
                                        on purpose. The editor lives inside
                                        wire:ignore, so Livewire's morph has to be
                                        made to replace the element rather than
                                        patch around it — otherwise switching back
                                        from Markdown would leave the old, stale
                                        editor in place.
                                    --}}
                                    <div wire:key="rich-{{ $page->id }}">
                                        <x-ui.rich-editor
                                            property="descriptionHtml"
                                            :html="$editorHtml"
                                            :uploader="$canAttachInEditor ? 'pendingUploads' : null"
                                            autosave="autosave"
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
                            </div>

                            <footer class="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3 sm:px-8">
                                <x-ui.button type="submit">Save page</x-ui.button>

                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    wire:click="cancelEditing"
                                    :confirm="[
                                        'title' => 'Leave without saving?',
                                        'body' => 'The page keeps what was last saved. Everything typed since then, including the autosaved draft, is thrown away.',
                                        'confirmText' => 'Discard and leave',
                                        'tone' => 'danger',
                                    ]"
                                >
                                    Cancel
                                </x-ui.button>

                                <p class="ms-auto text-xs text-slate-500">
                                    Autosaves as a draft. Only <span class="font-medium text-slate-600">Save page</span>
                                    changes what readers see.
                                </p>
                            </footer>
                        </form>
                    @else
                        <header class="border-b border-slate-100 px-5 py-5 sm:px-8 sm:py-6">
                            @if ($page->customer_visible || $canSeeInternal)
                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    @if ($page->customer_visible)
                                        <x-ui.badge variant="emerald">Published to customers</x-ui.badge>
                                    @else
                                        <x-ui.internal-badge label="Internal only" />
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <h1 class="min-w-0 text-2xl font-semibold tracking-tight text-slate-900">
                                    {{ $page->title }}
                                </h1>

                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($canEditPage)
                                        <x-ui.button type="button" size="sm" wire:click="startEditing">Edit</x-ui.button>
                                    @endif

                                    @if ($canCreate)
                                        <x-ui.button type="button" variant="secondary" size="sm"
                                                     wire:click="startCreating({{ $page->id }})">
                                            Add child page
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>

                            {{--
                                The byline. Only what the table already holds:
                                who created it, who last changed it and when.
                                A <time> element so the exact moment is on hover
                                while the line itself stays readable.
                            --}}
                            <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                @if ($page->creator)
                                    <span>Created by {{ $page->creator->name }}</span>
                                    <span aria-hidden="true">&middot;</span>
                                @endif

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
                            </p>
                        </header>

                        <div class="px-5 py-6 sm:px-8 sm:py-8">
                            @if (trim((string) $page->body_md) === '')
                                <p class="text-sm text-slate-400">This page is empty.</p>
                            @else
                                {{-- max-w-3xl is the measure, not the container: a
                                     line of prose the full width of a desktop is
                                     hard to read, and documentation is read more
                                     than anything else in this product. --}}
                                <div class="markdown mx-auto max-w-3xl">{!! $bodyHtml !!}</div>
                            @endif
                        </div>
                    @endif
                </article>

                @if ($showAttachments)
                    <livewire:docs.components.attachments :page="$page" :key="'doc-files-'.$page->id" />
                @endif

                @if ($canPublish)
                    <x-ui.card title="Customer visibility">
                        @error('visibility')
                            <p class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                {{ $message }}
                            </p>
                        @enderror

                        <p class="text-sm text-slate-600">
                            @if ($page->customer_visible)
                                Customers on this board can read this page. Making it internal again also
                                hides every page filed under it.
                            @else
                                This page is internal. Customers never receive it &mdash; not in the tree,
                                not by URL, not through a link in a ticket.
                            @endif
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <x-ui.button
                                type="button"
                                :variant="$page->customer_visible ? 'secondary' : 'primary'"
                                size="sm"
                                wire:click="toggleVisibility"
                                {{-- Same asymmetry as a ticket's visibility; see tickets/show.blade.php. --}}
                                :confirm="$page->customer_visible
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
                                    ]"
                            >
                                {{ $page->customer_visible ? 'Make internal' : 'Publish to customers' }}
                            </x-ui.button>
                        </div>
                    </x-ui.card>
                @endif

                @if ($canDeletePage)
                    <x-ui.card title="Danger zone">
                        @if ($confirmingDelete)
                            <p class="text-sm text-slate-700">
                                Delete &ldquo;{{ $page->title }}&rdquo; permanently? Every page filed under it,
                                and their attachments, go with it. This cannot be undone.
                            </p>
                            <div class="mt-3 flex items-center gap-2">
                                <x-ui.button type="button" variant="danger" size="sm" wire:click="destroyPage">
                                    Yes, delete it
                                </x-ui.button>
                                <x-ui.button type="button" variant="secondary" size="sm" wire:click="cancelDelete">
                                    Cancel
                                </x-ui.button>
                            </div>
                        @else
                            <x-ui.button type="button" variant="secondary" size="sm" class="text-rose-600"
                                         wire:click="confirmDelete">
                                Delete page
                            </x-ui.button>
                        @endif
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>
</div>
