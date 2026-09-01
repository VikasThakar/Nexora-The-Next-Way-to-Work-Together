@php
    /**
     * The customer summary.
     *
     * Every value on this page comes from CustomerStatistics, which is the only
     * statistics service the component injects. There is no branch here that
     * renders more for a member of staff — a staff member opening this page
     * sees the customer report, computed against their own visibility, which is
     * exactly what makes it useful for checking what a customer sees.
     */
    $isStaff = auth()->user()?->isStaff() ?? false;
@endphp

<div>
    <x-ui.page-header
        title="Your tickets"
        :description="$scope->board
            ? 'A summary of the work on '.$scope->board->name.'.'
            : 'A summary of the work shared with you.'"
    >
        @if ($isStaff)
            <x-slot:actions>
                <x-ui.button :href="route('stats')" variant="secondary" size="md">
                    Team statistics
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($isStaff)
        <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
            <span class="font-medium">This is the customer view.</span>
            It is filtered by your own access, not by a particular customer's, so it still includes anything
            you can see. Use it to check the shape of the page, not to confirm what one customer sees.
        </div>
    @endif

    <x-stats.filters :board-options="$boardOptions" :period="$period" :range="$range" />

    @if ($scope->isEmpty())
        <x-ui.empty-state
            title="Nothing shared with you yet"
            description="Once your team shares a board with you, a summary of its tickets appears here."
        />
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stats.metric label="Open" :value="number_format($counts['open'])" tone="brand" />
            <x-stats.metric label="Closed" :value="number_format($counts['closed'])" tone="emerald" />
            <x-stats.metric label="Total" :value="number_format($counts['total'])" />
            <x-stats.metric
                label="Raised in this period"
                :value="number_format($counts['created'])"
                :hint="$period->label()"
            />
        </div>

        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Status" description="How your tickets are split between open and finished.">
                <x-charts.donut :series="$statusSplit" empty="No tickets yet." />
            </x-ui.card>

            <x-ui.card title="Priority" description="Open tickets, by how urgent they are.">
                <x-charts.bar :series="$byPriority" empty="Nothing is open." />
            </x-ui.card>
        </div>

        <x-ui.card class="mb-6" title="Tickets raised" description="New tickets, by week.">
            <x-charts.columns :series="$createdByWeek" unit="tickets" empty="No tickets were raised in this period." />
        </x-ui.card>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Recent tickets">
                @if ($recentTickets->isEmpty())
                    <p class="py-6 text-center text-sm text-slate-500">Nothing to show yet.</p>
                @else
                    <ul class="divide-y divide-slate-100" role="list">
                        @foreach ($recentTickets as $ticket)
                            <li wire:key="recent-ticket-{{ $ticket->id }}" class="py-2.5 first:pt-0 last:pb-0">
                                <a
                                    href="{{ route('tickets.show', ['board' => $ticket->board, 'number' => $ticket->number]) }}"
                                    wire:navigate
                                    class="group flex items-baseline gap-2"
                                >
                                    <span class="font-mono text-xs text-slate-400">{{ $ticket->key() }}</span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-slate-700 group-hover:text-brand-700">
                                        {{ $ticket->title }}
                                    </span>
                                    <x-ui.badge variant="slate">{{ $ticket->column->name }}</x-ui.badge>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card title="Recent activity" description="Updates on the tickets you can see.">
                @if ($recentActivity->isEmpty())
                    <p class="py-6 text-center text-sm text-slate-500">Nothing has happened in this period.</p>
                @else
                    <ol class="space-y-3" role="list">
                        @foreach ($recentActivity as $event)
                            <li wire:key="activity-{{ $event->id }}" class="flex items-baseline gap-2 text-sm">
                                <span class="shrink-0 font-mono text-xs text-slate-400">
                                    {{ $event->ticket->board->ticket_prefix }}-{{ $event->ticket->number }}
                                </span>
                                <span class="min-w-0 flex-1 text-slate-600">
                                    <span class="font-medium text-slate-800">{{ $event->actor?->name ?? 'System' }}</span>
                                    {{ $event->type->label() }}
                                </span>
                                <time
                                    class="shrink-0 text-xs text-slate-400"
                                    datetime="{{ $event->created_at?->toIso8601String() }}"
                                >
                                    {{ $event->created_at?->diffForHumans(short: true) }}
                                </time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
