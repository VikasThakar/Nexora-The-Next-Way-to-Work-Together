@php
    $total = $subtasks->count();
    $percent = $total > 0 ? (int) round(($completedCount / $total) * 100) : 0;
@endphp

<x-ui.card
    title="Checklist"
    :description="$total > 0 ? $completedCount.' of '.$total.' complete' : 'Break this ticket down into steps.'"
>
    @if ($canConvert)
        {{--
            The transition off this panel.

            Checklists now also live inside the description, which is what the
            client asked for. Both work, and nothing converts on its own: this
            is the one deliberate, per-ticket step, and the confirmation says
            plainly what it keeps and what it does not.
        --}}
        <x-slot:actions>
            <x-ui.button
                type="button"
                variant="ghost"
                size="sm"
                wire:click="convertToChecklist"
                :confirm="[
                    'title' => 'Move this checklist into the description?',
                    'body' => 'The '.$total.' '.\Illuminate\Support\Str::plural('item', $total).' will be appended to the '
                        .'description as a checklist you can tick there, and this panel will empty. Which items are '
                        .'complete is kept; who completed them and when is recorded in the ticket history rather than '
                        .'shown on the item. This cannot be undone from here.',
                    'confirmText' => 'Move into description',
                    'tone' => 'warning',
                ]"
            >
                Move into description
            </x-ui.button>
        </x-slot:actions>
    @endif

    @if ($total > 0)
        <div class="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $percent }}%"></div>
        </div>

        <div
            @if ($canManage) wire:sort="$wire.reorder($item, $position)" @endif
            class="space-y-1"
        >
            @foreach ($subtasks as $subtask)
                <div
                    wire:key="subtask-{{ $subtask->id }}"
                    @if ($canManage) wire:sort:item="{{ $subtask->id }}" @endif
                    class="group flex items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-slate-50"
                >
                    @if ($canManage)
                        <span wire:sort:handle class="cursor-grab text-slate-200 group-hover:text-slate-400 active:cursor-grabbing"
                              aria-hidden="true">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                            </svg>
                        </span>
                    @endif

                    <input
                        type="checkbox"
                        @checked($subtask->completed)
                        @disabled(! $canManage)
                        wire:click="toggle({{ $subtask->id }})"
                        wire:sort:ignore
                        class="size-4 shrink-0 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                        aria-label="{{ $subtask->completed ? 'Mark incomplete' : 'Mark complete' }}"
                    >

                    @if ($editingId === $subtask->id)
                        <form wire:submit="saveEditing" class="flex flex-1 items-center gap-2" wire:sort:ignore>
                            <x-ui.input wire:model="editingTitle" autofocus class="py-1 text-sm"
                                        :invalid="$errors->has('editingTitle')"
                                        @keydown.escape="$wire.cancelEditing()" />
                            <x-ui.button type="submit" size="sm">Save</x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEditing">Cancel</x-ui.button>
                        </form>
                    @else
                        <span @class([
                            'min-w-0 flex-1 text-sm',
                            'text-slate-400 line-through' => $subtask->completed,
                            'text-slate-800' => ! $subtask->completed,
                        ])>{{ $subtask->title }}</span>

                        @if ($canManage)
                            <div class="flex shrink-0 items-center gap-1 opacity-0 transition group-hover:opacity-100" wire:sort:ignore>
                                <x-ui.button type="button" variant="ghost" size="sm"
                                             wire:click="startEditing({{ $subtask->id }})">Edit</x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" class="text-rose-600 hover:bg-rose-50"
                                             wire:click="remove({{ $subtask->id }})">Remove</x-ui.button>
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canManage)
        <form wire:submit="add" class="mt-3 flex items-start gap-2 {{ $total > 0 ? 'border-t border-slate-100 pt-3' : '' }}">
            <div class="flex-1">
                <x-ui.input wire:model="newTitle" placeholder="Add a checklist item"
                            :invalid="$errors->has('newTitle')" aria-label="New checklist item" />
                @error('newTitle')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <x-ui.button type="submit">Add</x-ui.button>
        </form>
    @elseif ($total === 0)
        <p class="text-sm text-slate-400">No checklist items.</p>
    @endif
</x-ui.card>
