@php
    $sortGroup = 'docs-'.$board->id;
@endphp

<div>
    <x-ui.page-header :title="$page?->title ?? 'Documentation'">
        <x-slot:breadcrumb>
            <a href="{{ route('boards.show', $board) }}" wire:navigate class="hover:text-slate-700">{{ $board->name }}</a>
            <span class="mx-1">/</span>
            <a href="{{ route('docs.index', $board) }}" wire:navigate class="hover:text-slate-700">Docs</a>

            @foreach ($breadcrumb as $ancestor)
                <span class="mx-1">/</span>
                <a href="{{ route('docs.show', ['board' => $board, 'slug' => $ancestor->slug]) }}"
                   wire:navigate class="hover:text-slate-700">{{ $ancestor->title }}</a>
            @endforeach
        </x-slot:breadcrumb>

        <x-slot:actions>
            @unless ($canSeeInternal)
                <x-ui.badge variant="amber">Customer view</x-ui.badge>
            @endunless

            @if ($page)
                @if ($page->customer_visible)
                    <x-ui.badge variant="emerald">Published to customers</x-ui.badge>
                @elseif ($canSeeInternal)
                    <x-ui.badge variant="amber">Internal only</x-ui.badge>
                @endif
            @endif

            @if ($canCreate)
                <x-ui.button type="button" size="sm" wire:click="startCreating">New page</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('tree')
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-4">
        {{-- ------------------------------------------------------------- --}}
        {{-- Sidebar tree                                                    --}}
        {{-- ------------------------------------------------------------- --}}
        <aside class="lg:col-span-1">
            <div class="rounded-xl border border-slate-200 bg-white p-3">
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
                    />
                @endif
            </div>
        </aside>

        {{-- ------------------------------------------------------------- --}}
        {{-- Page                                                            --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="space-y-6 lg:col-span-3">
            @if ($page === null)
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
                <x-ui.card :title="$editing ? 'Edit page' : $page->title">
                    <x-slot:actions>
                        @if ($editing)
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                                {{ $previewing ? 'Write' : 'Preview' }}
                            </x-ui.button>
                        @elseif ($canEditPage)
                            <x-ui.button type="button" size="sm" wire:click="startEditing">Edit</x-ui.button>
                        @endif
                    </x-slot:actions>

                    @if ($editing)
                        <form wire:submit="save" class="space-y-4">
                            <x-ui.field label="Title" for="page-title" :error="$errors->first('title')" required>
                                <x-ui.input id="page-title" wire:model="title" :invalid="$errors->has('title')" />
                            </x-ui.field>

                            <x-ui.field
                                label="Content"
                                for="page-body"
                                :error="$errors->first('bodyMd')"
                                hint="Markdown: headings, lists, tables, code blocks, images and links. Raw HTML is stripped. Ticket keys such as {{ $board->ticket_prefix }}-1 link themselves."
                            >
                                @if ($previewing)
                                    <div class="markdown min-h-64 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                        {{-- Safe unescaped: App\Support\Markdown strips raw HTML and
                                             unsafe schemes, and the post-processors escape what they add. --}}
                                        {!! $bodyHtml ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                                    </div>
                                @else
                                    <x-ui.textarea id="page-body" rows="24" class="font-mono text-xs"
                                                   wire:model="bodyMd" :invalid="$errors->has('bodyMd')">{{ $bodyMd }}</x-ui.textarea>
                                @endif
                            </x-ui.field>

                            <div class="flex items-center gap-2">
                                <x-ui.button type="submit">Save page</x-ui.button>
                                <x-ui.button type="button" variant="secondary" wire:click="cancelEditing">Cancel</x-ui.button>
                            </div>
                        </form>
                    @else
                        @if (trim((string) $page->body_md) === '')
                            <p class="text-sm text-slate-400">This page is empty.</p>
                        @else
                            <div class="markdown">{!! $bodyHtml !!}</div>
                        @endif

                        <p class="mt-6 border-t border-slate-100 pt-3 text-xs text-slate-400">
                            Last updated {{ $page->updated_at->diffForHumans() }}
                            @if ($page->editor)
                                by {{ $page->editor->name }}
                            @endif
                        </p>
                    @endif
                </x-ui.card>

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

                            @if ($canCreate)
                                <x-ui.button type="button" variant="secondary" size="sm"
                                             wire:click="startCreating({{ $page->id }})">
                                    Add a child page
                                </x-ui.button>
                            @endif
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
