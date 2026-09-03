<div>
    <x-ui.page-header
        title="Activity"
        description="Important changes and actions across every board you can see. Ticket movement, assignments, documentation and board settings, newest first."
    >
        <x-slot:actions>
            <x-ui.button :href="route('stats')" variant="secondary">Statistics</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-activity.filters
        :board-options="$boardOptions"
        :actor-options="$actorOptions"
        :type-options="$typeOptions"
        :range-options="$rangeOptions"
        :filters="$filters"
        :period="$period"
    />

    @if ($activities->isEmpty())
        {{--
            Two different empty states, because they call for two different
            actions. A filtered feed that found nothing is a filter to loosen;
            an unfiltered one is a workspace with no history yet — or, for
            somebody not on any board, nothing they are allowed to see.
        --}}
        @if ($filters->isEmpty())
            <x-ui.empty-state
                title="No activity yet"
                description="Once tickets start moving, pages are written and boards are configured, everything worth remembering shows up here."
            />
        @else
            <x-ui.empty-state
                title="No activity matches"
                description="Try a different search, a wider period, or clear the filters."
            >
                <x-slot:actions>
                    <x-ui.button type="button" variant="secondary" wire:click="clearFilters">
                        Clear filters
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @endif
    @else
        <div class="space-y-6">
            @foreach ($groups as $heading => $items)
                <section wire:key="group-{{ $loop->index }}-{{ \Illuminate\Support\Str::slug($heading) }}">
                    <h2 class="mb-2 px-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        {{ $heading }}
                    </h2>

                    <x-ui.card :padded="false">
                        <ol class="divide-y divide-slate-100">
                            @foreach ($items as $activity)
                                <x-activity.item
                                    :key="'activity-'.$activity->id"
                                    :activity="$activity"
                                    :timezone="$timezone"
                                />
                            @endforeach
                        </ol>
                    </x-ui.card>
                </section>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $activities->links() }}
        </div>
    @endif
</div>
