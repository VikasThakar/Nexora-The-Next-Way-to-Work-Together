{{--
    Deleting a tab, decided on the server.

    Always this panel, never a one-click confirm: whether the tab holds
    tickets is checked when the panel is built, and DeleteTab checks it again
    under a lock — a confirm drawn earlier would go stale the moment somebody
    added a ticket. Styled like the column delete panel in board settings.

    The preview is built from App\Support\TabColumnMapping, the same rule
    DeleteTab applies, so what it says is what happens.
--}}
<div
    class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4"
    role="region"
    aria-label="Delete the {{ $deletingTab->name }} tab"
>
    <p class="text-sm font-medium text-rose-900">
        Delete the “{{ $deletingTab->name }}” tab?
    </p>

    <p class="mt-1 text-sm text-rose-800">
        Its {{ $deletingTab->columns->count() }} {{ \Illuminate\Support\Str::plural('column', $deletingTab->columns->count()) }}
        ({{ $deletingTab->columns->pluck('name')->implode(', ') }}) go with it.
    </p>

    @if ($deletingTabTickets > 0)
        <p class="mt-1 text-sm text-rose-800">
            It holds {{ $deletingTabTickets }} {{ \Illuminate\Support\Str::plural('ticket', $deletingTabTickets) }}.
            Choose a tab to move them to — they are moved, not deleted.
        </p>

        <div class="mt-3 max-w-xs">
            <x-ui.select wire:model.live="destinationTabId" aria-label="Move the tickets to">
                @foreach ($tabs as $option)
                    @continue($option->is($deletingTab))
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($deletionMap !== [])
            <ul class="mt-3 space-y-1 text-sm text-rose-900">
                @foreach ($deletionMap as $row)
                    <li>
                        {{ $row['from'] }} ({{ $row['count'] }}) → {{ $row['to'] }}
                        <span class="text-rose-700">
                            @switch($row['reason'])
                                @case(\App\Support\TabColumnMapping::REASON_NAME)
                                    (same name)
                                    @break
                                @case(\App\Support\TabColumnMapping::REASON_DONE)
                                    (a done column)
                                    @break
                                @default
                                    (no column of that name)
                            @endswitch
                        </span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-2 text-xs text-rose-700">
                A ticket goes to the column with the same name; a ticket in a done column goes to a done column;
                anything else goes to the first column.
            </p>
        @endif
    @else
        <p class="mt-1 text-sm text-rose-800">The tab is empty, so no tickets are moved.</p>
    @endif

    <div class="mt-4 flex items-center gap-2">
        <x-ui.button type="button" variant="danger" size="sm" wire:click="confirmDeleteTab">
            {{ $deletingTabTickets > 0 ? 'Move tickets and delete' : 'Delete tab' }}
        </x-ui.button>
        <x-ui.button type="button" variant="secondary" size="sm" wire:click="cancelDeletingTab">
            Cancel
        </x-ui.button>
    </div>
</div>
