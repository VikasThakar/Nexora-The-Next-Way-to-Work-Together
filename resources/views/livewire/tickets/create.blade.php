<div>
    <x-ui.page-header
        title="New ticket"
        :description="$isStaff
            ? 'Tickets are internal unless you mark them customer-visible.'
            : 'Your request goes straight to the delivery team and stays visible to you.'"
        :trail="\App\Support\Breadcrumbs::newTicket($board)"
    />

    {{--
        The whole form is a drop target.

        Somebody with a screenshot in hand should not have to find the editor
        with it: a drop anywhere on the form hands the files to the editor,
        which stages them exactly as a paste would. The editor's own surface
        keeps handling its own drops — the check on the target is what stops
        one drop being uploaded twice.
    --}}
    <form
        wire:submit="save"
        class="grid max-w-5xl gap-6 lg:grid-cols-3"
        x-data="{ dragging: false }"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="
            dragging = false;
            const files = Array.from($event.dataTransfer?.files ?? []);
            const editor = $root.querySelector('[data-rich-editor]');
            if (files.length && editor && ! editor.contains($event.target)) {
                Alpine.$data(editor).upload?.(files);
            }
        "
    >
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="What needs doing?">
                <x-slot:actions>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleMarkdownMode">
                        {{ $markdownMode ? 'Rich text' : 'Markdown' }}
                    </x-ui.button>

                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                        {{ $previewing ? 'Write' : 'Preview' }}
                    </x-ui.button>
                </x-slot:actions>

                <div class="space-y-5">
                    <x-ui.field label="Title" for="new-title" :error="$errors->first('title')" required>
                        <x-ui.input id="new-title" wire:model="title" autofocus :invalid="$errors->has('title')" />
                    </x-ui.field>

                    {{-- `for` only in Markdown mode; see the ticket screen for
                         why pointing a label at the rich surface is wrong. --}}
                    <x-ui.field
                        label="Description"
                        :for="$markdownMode ? 'new-description' : null"
                        :error="$errors->first('descriptionMd')"
                        :hint="$markdownMode
                            ? 'Markdown source. Raw HTML is stripped.'
                            : 'Paste or drop screenshots, recordings and files anywhere on this form. They are attached when the ticket is created.'"
                    >
                        @if ($previewing)
                            <div class="markdown min-h-40 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                {{-- Safe unescaped: App\Support\Markdown strips raw HTML
                                     and unsafe link schemes. --}}
                                {!! $previewHtml ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                            </div>
                        @elseif ($markdownMode)
                            <x-ui.textarea id="new-description" rows="12" class="font-mono text-xs"
                                           wire:model="descriptionMd"
                                           :invalid="$errors->has('descriptionMd')">{{ $descriptionMd }}</x-ui.textarea>
                        @else
                            {{--
                                Files dropped, pasted or chosen here are stored
                                straight away as drafts — rows with no owner yet
                                — and adopted by the ticket when this form is
                                saved. An inline image therefore points at
                                route('attachments.show') from the moment it
                                appears, so the description never has to have its
                                URLs rewritten afterwards. See
                                App\Livewire\Concerns\CollectsDraftAttachments.
                            --}}
                            <div wire:key="rich-new-ticket">
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

                    @if ($draftAttachments->isNotEmpty())
                        {{--
                            Images dropped into the editor are already visible in
                            the description above. This list is what makes a PDF
                            or a log file visible at all, and what gives any of
                            them a way back out before the ticket exists.
                        --}}
                        <div class="mt-4 border-t border-slate-200 pt-4">
                            <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                Attached to this ticket
                            </h3>

                            <ul class="mt-2 space-y-1.5" role="list">
                                @foreach ($draftAttachments as $draft)
                                    <li wire:key="draft-{{ $draft->id }}"
                                        class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5">
                                        @if ($draft->isImage())
                                            <img src="{{ route('attachments.show', $draft) }}"
                                                 alt=""
                                                 class="h-8 w-8 shrink-0 rounded object-cover">
                                        @else
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-slate-200 text-[10px] font-semibold text-slate-600">
                                                {{ strtoupper(pathinfo($draft->filename, PATHINFO_EXTENSION) ?: 'FILE') }}
                                            </span>
                                        @endif

                                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ $draft->filename }}</span>
                                        <span class="shrink-0 text-xs text-slate-400">{{ $draft->humanSize() }}</span>

                                        <button type="button"
                                                wire:click="removeDraftAttachment({{ $draft->id }})"
                                                class="shrink-0 rounded px-1.5 py-0.5 text-xs text-slate-500 transition hover:bg-slate-200 hover:text-slate-800"
                                                aria-label="Remove {{ $draft->filename }}">
                                            Remove
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Details">
                <div class="space-y-4">
                    <x-ui.field label="Type" for="new-type" :error="$errors->first('type')" required>
                        <x-ui.select id="new-type" wire:model="type">
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Priority" for="new-priority" :error="$errors->first('priority')" required>
                        <x-ui.select id="new-priority" wire:model="priority">
                            @foreach ($priorityOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($isStaff)
                        <x-ui.field label="Column" for="new-column">
                            <x-ui.select id="new-column" wire:model="columnId">
                                @foreach ($columns as $column)
                                    <option value="{{ $column->id }}">{{ $column->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Assignee" for="new-assignee">
                            <x-ui.select id="new-assignee" wire:model="assigneeId">
                                <option value="">Unassigned</option>
                                @foreach ($assignableMembers as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Estimate" for="new-estimate" :error="$errors->first('estimate')">
                            <x-ui.input id="new-estimate" type="number" step="0.25" min="0" wire:model="estimate"
                                        :invalid="$errors->has('estimate')" />
                        </x-ui.field>
                    @else
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            Your request will start in <strong>{{ $firstColumnName }}</strong>. The team will
                            triage and assign it.
                        </p>
                    @endif

                    <x-ui.field label="Due date" for="new-due" :error="$errors->first('dueDate')">
                        <x-ui.input id="new-due" type="date" wire:model="dueDate" :invalid="$errors->has('dueDate')" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            @if ($isStaff)
                <x-ui.card title="Customer visibility">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="customerVisible"
                               class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span class="text-sm">
                            <span class="font-medium text-slate-900">Visible to the customer</span>
                            <span class="mt-0.5 block text-slate-500">
                                Leave this off for internal work. Customers on this board never receive
                                internal tickets, in the board, in search or through links.
                            </span>
                        </span>
                    </label>
                </x-ui.card>

                @if ($boardLabels->isNotEmpty())
                    <x-ui.card title="Labels">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($boardLabels as $label)
                                <label class="cursor-pointer">
                                    <input type="checkbox" value="{{ $label->id }}" wire:model="selectedLabelIds"
                                           class="peer sr-only">
                                    <span class="inline-flex rounded-full opacity-50 transition peer-checked:opacity-100 peer-checked:ring-2 peer-checked:ring-slate-900 peer-checked:ring-offset-1 peer-checked:ring-offset-surface hover:opacity-100">
                                        <x-ui.label-chip :label="$label" />
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            @endif

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Create ticket</x-ui.button>
                <x-ui.button variant="secondary" :href="route('boards.show', $board)">Cancel</x-ui.button>
            </div>
        </div>
    </form>
</div>
