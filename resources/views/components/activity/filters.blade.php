@props([
    'boardOptions' => [],
    'actorOptions' => [],
    'typeOptions' => [],
    'rangeOptions' => [],
    'filters',
    'period' => null,
])

@php
    /**
     * The Activity screen's filter bar.
     *
     * Deliberately the same construction as x-stats.filters: one card, labelled
     * selects, the custom date inputs appearing only when the custom range is
     * chosen. Every option list arrives already scoped to the viewer (see
     * App\Services\ActivityReader), so this template renders what it is given
     * and makes no access decision of its own.
     */
@endphp

<x-ui.card class="mb-6" :padded="false">
    <div class="space-y-4 px-5 py-4">
        <div>
            <label for="activity-search" class="sr-only">Search activities</label>
            <x-ui.input
                id="activity-search"
                type="search"
                placeholder="Search by ticket key, title, person, board or description…"
                wire:model.live.debounce.300ms="search"
            />
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-48">
                <label for="activity-board" class="mb-1 block text-xs font-medium text-slate-600">Board</label>
                <x-ui.select id="activity-board" wire:model.live="boardSlug">
                    <option value="">All boards</option>
                    @foreach ($boardOptions as $slug => $name)
                        <option value="{{ $slug }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-48">
                <label for="activity-type" class="mb-1 block text-xs font-medium text-slate-600">Activity</label>
                <x-ui.select id="activity-type" wire:model.live="type">
                    <option value="">All activity</option>
                    @foreach ($typeOptions as $group => $options)
                        @if (! empty($options))
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-44">
                <label for="activity-user" class="mb-1 block text-xs font-medium text-slate-600">Person</label>
                <x-ui.select id="activity-user" wire:model.live="userId">
                    <option value="">Anyone</option>
                    @foreach ($actorOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-40">
                <label for="activity-range" class="mb-1 block text-xs font-medium text-slate-600">Period</label>
                <x-ui.select id="activity-range" wire:model.live="range">
                    <option value="">All time</option>
                    @foreach ($rangeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            @if ($filters->range === \App\Support\StatsPeriod::CUSTOM)
                <div>
                    <label for="activity-from" class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <x-ui.input id="activity-from" type="date" wire:model.live="customFrom" />
                </div>

                <div>
                    <label for="activity-to" class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <x-ui.input id="activity-to" type="date" wire:model.live="customTo" />
                </div>
            @endif

            <div class="ml-auto flex items-center gap-3 pb-1">
                @if ($period)
                    <p class="text-xs text-slate-500">
                        {{ $period->fromDate() }} &rarr; {{ $period->toDate() }}
                        <span class="text-slate-400">({{ $period->timezone }})</span>
                    </p>
                @endif

                @unless ($filters->isEmpty())
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearFilters">
                        Clear {{ $filters->activeCount() }} {{ \Illuminate\Support\Str::plural('filter', $filters->activeCount()) }}
                    </x-ui.button>
                @endunless
            </div>
        </div>
    </div>
</x-ui.card>
