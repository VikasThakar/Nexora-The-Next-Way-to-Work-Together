<div>
    <x-ui.page-header
        title="New ticket"
        :description="$isStaff
            ? 'Tickets are internal unless you mark them customer-visible.'
            : 'Your request goes straight to the delivery team and stays visible to you.'"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('boards.show', $board) }}" wire:navigate class="hover:text-slate-700">{{ $board->name }}</a>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form wire:submit="save" class="grid max-w-5xl gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="What needs doing?">
                <x-slot:actions>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="togglePreview">
                        {{ $previewing ? 'Write' : 'Preview' }}
                    </x-ui.button>
                </x-slot:actions>

                <div class="space-y-5">
                    <x-ui.field label="Title" for="new-title" :error="$errors->first('title')" required>
                        <x-ui.input id="new-title" wire:model="title" autofocus :invalid="$errors->has('title')" />
                    </x-ui.field>

                    <x-ui.field
                        label="Description"
                        for="new-description"
                        :error="$errors->first('descriptionMd')"
                        hint="Markdown is supported. Raw HTML is stripped."
                    >
                        @if ($previewing)
                            <div class="markdown min-h-40 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                {{-- Safe unescaped: App\Support\Markdown strips raw HTML
                                     and unsafe link schemes. --}}
                                {!! $previewHtml ?: '<p class="text-slate-400">Nothing to preview.</p>' !!}
                            </div>
                        @else
                            <x-ui.textarea id="new-description" rows="12" class="font-mono text-xs"
                                           wire:model="descriptionMd"
                                           :invalid="$errors->has('descriptionMd')">{{ $descriptionMd }}</x-ui.textarea>
                        @endif
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Details">
                <div class="space-y-4">
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
                                    <span class="block rounded-full opacity-50 transition peer-checked:opacity-100 peer-checked:ring-2 peer-checked:ring-slate-900 peer-checked:ring-offset-1 hover:opacity-100">
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
