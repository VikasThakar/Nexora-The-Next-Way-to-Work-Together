<div>
    <x-ui.page-header
        title="Boards"
        description="Every board you have access to. Access is granted per board, never workspace-wide."
    >
        @if ($canCreateBoards)
            <x-slot:actions>
                <x-ui.button :href="route('boards.create')">New board</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div class="relative min-w-64 flex-1">
            <x-ui.input
                type="search"
                placeholder="Search boards…"
                wire:model.live.debounce.300ms="search"
                aria-label="Search boards"
            />
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input
                type="checkbox"
                wire:model.live="showArchived"
                class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            >
            Include archived
        </label>
    </div>

    @if ($boards->isEmpty())
        <x-ui.empty-state
            :title="$search !== '' ? 'No boards match that search' : 'No boards yet'"
            :description="$search !== ''
                ? 'Try a different name or ticket prefix.'
                : ($canCreateBoards
                    ? 'Create a board to get started.'
                    : 'You have not been added to any boards yet.')"
        />
    @else
        <x-ui.card :padded="false">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-5 py-3">Board</th>
                        <th scope="col" class="px-5 py-3">Prefix</th>
                        <th scope="col" class="px-5 py-3">Members</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($boards as $board)
                        <tr wire:key="board-{{ $board->id }}" class="transition hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <a href="{{ route('boards.show', $board) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">
                                    {{ $board->name }}
                                </a>
                                @if ($board->description)
                                    <p class="mt-0.5 line-clamp-1 text-xs text-slate-500">{{ $board->description }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="font-mono text-xs text-slate-600">{{ $board->ticket_prefix }}</span>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $board->members_count }}</td>
                            <td class="px-5 py-3">
                                @if ($board->isArchived())
                                    <x-ui.badge variant="amber">Archived</x-ui.badge>
                                @else
                                    <x-ui.badge variant="emerald">Active</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <x-ui.button :href="route('boards.show', $board)" variant="secondary" size="sm">Open</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">
            {{ $boards->links() }}
        </div>
    @endif
</div>
