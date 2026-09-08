<div>
    <x-ui.page-header
        title="Board configuration"
        description="Columns and labels for this board. Changes apply to everyone who works on it."
        :trail="\App\Support\Breadcrumbs::boardChild($board, 'Board configuration')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('boards.show', $board)" variant="secondary">Back to board</x-ui.button>

            @if ($canManageBoard)
                <x-ui.button :href="route('boards.edit', $board)" variant="secondary">Board details</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ------------------------------------------------------------- --}}
        {{-- Columns                                                         --}}
        {{-- ------------------------------------------------------------- --}}
        <x-ui.card title="Columns" description="Drag to reorder. Tickets are never deleted with a column.">
            <div
                wire:sort="$wire.reorderColumn($item, $position)"
                class="space-y-2"
            >
                @foreach ($columns as $column)
                    <div
                        wire:key="settings-column-{{ $column->id }}"
                        wire:sort:item="{{ $column->id }}"
                        class="rounded-lg border border-slate-200 bg-surface p-3"
                    >
                        @if ($editingColumnId === $column->id)
                            <form wire:submit="saveColumn" class="flex items-start gap-2">
                                <div class="flex-1">
                                    <x-ui.input
                                        wire:model="editingColumnName"
                                        autofocus
                                        :invalid="$errors->has('editingColumnName')"
                                        @keydown.escape="$wire.cancelEditingColumn()"
                                    />
                                    @error('editingColumnName')
                                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEditingColumn">
                                    Cancel
                                </x-ui.button>
                            </form>
                        @else
                            <div class="flex items-center gap-3">
                                <span wire:sort:handle class="cursor-grab text-slate-300 active:cursor-grabbing"
                                      aria-hidden="true" title="Drag to reorder">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                                    </svg>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $column->name }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $column->tickets_count }} {{ \Illuminate\Support\Str::plural('ticket', $column->tickets_count) }}
                                    </p>
                                </div>

                                <button type="button"
                                        wire:click="toggleDoneColumn({{ $column->id }})"
                                        wire:sort:ignore
                                        class="rounded px-2 py-1 text-xs font-medium transition {{ $column->is_done ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 ring-inset' : 'text-slate-400 hover:bg-slate-100' }}"
                                        title="Mark the column that means work is finished. Used by future flow metrics.">
                                    Done column
                                </button>

                                <x-ui.button type="button" variant="ghost" size="sm" wire:sort:ignore
                                             wire:click="startEditingColumn({{ $column->id }})">
                                    Rename
                                </x-ui.button>

                                <x-ui.button type="button" variant="ghost" size="sm" wire:sort:ignore
                                             class="text-rose-600 hover:bg-rose-50"
                                             wire:click="startDeletingColumn({{ $column->id }})">
                                    Delete
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Delete flow: a column holding tickets cannot go until the user
                 says where those tickets should end up. --}}
            @if ($deletingColumn)
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <p class="text-sm font-medium text-rose-900">
                        Delete the “{{ $deletingColumn->name }}” column?
                    </p>

                    @if ($deletingColumnHasTickets)
                        <p class="mt-1 text-sm text-rose-800">
                            It holds {{ $deletingColumn->tickets_count }}
                            {{ \Illuminate\Support\Str::plural('ticket', $deletingColumn->tickets_count) }}.
                            Choose where they should go — they will be moved, not deleted.
                        </p>

                        <div class="mt-3 max-w-xs">
                            <x-ui.select wire:model="destinationColumnId" aria-label="Move tickets to">
                                @foreach ($columns->where('id', '!=', $deletingColumn->id) as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-rose-800">The column is empty, so nothing will be moved.</p>
                    @endif

                    <div class="mt-4 flex items-center gap-2">
                        <x-ui.button type="button" variant="danger" size="sm" wire:click="confirmDeleteColumn">
                            {{ $deletingColumnHasTickets ? 'Move tickets and delete' : 'Delete column' }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="secondary" size="sm" wire:click="cancelDeletingColumn">
                            Cancel
                        </x-ui.button>
                    </div>
                </div>
            @endif

            <form wire:submit="addColumn" class="mt-4 flex items-start gap-2 border-t border-slate-100 pt-4">
                <div class="flex-1">
                    <x-ui.input
                        wire:model="newColumnName"
                        placeholder="New column name"
                        :invalid="$errors->has('newColumnName')"
                        aria-label="New column name"
                    />
                    @error('newColumnName')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
                <x-ui.button type="submit">Add column</x-ui.button>
            </form>
        </x-ui.card>

        {{-- ------------------------------------------------------------- --}}
        {{-- Labels                                                          --}}
        {{-- ------------------------------------------------------------- --}}
        <x-ui.card title="Labels" description="Labels belong to this board only.">
            @if ($labels->isEmpty())
                <p class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
                    No labels yet.
                </p>
            @else
                <div class="space-y-2">
                    @foreach ($labels as $label)
                        <div wire:key="settings-label-{{ $label->id }}"
                             class="rounded-lg border border-slate-200 bg-surface p-3">
                            @if ($editingLabelId === $label->id)
                                <form wire:submit="saveLabel" class="space-y-3">
                                    <div>
                                        <x-ui.input wire:model="editingLabelName" autofocus
                                                    :invalid="$errors->has('editingLabelName')" />
                                        @error('editingLabelName')
                                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($labelColors as $color)
                                            <label class="cursor-pointer">
                                                <input type="radio" value="{{ $color->value }}"
                                                       wire:model="editingLabelColor" class="peer sr-only">
                                                <span class="block size-6 rounded-full ring-offset-2 peer-checked:ring-2 peer-checked:ring-slate-900 {{ $color->swatchClass() }}"
                                                      title="{{ $color->label() }}"></span>
                                            </label>
                                        @endforeach
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEditingLabel">
                                            Cancel
                                        </x-ui.button>
                                    </div>
                                </form>
                            @else
                                <div class="flex items-center gap-3">
                                    <x-ui.label-chip :label="$label" />

                                    <span class="text-xs text-slate-500">
                                        {{ $label->tickets_count }} {{ \Illuminate\Support\Str::plural('ticket', $label->tickets_count) }}
                                    </span>

                                    <div class="ml-auto flex items-center gap-1">
                                        <x-ui.button type="button" variant="ghost" size="sm"
                                                     wire:click="startEditingLabel({{ $label->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="ghost" size="sm"
                                                     class="text-rose-600 hover:bg-rose-50"
                                                     wire:click="deleteLabel({{ $label->id }})"
                                                     :confirm="[
                                                         'title' => 'Delete the “'.$label->name.'” label?',
                                                         'body' => 'It is removed from every ticket that uses it. The tickets themselves are not affected.',
                                                         'confirmText' => 'Delete label',
                                                     ]">
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <form wire:submit="addLabel" class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                <div>
                    <x-ui.input wire:model="newLabelName" placeholder="New label name"
                                :invalid="$errors->has('newLabelName')" aria-label="New label name" />
                    @error('newLabelName')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($labelColors as $color)
                        <label class="cursor-pointer">
                            <input type="radio" value="{{ $color->value }}" wire:model="newLabelColor" class="peer sr-only">
                            <span class="block size-6 rounded-full ring-offset-2 peer-checked:ring-2 peer-checked:ring-slate-900 {{ $color->swatchClass() }}"
                                  title="{{ $color->label() }}"></span>
                        </label>
                    @endforeach

                    <x-ui.button type="submit" size="sm" class="ml-auto">Add label</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
