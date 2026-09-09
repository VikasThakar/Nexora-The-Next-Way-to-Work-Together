<div>
    <x-ui.page-header :title="$ticket->title" :trail="\App\Support\Breadcrumbs::ticket($ticket)">
        <x-slot:actions>
            @if ($ticket->customer_visible)
                <x-ui.badge variant="emerald">Customer-visible</x-ui.badge>
            @elseif ($canSeeInternal)
                <x-ui.internal-badge label="Internal only" />
            @endif

            <x-ui.button :href="route('boards.show', $board)" variant="secondary" size="sm">Back to board</x-ui.button>

            @if ($canEdit && ! $editing)
                <x-ui.button type="button" size="sm" wire:click="startEditing">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ------------------------------------------------------------- --}}
        {{-- Main panel                                                      --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card :title="$editing ? 'Edit ticket' : 'Description'">
                @if ($editing)
                    <x-slot:actions>
                        {{-- Markdown source, for anybody who wants their
                             formatting left exactly as they typed it: the rich
                             editor normalises bullet characters and table
                             padding. See App\Support\RichText\RichText. --}}
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleMarkdownMode">
                            {{ $markdownMode ? 'Rich text' : 'Markdown' }}
                        </x-ui.button>

                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                            {{ $previewing ? 'Write' : 'Preview' }}
                        </x-ui.button>
                    </x-slot:actions>

                    <form wire:submit="save" class="space-y-4">
                        <x-ui.field label="Title" for="ticket-title" :error="$errors->first('title')" required>
                            <x-ui.input id="ticket-title" wire:model="title" :invalid="$errors->has('title')" />
                        </x-ui.field>

                        {{--
                            `for` only in Markdown mode.

                            A <label for> has to name an element that exists.
                            The rich editor's writing surface is created by
                            ProseMirror at runtime and carries its own
                            aria-label, so pointing a label at the id of a
                            textarea that is not rendered would be worse than
                            no association at all.
                        --}}
                        <x-ui.field
                            label="Description"
                            :for="$markdownMode ? 'ticket-description' : null"
                            :error="$errors->first('descriptionMd')"
                            :hint="$markdownMode
                                ? 'Markdown source. Raw HTML is stripped.'
                                : 'Paste a screenshot to attach it. Markdown shortcuts work as you type.'"
                        >
                            @if ($previewing)
                                <div class="markdown min-h-40 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    {{-- Safe to render unescaped: App\Support\Markdown strips raw
                                         HTML and unsafe link schemes before this point. --}}
                                    {!! $renderedDescription ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                                </div>
                            @elseif ($markdownMode)
                                <x-ui.textarea
                                    id="ticket-description"
                                    rows="12"
                                    class="font-mono text-xs"
                                    wire:model="descriptionMd"
                                    :invalid="$errors->has('descriptionMd')"
                                >{{ $descriptionMd }}</x-ui.textarea>
                            @else
                                {{--
                                    wire:key differs between the two branches on
                                    purpose. The editor lives inside wire:ignore,
                                    so Livewire's morph must be made to replace
                                    the element rather than patch around it —
                                    otherwise switching back from Markdown would
                                    leave the old, stale editor in place.
                                --}}
                                <div wire:key="rich-{{ $ticket->id }}">
                                    <x-ui.rich-editor
                                        property="descriptionHtml"
                                        :html="$editorHtml"
                                        :uploader="$canAttachInEditor ? 'pendingUploads' : null"
                                        :invalid="$errors->has('descriptionMd')"
                                    />
                                </div>

                                @error('pendingUploads.*')
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror
                            @endif
                        </x-ui.field>

                        <div class="flex items-center gap-2">
                            <x-ui.button type="submit">Save changes</x-ui.button>
                            <x-ui.button type="button" variant="secondary" wire:click="cancelEditing">Cancel</x-ui.button>
                        </div>
                    </form>
                @else
                    @if (trim((string) $ticket->description_md) === '')
                        <p class="text-sm text-slate-400">No description yet.</p>
                    @else
                        {{--
                            The rendered description, with its checklist made
                            live.

                            CommonMark renders a task list as a *disabled*
                            checkbox, which is correct as a default — the reader
                            may not be allowed to change it. So the boxes stay
                            exactly as rendered for a reader without the right,
                            and for everybody else a real control is overlaid
                            per item below.
                        --}}
                        <div class="markdown @if ($canTickTasks) markdown-interactive @endif">{!! $renderedDescription !!}</div>

                        @if ($canTickTasks && $descriptionTasks !== [])
                            @php
                                $done = collect($descriptionTasks)->where('checked', true)->count();
                                $total = count($descriptionTasks);
                            @endphp

                            {{--
                                The live checklist.

                                Rendered as its own list rather than by rewiring
                                the checkboxes inside the prose above, because
                                those are produced by the Markdown renderer and
                                cannot carry a wire:click without this template
                                parsing and rewriting rendered HTML — which is
                                exactly the kind of string surgery that turns
                                into an injection bug.
                            --}}
                            <div class="mt-4 border-t border-slate-100 pt-3">
                                <p class="mb-2 text-xs font-medium text-slate-500">
                                    Checklist in this description &middot; {{ $done }} of {{ $total }} complete
                                </p>

                                <div class="space-y-0.5">
                                    @foreach ($descriptionTasks as $task)
                                        <label
                                            wire:key="desc-task-{{ $task['index'] }}"
                                            class="flex cursor-pointer items-baseline gap-2 rounded px-1 py-1 text-sm transition hover:bg-slate-50"
                                        >
                                            <input
                                                type="checkbox"
                                                @checked($task['checked'])
                                                wire:click="toggleDescriptionTask({{ $task['index'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="toggleDescriptionTask"
                                                class="mt-0.5 size-4 shrink-0 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                            >
                                            <span @class([
                                                'min-w-0 flex-1',
                                                'text-slate-400 line-through' => $task['checked'],
                                                'text-slate-800' => ! $task['checked'],
                                            ])>{{ $task['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                @endif
            </x-ui.card>

            <livewire:tickets.components.comments :ticket="$ticket" :key="'comments-'.$ticket->id" />

            {{--
                AI automation. Staff only.

                The guard here is a usability decision, not the security one: the
                component authorizes `viewAny` on mount and on every render, and
                AiRun::visibleTo() returns nothing for a customer regardless. This
                simply avoids mounting a component that would 404.
            --}}
            @if ($canSeeInternal)
                <livewire:tickets.components.ai-runs :ticket="$ticket" :key="'ai-runs-'.$ticket->id" />
            @endif

            {{--
                There is no GitHub panel here any more.

                Branches, commits and pull requests are events on the Activity
                card at the foot of this column, interleaved with what people
                did — which is what the client asked for and what makes the
                order of a day's work readable. Nothing was dropped: the same
                `github_links` rows still back them, and each event links out to
                the object on GitHub.
            --}}

            <livewire:tickets.components.subtasks :ticket="$ticket" :key="'subtasks-'.$ticket->id" />

            <livewire:tickets.components.attachments :ticket="$ticket" :key="'attachments-'.$ticket->id" />

            <livewire:tickets.components.links :ticket="$ticket" :key="'links-'.$ticket->id" />

            <livewire:tickets.components.activity :ticket="$ticket" :key="'activity-'.$ticket->id" />
        </div>

        {{-- ------------------------------------------------------------- --}}
        {{-- Sidebar                                                         --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="space-y-6">
            <x-ui.card title="Details">
                <div class="space-y-4">
                    <x-ui.field label="Status" for="ticket-column">
                        <x-ui.select id="ticket-column" wire:model.live="columnId" :disabled="! $canMove">
                            @foreach ($columns as $column)
                                <option value="{{ $column->id }}">{{ $column->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Type" for="ticket-type" :error="$errors->first('type')">
                        <x-ui.select id="ticket-type" wire:model.live="type" :disabled="! $canEdit">
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Priority" for="ticket-priority" :error="$errors->first('priority')">
                        <x-ui.select id="ticket-priority" wire:model.live="priority" :disabled="! $canEdit">
                            @foreach ($priorityOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field
                        label="Assignee"
                        for="ticket-assignee"
                        :hint="$canAssign ? null : 'Assignment is handled by the delivery team.'"
                    >
                        @if ($canAssign)
                            <x-ui.select id="ticket-assignee" wire:model.live="assigneeId">
                                <option value="">Unassigned</option>
                                @foreach ($assignableMembers as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </x-ui.select>
                        @else
                            <div class="flex items-center gap-2 py-1">
                                @if ($ticket->assignee)
                                    <x-ui.avatar :name="$ticket->assignee->name" size="sm" />
                                    <span class="text-sm text-slate-700">{{ $ticket->assignee->name }}</span>
                                @else
                                    <span class="text-sm text-slate-400">Unassigned</span>
                                @endif
                            </div>
                        @endif
                    </x-ui.field>

                    <x-ui.field label="Due date" for="ticket-due" :error="$errors->first('dueDate')">
                        <x-ui.input id="ticket-due" type="date" wire:model.live.debounce.500ms="dueDate"
                                    :disabled="! $canEdit" :invalid="$errors->has('dueDate')" />
                    </x-ui.field>

                    @if ($canAssign)
                        <x-ui.field label="Estimate" for="ticket-estimate" :error="$errors->first('estimate')"
                                    hint="Points or hours, whichever the team uses.">
                            <x-ui.input id="ticket-estimate" type="number" step="0.25" min="0"
                                        wire:model.live.debounce.500ms="estimate"
                                        :invalid="$errors->has('estimate')" />
                        </x-ui.field>
                    @endif
                </div>
            </x-ui.card>

            @if ($canChangeVisibility)
                <x-ui.card title="Customer visibility">
                    <p class="text-sm text-slate-600">
                        @if ($ticket->customer_visible)
                            Customers on this board can see this ticket.
                        @else
                            This ticket is internal. Customers on this board never receive it, in the board,
                            in search or through links.
                        @endif
                    </p>

                    <div class="mt-3">
                        <x-ui.button
                            type="button"
                            :variant="$ticket->customer_visible ? 'secondary' : 'primary'"
                            size="sm"
                            wire:click="toggleVisibility"
                            {{--
                                The two directions are not equally weighty, and
                                the dialog says so. Hiding a ticket is a safe,
                                reversible tightening; showing one to a customer
                                cannot be un-seen, so it gets the amber warning
                                treatment even though nothing is destroyed.
                            --}}
                            :confirm="$ticket->customer_visible
                                ? [
                                    'title' => 'Make this ticket internal?',
                                    'body' => 'The customer stops seeing it and any comments in the customer conversation go with it. You can make it visible again at any time.',
                                    'confirmText' => 'Make internal',
                                    'tone' => 'brand',
                                ]
                                : [
                                    'title' => 'Show this ticket to the customer?',
                                    'body' => 'Everyone on this board with customer access will be able to read its title, description and the customer conversation. Internal notes stay hidden. This cannot be un-seen.',
                                    'confirmText' => 'Make customer-visible',
                                    'tone' => 'warning',
                                ]"
                        >
                            {{ $ticket->customer_visible ? 'Make internal' : 'Make customer-visible' }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Labels">
                @if ($boardLabels->isEmpty())
                    <p class="text-sm text-slate-400">This board has no labels yet.</p>
                @elseif ($canManageLabels)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($boardLabels as $label)
                            <button
                                type="button"
                                wire:key="label-toggle-{{ $label->id }}"
                                wire:click="toggleLabel({{ $label->id }})"
                                @class([
                                    'inline-flex rounded-full transition',
                                    'opacity-100 ring-2 ring-slate-900 ring-offset-1 ring-offset-surface' => in_array($label->id, $selectedLabelIds, true),
                                    'opacity-50 hover:opacity-100' => ! in_array($label->id, $selectedLabelIds, true),
                                ])
                            >
                                <x-ui.label-chip :label="$label" />
                            </button>
                        @endforeach
                    </div>
                @elseif ($ticket->labels->isEmpty())
                    <p class="text-sm text-slate-400">No labels.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($ticket->labels as $label)
                            <x-ui.label-chip :label="$label" />
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="About">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Key</dt>
                        <dd class="font-mono text-slate-900">{{ $ticket->key() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Raised by</dt>
                        <dd class="truncate text-slate-900">{{ $ticket->creator?->name ?? 'Unknown' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Created</dt>
                        <dd class="text-slate-900">{{ $ticket->created_at->toFormattedDateString() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Updated</dt>
                        <dd class="text-slate-900">{{ $ticket->updated_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($canDelete)
                <x-ui.card title="Danger zone">
                    @if ($confirmingDelete)
                        <p class="text-sm text-slate-700">
                            Delete {{ $ticket->key() }} permanently? Its history, subtasks, links and
                            attachments go with it. This cannot be undone.
                        </p>
                        <div class="mt-3 flex items-center gap-2">
                            <x-ui.button type="button" variant="danger" size="sm" wire:click="destroyTicket">
                                Yes, delete it
                            </x-ui.button>
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="cancelDelete">
                                Cancel
                            </x-ui.button>
                        </div>
                    @else
                        <x-ui.button type="button" variant="secondary" size="sm"
                                     class="text-rose-600" wire:click="confirmDelete">
                            Delete ticket
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
