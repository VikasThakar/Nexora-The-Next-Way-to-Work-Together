@props([
    'boardOptions' => [],
    'period',
    'range',
])

@php
    /**
     * The board and date-range filter, shared by both statistics screens.
     *
     * `$boardOptions` is already restricted to boards the viewer may see (see
     * FiltersStatistics::boardOptions), so this template renders whatever it is
     * given and makes no access decision of its own.
     */
@endphp

<x-ui.card class="mb-6" :padded="false">
    <div class="flex flex-wrap items-end gap-4 px-5 py-4">
        <div class="min-w-48">
            <label for="stats-board" class="mb-1 block text-xs font-medium text-slate-600">Board</label>
            <x-ui.select id="stats-board" wire:model.live="boardSlug">
                <option value="">All boards</option>
                @foreach ($boardOptions as $slug => $name)
                    <option value="{{ $slug }}">{{ $name }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="min-w-44">
            <label for="stats-range" class="mb-1 block text-xs font-medium text-slate-600">Period</label>
            <x-ui.select id="stats-range" wire:model.live="range">
                @foreach (\App\Support\StatsPeriod::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($range === \App\Support\StatsPeriod::CUSTOM)
            <div>
                <label for="stats-from" class="mb-1 block text-xs font-medium text-slate-600">From</label>
                <x-ui.input id="stats-from" type="date" wire:model.live="customFrom" />
            </div>

            <div>
                <label for="stats-to" class="mb-1 block text-xs font-medium text-slate-600">To</label>
                <x-ui.input id="stats-to" type="date" wire:model.live="customTo" />
            </div>
        @endif

        <p class="ml-auto pb-2 text-xs text-slate-500">
            {{ $period->fromDate() }} → {{ $period->toDate() }}
            <span class="text-slate-400">({{ $period->timezone }})</span>
        </p>
    </div>
</x-ui.card>
