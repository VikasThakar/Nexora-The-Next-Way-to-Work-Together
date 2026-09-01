{{--
    Only links whose far end the viewer can actually read are in $links:
    TicketLinkReader re-queries both ends through the visibility scope and
    drops the rest entirely — no placeholder, no count — because even
    "2 tickets you cannot see" would confirm that those tickets exist.
--}}
<x-ui.card title="Linked tickets" description="Relationships to other tickets, including on other boards.">
    @if ($links->isEmpty())
        <p class="text-sm text-slate-400">No linked tickets.</p>
    @else
        <ul class="divide-y divide-slate-100">
            @foreach ($links as $row)
                <li wire:key="link-{{ $row->link->id }}" class="flex items-center gap-3 py-2.5">
                    <span class="w-24 shrink-0 text-xs font-medium tracking-wide text-slate-500 uppercase">
                        {{ $row->label }}
                    </span>

                    <a
                        href="{{ route('tickets.show', ['board' => $row->ticket->board, 'number' => $row->ticket->number]) }}"
                        wire:navigate
                        class="min-w-0 flex-1 truncate text-sm text-slate-900 hover:text-brand-700"
                    >
                        <span class="font-mono text-xs text-slate-400">{{ $row->ticket->key() }}</span>
                        {{ $row->ticket->title }}
                    </a>

                    @if ($row->ticket->board_id !== $ticket->board_id)
                        <x-ui.badge variant="slate">{{ $row->ticket->board->name }}</x-ui.badge>
                    @endif

                    @if ($canManage)
                        <x-ui.button type="button" variant="ghost" size="sm" class="text-rose-600 hover:bg-rose-50"
                                     wire:click="unlink({{ $row->link->id }})">
                            Unlink
                        </x-ui.button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canManage)
        <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
            <div class="flex flex-wrap items-end gap-3">
                <x-ui.field label="Relationship" for="link-type" class="w-40">
                    <x-ui.select id="link-type" wire:model="type">
                        @foreach ($linkTypes as $value => $caption)
                            <option value="{{ $value }}">{{ $caption }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Find a ticket" for="link-search" class="min-w-56 flex-1"
                            :error="$errors->first('selectedTicketId')">
                    <x-ui.input id="link-search" type="search" wire:model.live.debounce.300ms="search"
                                placeholder="Search by key, title or text…" />
                </x-ui.field>
            </div>

            @if ($selected)
                <div class="flex items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2">
                    <span class="min-w-0 flex-1 truncate text-sm text-slate-800">
                        <span class="font-mono text-xs text-slate-500">{{ $selected->key() }}</span>
                        {{ $selected->title }}
                    </span>
                    <x-ui.button type="button" size="sm" wire:click="link">Link</x-ui.button>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearSelection">Clear</x-ui.button>
                </div>
            @elseif ($results->isNotEmpty())
                <ul class="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                    @foreach ($results as $candidate)
                        <li wire:key="candidate-{{ $candidate->id }}">
                            <button type="button" wire:click="selectTicket({{ $candidate->id }})"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left transition hover:bg-slate-50">
                                <span class="font-mono text-xs text-slate-400">{{ $candidate->key() }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-slate-800">{{ $candidate->title }}</span>
                                @if ($candidate->board_id !== $ticket->board_id)
                                    <x-ui.badge variant="slate">{{ $candidate->board->name }}</x-ui.badge>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (trim($search) !== '')
                <p class="text-sm text-slate-400">No tickets match that search.</p>
            @endif
        </div>
    @endif
</x-ui.card>
