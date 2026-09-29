@php
    $sortGroup = 'board-'.$board->id;
@endphp

<div>
    <x-ui.page-header
        :title="$board->name"
        :description="$board->description"
        :trail="\App\Support\Breadcrumbs::board($board)"
    >
        <x-slot:actions>
            @unless ($canSeeInternal)
                {{-- Customers see a permanent reminder that this is their view
                     of the board, not the whole board. --}}
                <x-ui.badge variant="amber">Customer view</x-ui.badge>
            @endunless

            @if ($board->isArchived())
                <x-ui.badge variant="amber">Archived</x-ui.badge>
            @endif

            <x-ui.button :href="route('docs.index', $board)" variant="secondary" size="sm">
                Docs
            </x-ui.button>

            {{--
                The "Workspace AI" button used to be here.

                The assistant is now reached from the chat icon in the top bar,
                which opens it as a panel over whatever page you are on and is
                available everywhere rather than only on a board. Opening it
                here pre-selects this board as the context.

                The full-page chat at boards.ai-chat is not gone — it is still
                linked from this board's AI settings screen, and its URL still
                works — but it is no longer the way in.
            --}}

            @if ($canConfigureBoard)
                <x-ui.button :href="route('boards.settings', $board)" variant="secondary" size="sm">
                    Configure
                </x-ui.button>
            @endif

            @if ($canManageBoard)
                <x-ui.button :href="route('boards.edit', $board)" variant="secondary" size="sm">
                    Board settings
                </x-ui.button>
            @endif

            @if ($canCreateTickets)
                <x-ui.button :href="route('tickets.create', $board)" size="sm">New ticket</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter bar --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-surface p-3">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-56 flex-1">
                <x-ui.input
                    type="search"
                    placeholder="Search tickets by title, text or number…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search tickets"
                />
            </div>

            <x-ui.select wire:model.live="assignee" class="w-48" aria-label="Filter by assignee">
                <option value="">Anyone</option>
                <option value="{{ \App\Support\TicketFilters::UNASSIGNED }}">Unassigned</option>
                @foreach ($assignableMembers as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.button type="button" variant="secondary" size="md" wire:click="toggleFilters">
                More filters
                @if ($filters->activeCount() > 0)
                    <span class="ml-1 rounded-full bg-brand-solid px-1.5 text-[10px] text-white">
                        {{ $filters->activeCount() }}
                    </span>
                @endif
            </x-ui.button>

            @unless ($filters->isEmpty())
                <x-ui.button type="button" variant="ghost" size="md" wire:click="clearFilters">Clear</x-ui.button>
            @endunless

            <span class="ml-auto text-xs text-slate-500">
                {{ $totalVisible }} {{ \Illuminate\Support\Str::plural('ticket', $totalVisible) }}
            </span>
        </div>

        @if ($showFilters)
            <div class="mt-4 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3">
                <div>
                    <p class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Priority</p>
                    <div class="space-y-1">
                        @foreach ($priorityOptions as $priority)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" value="{{ $priority->value }}" wire:model.live="priorities"
                                       class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span class="size-2 rounded-full {{ $priority->dotClass() }}"></span>
                                {{ $priority->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Labels</p>
                    @if ($boardLabels->isEmpty())
                        <p class="text-sm text-slate-400">No labels on this board yet.</p>
                    @else
                        <div class="max-h-40 space-y-1 overflow-y-auto pr-1">
                            @foreach ($boardLabels as $label)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" value="{{ $label->id }}" wire:model.live="labelIds"
                                           class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <x-ui.label-chip :label="$label" size="sm" />
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($canSeeInternal)
                    {{-- Only meaningful to staff: a customer's board already
                         contains nothing but customer-visible tickets. --}}
                    <div>
                        <p class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Visibility</p>
                        <div class="space-y-1">
                            @foreach ([
                                \App\Support\TicketFilters::VISIBILITY_ALL => 'Everything',
                                \App\Support\TicketFilters::VISIBILITY_CUSTOMER => 'Customer-visible only',
                                \App\Support\TicketFilters::VISIBILITY_INTERNAL => 'Internal only',
                            ] as $value => $caption)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="radio" value="{{ $value }}" wire:model.live="visibility"
                                           class="size-4 border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ $caption }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    @if ($columns->isEmpty())
        <x-ui.empty-state
            title="This board has no columns"
            description="A board needs at least one column before tickets can be created."
        >
            @if ($canConfigureBoard)
                <x-slot:actions>
                    <x-ui.button :href="route('boards.settings', $board)">Configure columns</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.empty-state>
    @else
        {{-- The board scrolls horizontally; each column scrolls vertically. --}}
        <div class="flex gap-4 overflow-x-auto pb-4">
            @foreach ($columns as $column)
                @php
                    $columnTickets = $ticketsByColumn->get($column->id, collect());
                @endphp

                <section
                    wire:key="column-{{ $column->id }}"
                    class="flex w-80 shrink-0 flex-col rounded-xl bg-surface-sunken"
                >
                    <header class="flex items-center gap-2 px-3 py-2.5">
                        <h2 class="text-sm font-semibold text-slate-700">{{ $column->name }}</h2>
                        <span class="rounded-full bg-surface/80 px-2 py-px text-xs font-medium text-slate-500">
                            {{ $columnTickets->count() }}
                        </span>

                        @if ($column->is_done)
                            <svg class="size-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                 stroke="currentColor" title="Done column">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        @endif

                        @if ($canCreateTickets)
                            <button type="button"
                                    wire:click="startQuickAdd({{ $column->id }})"
                                    class="ml-auto rounded p-1 text-slate-500 transition hover:bg-surface/70 hover:text-slate-900"
                                    aria-label="Add a ticket to {{ $column->name }}">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        @endif
                    </header>

                    {{--
                        The drop zone, which is also the column's scroll box.

                        x-sort is the Alpine Sort plugin bundled inside
                        Livewire. Every column shares one group name so a card
                        can be dragged between them, and the handler carries
                        this column's id, so the server is always told where the
                        card landed — a drop is the whole status change, because
                        on this board a ticket's status *is* the column it sits
                        in.

                        x-sort and not wire:sort, which is Livewire's wrapper
                        around this same plugin. wire:sort hands the expression
                        to Livewire's own evaluator first, and that rewrites
                        every identifier it does not recognise into a property
                        of $wire — including the plugin's own $item and
                        $position, which become $wire.$item and $wire.$position.
                        Those resolve to Livewire's "call a method by this name"
                        fallback, so the drop arrives at the server as
                        moveTicket(null, null, 5) and fails on the signature.
                        Alpine evaluates this attribute itself, where $item and
                        $position mean what the plugin put in scope. The same
                        applies to the other three sortables in this codebase;
                        BoardDragAndDropTest pins the choice.

                        Only ticket elements may live inside this container:
                        the index the browser reports is the child index, so an
                        empty-state node in here would shift every position by
                        one. The empty hint and the quick-add form are siblings
                        below it.

                        The Tailwind max-height is a stand-in for the first
                        paint and for a browser that never runs the script; the
                        real cap is measured from seven actual cards by the
                        boardColumn component, because cards are not all the
                        same height. overscroll-contain keeps a flick at the
                        end of a column from scrolling the page behind it.
                    --}}
                    <div
                        @if ($canReorder)
                            x-sort="$wire.moveTicket($item, $position, {{ $column->id }})"
                            x-sort:group="{{ $sortGroup }}"

                            {{--
                                SortableJS options, on top of the plugin's own.

                                forceFallback drops the browser's native HTML5
                                drag in favour of SortableJS's own mirror. Three
                                reasons, all of them load-bearing here:
                                  - a card is an <a>, and a native drag of a
                                    link is the browser's link-drag, not ours;
                                  - the native drag image cannot be styled, and
                                    fallbackOnBody lifts ours out of this
                                    element so the column's own overflow does
                                    not clip the card being dragged;
                                  - one code path for mouse and touch instead of
                                    two, so what is tested on a laptop is what
                                    ships to a tablet.

                                delay with delayOnTouchOnly is what makes a
                                touch screen work: a finger that presses and
                                swipes scrolls the column, and a finger that
                                presses, waits and then moves drags the card.
                                Without it every attempt to scroll a column
                                would pick a ticket up instead. A mouse gets no
                                delay at all — delayOnTouchOnly — so dragging
                                with a pointer stays immediate.

                                supportPointer is off deliberately. With pointer
                                events SortableJS suppresses the browser's own
                                scrolling by calling preventDefault on
                                pointermove, which browsers are free to ignore;
                                on touchmove they are not. Turning it off is
                                what stops a drag on Android from scrolling the
                                column at the same time.
                            --}}
                            x-sort:config="{
                                forceFallback: true,
                                fallbackOnBody: true,
                                fallbackTolerance: 6,
                                supportPointer: false,
                                delay: 180,
                                delayOnTouchOnly: true,
                                touchStartThreshold: 6,
                                emptyInsertThreshold: 16,
                                scrollSensitivity: 64,
                                scrollSpeed: 18,
                            }"
                        @endif
                        x-data="boardColumn"
                        {{-- A region that scrolls has to be reachable from the keyboard. --}}
                        tabindex="0"
                        aria-label="{{ $column->name }} tickets"
                        class="column-scroll max-h-[41rem] min-h-24 space-y-2 overflow-y-auto overscroll-contain px-2 pb-2 focus-visible:ring-2 focus-visible:ring-brand-500/30 focus-visible:outline-none"
                        data-column-id="{{ $column->id }}"
                    >
                        @foreach ($columnTickets as $ticket)
                            <div wire:key="ticket-{{ $ticket->id }}" @if ($canReorder) x-sort:item="{{ $ticket->id }}" @endif>
                                <x-ticket.card
                                    :ticket="$ticket"
                                    :board="$board"
                                    :can-see-internal="$canSeeInternal"
                                    :draggable="$canReorder"
                                />
                            </div>
                        @endforeach
                    </div>

                    @if ($columnTickets->isEmpty() && $quickAddColumnId !== $column->id)
                        <p class="px-4 pb-3 text-center text-xs text-slate-500">
                            {{ $filters->isEmpty() ? 'Nothing here yet' : 'Nothing matches the filters' }}
                        </p>
                    @endif

                    @if ($canCreateTickets)
                        <div class="px-2 pb-2">
                            @if ($quickAddColumnId === $column->id)
                                {{--
                                    The card is a drop target and a paste
                                    target. A screenshot dropped on it, or
                                    pasted into the title, becomes a file on
                                    the ticket about to be created — see
                                    quickAddFiles on the component and the
                                    fileDrop component in
                                    resources/js/file-drop.js.
                                --}}
                                <form
                                    wire:submit="quickAdd"
                                    x-data="fileDrop"
                                    x-on:dragenter.prevent="dragEnter($event)"
                                    x-on:dragover.prevent
                                    x-on:dragleave.prevent="dragLeave($event)"
                                    x-on:drop.prevent="drop($event)"
                                    class="rounded-lg border bg-surface p-2 shadow-sm transition"
                                    x-bind:class="dragging ? 'border-brand-500 bg-brand-50/60 ring-2 ring-brand-500/20' : 'border-brand-300'"
                                >
                                    {{--
                                        Enter creates the ticket; Shift-Enter is
                                        left alone so a title can wrap. This is
                                        the fast path and it still needs nothing
                                        but a title, however many fields are
                                        revealed below.
                                    --}}
                                    <textarea
                                        wire:model="quickAddTitle"
                                        rows="2"
                                        autofocus
                                        placeholder="What needs doing? Paste a screenshot to attach it."
                                        aria-label="Ticket title"
                                        class="w-full resize-none border-0 p-1 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 focus:outline-none"
                                        @keydown.enter.prevent="$wire.quickAdd()"
                                        @keydown.escape="$wire.cancelQuickAdd()"
                                        x-on:paste="paste($event)"
                                    ></textarea>

                                    @error('quickAddTitle')
                                        <p class="px-1 pb-1 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror

                                    <input
                                        type="file"
                                        multiple
                                        x-ref="picker"
                                        wire:model="quickAddUploads"
                                        class="sr-only"
                                        tabindex="-1"
                                        aria-label="Attach files to the new ticket"
                                    >

                                    <div x-show="uploading" x-cloak class="px-1 pb-1">
                                        <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200">
                                            <div class="h-full rounded-full bg-brand-500 transition-all" x-bind:style="`width: ${progress}%`"></div>
                                        </div>
                                    </div>

                                    @error('quickAddUploads.*')
                                        <p class="px-1 pb-1 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror
                                    @error('quickAddUploads')
                                        <p class="px-1 pb-1 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror

                                    @if ($quickAddFilePreviews !== [])
                                        {{-- Pictures as pictures, even here. --}}
                                        <ul class="flex flex-wrap gap-1.5 px-1 pb-2" aria-label="Files to attach">
                                            @foreach ($quickAddFilePreviews as $index => $file)
                                                <li wire:key="qa-file-{{ $column->id }}-{{ $index }}-{{ md5($file['name']) }}"
                                                    class="group relative overflow-hidden rounded-md border border-slate-200 bg-surface">
                                                    @if ($file['image'] && $file['url'])
                                                        <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" class="block h-16 w-20 object-cover" loading="lazy">
                                                    @else
                                                        <span class="flex h-16 w-28 items-center px-2 text-[11px] leading-tight text-slate-600" title="{{ $file['name'] }}">
                                                            <span class="line-clamp-3 break-all">{{ $file['name'] }}</span>
                                                        </span>
                                                    @endif

                                                    <button type="button"
                                                            wire:click="removeQuickAddFile({{ $index }})"
                                                            class="absolute top-0.5 right-0.5 rounded-full bg-slate-900/70 p-0.5 text-white opacity-0 transition group-hover:opacity-100 focus:opacity-100"
                                                            aria-label="Remove {{ $file['name'] }}">
                                                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if ($canQuickAddDetails)
                                        <div
                                            @class([
                                                'space-y-2 border-t border-slate-100 pt-2',
                                                'hidden' => ! $quickAddExpanded,
                                            ])
                                            id="quick-add-details-{{ $column->id }}"
                                        >
                                            <div class="grid grid-cols-2 gap-1.5">
                                                <label class="sr-only" for="qa-type-{{ $column->id }}">Type</label>
                                                <select id="qa-type-{{ $column->id }}" wire:model="quickAddType"
                                                        class="w-full rounded border-slate-200 py-1 text-xs text-slate-700 focus:border-brand-400 focus:ring-brand-500/30">
                                                    @foreach ($typeOptions as $option)
                                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                                    @endforeach
                                                </select>

                                                <label class="sr-only" for="qa-priority-{{ $column->id }}">Priority</label>
                                                <select id="qa-priority-{{ $column->id }}" wire:model="quickAddPriority"
                                                        class="w-full rounded border-slate-200 py-1 text-xs text-slate-700 focus:border-brand-400 focus:ring-brand-500/30">
                                                    @foreach ($priorityOptions as $option)
                                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <label class="sr-only" for="qa-assignee-{{ $column->id }}">Assignee</label>
                                            <select id="qa-assignee-{{ $column->id }}" wire:model="quickAddAssigneeId"
                                                    class="w-full rounded border-slate-200 py-1 text-xs text-slate-700 focus:border-brand-400 focus:ring-brand-500/30">
                                                <option value="">Unassigned</option>
                                                @foreach ($assignableMembers as $member)
                                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                                @endforeach
                                            </select>

                                            <label class="sr-only" for="qa-due-{{ $column->id }}">Due date</label>
                                            <input type="date" id="qa-due-{{ $column->id }}" wire:model="quickAddDueDate"
                                                   class="w-full rounded border-slate-200 py-1 text-xs text-slate-700 focus:border-brand-400 focus:ring-brand-500/30">

                                            @error('quickAddDueDate')
                                                <p class="text-xs text-rose-600">{{ $message }}</p>
                                            @enderror

                                            @if ($boardLabels->isNotEmpty())
                                                <div class="flex flex-wrap gap-1" role="group" aria-label="Labels">
                                                    @foreach ($boardLabels as $label)
                                                        <button
                                                            type="button"
                                                            wire:click="toggleQuickAddLabel({{ $label->id }})"
                                                            aria-pressed="{{ in_array($label->id, $quickAddLabelIds, true) ? 'true' : 'false' }}"
                                                            @class([
                                                                'inline-flex rounded-full transition',
                                                                'opacity-100 ring-2 ring-slate-900 ring-offset-1 ring-offset-surface' => in_array($label->id, $quickAddLabelIds, true),
                                                                'opacity-50 hover:opacity-100' => ! in_array($label->id, $quickAddLabelIds, true),
                                                            ])
                                                        >
                                                            <x-ui.label-chip :label="$label" />
                                                        </button>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="flex items-center gap-2 pt-1">
                                        <x-ui.button type="submit" size="sm">Add</x-ui.button>

                                        <button type="button"
                                                x-on:click="choose()"
                                                class="rounded p-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
                                                title="Attach files"
                                                aria-label="Attach files to the new ticket">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                            </svg>
                                        </button>

                                        @if ($canQuickAddDetails)
                                            <x-ui.button type="button" variant="ghost" size="sm"
                                                         wire:click="toggleQuickAddDetails"
                                                         aria-expanded="{{ $quickAddExpanded ? 'true' : 'false' }}"
                                                         aria-controls="quick-add-details-{{ $column->id }}">
                                                {{ $quickAddExpanded ? 'Fewer' : 'Details' }}
                                            </x-ui.button>
                                        @endif

                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelQuickAdd">
                                            Cancel
                                        </x-ui.button>
                                    </div>
                                </form>
                            @else
                                <button type="button"
                                        wire:click="startQuickAdd({{ $column->id }})"
                                        class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-500 transition hover:bg-surface/70 hover:text-slate-800">
                                    + Add a ticket
                                </button>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
