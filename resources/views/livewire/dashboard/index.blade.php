@php
    $user = auth()->user();
@endphp

<div>
    <x-ui.page-header
        :title="'Welcome back, '.\Illuminate\Support\Str::before($user->name, ' ')"
        :description="match (true) {
            $user->isAdmin() => 'You have administrator access: every board in the workspace is listed here.',
            $user->isTeam() => 'These are the boards you have been added to.',
            default => 'These are the boards your team has shared with you.',
        }"
        :trail="\App\Support\Breadcrumbs::root()"
    >
        @if ($canCreateBoards)
            <x-slot:actions>
                <x-ui.button :href="route('boards.create')" size="md">New board</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.card :padded="false">
            <div class="px-5 py-4">
                <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Boards</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $boards->count() }}</p>
            </div>
        </x-ui.card>

        <x-ui.card :padded="false">
            <div class="px-5 py-4">
                <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Your role</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $user->role->label() }}</p>
            </div>
        </x-ui.card>

        <x-ui.card :padded="false">
            <div class="px-5 py-4">
                <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Internal content</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">
                    {{ $user->canSeeInternalContent() ? 'Visible' : 'Hidden' }}
                </p>
            </div>
        </x-ui.card>
    </div>

    @if ($boards->isEmpty())
        <x-ui.empty-state
            title="No boards yet"
            :description="$canCreateBoards
                ? 'Create the first board to start organising work. You can invite team members and customers to it afterwards.'
                : 'You have not been added to any boards yet. An administrator needs to invite you before anything appears here.'"
        >
            @if ($canCreateBoards)
                <x-slot:actions>
                    <x-ui.button :href="route('boards.create')">Create a board</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.empty-state>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($boards as $board)
                <a
                    href="{{ route('boards.show', $board) }}"
                    wire:navigate
                    wire:key="board-{{ $board->id }}"
                    class="group flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition hover:border-brand-300 hover:shadow-md"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="min-w-0 truncate text-sm font-semibold text-slate-900 group-hover:text-brand-700">
                            {{ $board->name }}
                        </h3>
                        <x-ui.badge variant="brand" class="font-mono">{{ $board->ticket_prefix }}</x-ui.badge>
                    </div>

                    <p class="mt-2 line-clamp-2 min-h-10 text-sm text-slate-500">
                        {{ $board->description ?: 'No description yet.' }}
                    </p>

                    <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
                        </svg>
                        {{ $board->members_count }} {{ \Illuminate\Support\Str::plural('member', $board->members_count) }}
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
