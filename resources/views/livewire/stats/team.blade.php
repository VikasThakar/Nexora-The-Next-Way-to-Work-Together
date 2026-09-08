<div>
    <x-ui.page-header
        title="Statistics"
        :description="$scope->board
            ? 'Delivery metrics for '.$scope->board->name.'.'
            : 'Delivery metrics across every board you can see.'"
        :trail="\App\Support\Breadcrumbs::underDashboard('Statistics')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('stats.customer')" variant="secondary" size="md">
                Customer view
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-stats.filters :board-options="$boardOptions" :period="$period" :range="$range" />

    @if ($scope->isEmpty())
        <x-ui.empty-state
            title="No boards to report on"
            description="You have not been added to any boards yet, so there is nothing to measure. Once a board is shared with you its metrics appear here."
        />
    @else
        {{-- Headline counts --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stats.metric
                label="Total tickets"
                :value="number_format($counts['total'])"
                :hint="number_format($counts['created']).' created in this period'"
            />
            <x-stats.metric
                label="Active"
                :value="number_format($counts['active'])"
                tone="brand"
                :hint="$counts['unassigned'] > 0 ? number_format($counts['unassigned']).' unassigned' : 'All assigned'"
            />
            <x-stats.metric
                label="Closed"
                :value="number_format($counts['closed'])"
                tone="emerald"
                hint="Currently in a done column"
            />
            <x-stats.metric
                label="Overdue"
                :value="number_format($counts['overdue'])"
                :tone="$counts['overdue'] > 0 ? 'rose' : 'muted'"
                hint="Past due date and not done"
            />
        </div>

        {{-- Flow --}}
        <h2 class="mb-3 text-sm font-semibold text-slate-900">Flow</h2>

        <p class="mb-4 max-w-3xl text-xs text-slate-500">
            Measured from the ticket history rather than from where cards sit today, so a ticket that was
            finished and later reopened is counted on the day it was finished.
            @if ($truncated)
                <span class="font-medium text-amber-700">
                    This range covers more tickets than one report folds
                    ({{ number_format(\App\Services\Statistics\FlowMetrics::MAX_TICKETS) }}), so the
                    time-in-column figures below cover only part of it. Narrow the period for an exact answer.
                </span>
            @endif
        </p>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stats.metric label="Closed in period" :value="number_format($closedInPeriod)" tone="emerald" />
            <x-stats.metric label="Throughput" :value="$throughputPerWeek" hint="tickets per week" />
            <x-stats.metric
                label="Median cycle time"
                :value="$cycleTime->medianLabel()"
                :hint="$cycleTime->isEmpty()
                    ? 'Nothing closed in this period'
                    : 'across '.$cycleTime->count.' closed '.\Illuminate\Support\Str::plural('ticket', $cycleTime->count)"
            />
            <x-stats.metric
                label="85th percentile"
                :value="$cycleTime->percentile85Label()"
                hint="Most tickets finish within this"
            />
        </div>

        {{--
            What came in, against what went out.

            Two charts side by side rather than one with both series on it: a
            single week's number is easier to read off an axis than off a
            crossing pair of lines, and the two are close enough together to
            compare by eye.

            The `created-vs-completed` dataset still pairs them for a
            spreadsheet, and either chart's CSV button is one of the two halves
            on its own — see App\Services\Statistics\StatisticsExport.
        --}}

        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Weekly throughput" description="Tickets that reached a done column, by week.">
                <x-charts.exportable
                    dataset="throughput-by-week"
                    :filters="$exportFilters"
                    filename="weekly-throughput"
                >
                    <x-charts.columns :series="$throughputByWeek" unit="tickets" empty="Nothing was closed in this period." />
                </x-charts.exportable>
            </x-ui.card>

            <x-ui.card title="Tickets created" description="New tickets raised, by week.">
                <x-charts.exportable
                    dataset="created-by-week"
                    :filters="$exportFilters"
                    filename="tickets-created"
                >
                    <x-charts.columns :series="$createdByWeek" unit="tickets" empty="No tickets were created in this period." />
                </x-charts.exportable>
            </x-ui.card>
        </div>

        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Cycle time trend" description="Median time from creation to done, by the week it closed.">
                <x-charts.exportable
                    dataset="cycle-time-trend"
                    :filters="$exportFilters"
                    filename="cycle-time-trend"
                >
                    <x-charts.line
                        :series="$cycleTimeTrend"
                        :formatter="fn ($hours) => \App\Services\Statistics\DurationSummary::humanise((float) $hours)"
                    />
                </x-charts.exportable>
            </x-ui.card>

            <x-ui.card title="Average time in column" description="Completed stays only, slowest first.">
                <x-charts.exportable
                    dataset="time-in-column"
                    :filters="$exportFilters"
                    :image="false"
                    filename="time-in-column"
                >
                @if ($timeInColumn === [])
                    <p class="py-8 text-center text-sm text-slate-500">
                        No tickets moved between columns in this period.
                    </p>
                @else
                    <div class="-mx-5 overflow-x-auto">
                        <table class="w-full min-w-md text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                    <th class="px-5 py-2 font-medium">Column</th>
                                    <th class="px-3 py-2 text-right font-medium">Average</th>
                                    <th class="px-3 py-2 text-right font-medium">Median</th>
                                    <th class="px-5 py-2 text-right font-medium">Stays</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($timeInColumn as $row)
                                    <tr wire:key="col-{{ $row['column_id'] }}">
                                        <td class="px-5 py-2 text-slate-700">{{ $row['column'] }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                            {{ $row['summary']->averageLabel() }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                            {{ $row['summary']->medianLabel() }}
                                        </td>
                                        <td class="px-5 py-2 text-right font-mono text-xs text-slate-400">
                                            {{ $row['summary']->count }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </x-charts.exportable>
            </x-ui.card>
        </div>

        {{-- Distribution --}}
        <h2 class="mb-3 text-sm font-semibold text-slate-900">Distribution</h2>

        {{--
            No PNG or SVG on these four. They are div-and-height bars rather
            than SVG — a deliberate choice made when they were written, so that
            no dataset reaches the browser at all — and rewriting four working
            charts to add an image button would be the wrong trade. Their
            numbers are still downloadable, which is the part somebody re-uses.
        --}}
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <x-ui.card title="By column" description="Where every ticket sits right now.">
                <x-charts.exportable dataset="by-column" :filters="$exportFilters" filename="tickets-by-column">
                    <x-charts.bar :series="$byColumn" empty="This board has no tickets yet." />
                </x-charts.exportable>
            </x-ui.card>

            <x-ui.card title="By priority">
                <x-charts.exportable dataset="by-priority" :filters="$exportFilters" filename="tickets-by-priority">
                    <x-charts.bar :series="$byPriority" />
                </x-charts.exportable>
            </x-ui.card>

            <x-ui.card title="Open work by assignee" description="Tickets not yet in a done column.">
                <x-charts.exportable dataset="by-assignee" :filters="$exportFilters" filename="open-by-assignee">
                    <x-charts.bar :series="$byAssignee" empty="Nothing is open." />
                </x-charts.exportable>
            </x-ui.card>

            <x-ui.card title="By label" description="Labels with the same name are merged across boards.">
                <x-charts.exportable dataset="by-label" :filters="$exportFilters" filename="tickets-by-label">
                    <x-charts.bar :series="$byLabel" empty="No labels have been applied yet." />
                </x-charts.exportable>
            </x-ui.card>
        </div>

        <x-ui.card
            class="mb-6"
            title="Customer visibility"
            description="How much of this work the customer can see. Internal tickets never appear on their statistics page."
        >
            <x-charts.exportable
                dataset="visibility-split"
                :filters="$exportFilters"
                filename="customer-visibility"
            >
                <x-charts.donut
                    :series="[
                        ['label' => 'Shared with customer', 'value' => $visibilitySplit['customer_visible'], 'variant' => 'emerald'],
                        ['label' => 'Internal', 'value' => $visibilitySplit['internal'], 'variant' => 'slate'],
                    ]"
                    empty="No tickets yet."
                />
            </x-charts.exportable>
        </x-ui.card>

        {{-- AI --}}
        @if ($showAi)
            <h2 class="mb-3 text-sm font-semibold text-slate-900">AI activity</h2>

            <p class="mb-4 max-w-3xl text-xs text-slate-500">
                Internal to the delivery team. None of these figures — the runs, their outcomes, the tokens or
                the cost — appear anywhere a customer can reach.
            </p>

            <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stats.metric
                    label="Runs"
                    :value="number_format($ai['runs'])"
                    :hint="number_format($ai['suggest']).' suggest · '.number_format($ai['apply']).' apply'"
                />
                <x-stats.metric
                    label="Succeeded"
                    :value="number_format($ai['completed'])"
                    tone="emerald"
                    :hint="$ai['success_rate'] === null ? 'Nothing has finished yet' : $ai['success_rate'].'% of finished runs'"
                />
                <x-stats.metric
                    label="Failed"
                    :value="number_format($ai['failed'])"
                    :tone="$ai['failed'] > 0 ? 'rose' : 'muted'"
                    :hint="$ai['cancelled'] > 0 ? number_format($ai['cancelled']).' cancelled' : 'Each writes an internal note'"
                />
                <x-stats.metric
                    label="Pull requests"
                    :value="number_format($ai['pull_requests'])"
                    hint="Opened as drafts for review"
                />
            </div>

            <div class="mb-6 grid gap-4 lg:grid-cols-2">
                <x-ui.card title="Tokens and cost">
                    {{--
                        A list of figures rather than a chart, so there is no
                        picture to take — but these are the numbers somebody
                        actually wants in a spreadsheet at the end of a month,
                        and the CSV carries the unpriced-run count alongside
                        them so the total cannot be read as complete.
                    --}}
                    <x-charts.exportable
                        dataset="ai-summary"
                        :filters="$exportFilters"
                        :image="false"
                    >
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-600">Input tokens</dt>
                            <dd class="font-mono text-xs text-slate-700">{{ number_format($ai['input_tokens']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-600">Output tokens</dt>
                            <dd class="font-mono text-xs text-slate-700">{{ number_format($ai['output_tokens']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-2.5">
                            <dt class="font-medium text-slate-700">Estimated cost</dt>
                            <dd class="font-mono text-xs font-medium text-slate-900">
                                {{ $aiStats->formatCost($ai['known_cost']) }}
                            </dd>
                        </div>
                    </dl>

                    @if ($ai['unpriced_runs'] > 0)
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            {{ number_format($ai['unpriced_runs']) }}
                            {{ \Illuminate\Support\Str::plural('run', $ai['unpriced_runs']) }}
                            reported no usage, or used a model with no published price, so
                            {{ $ai['unpriced_runs'] === 1 ? 'it is' : 'they are' }} excluded from the total above
                            rather than counted as zero. The real cost is higher than the figure shown.
                        </p>
                    @endif
                    </x-charts.exportable>
                </x-ui.card>

                <x-ui.card title="Runs per week" description="Completed in blue, failed in red.">
                    <x-charts.exportable
                        dataset="ai-runs-by-week"
                        :filters="$exportFilters"
                        filename="ai-runs-per-week"
                    >
                        <x-charts.columns :series="$aiByWeek" :stacked="true" empty="No AI runs in this period." />
                    </x-charts.exportable>
                </x-ui.card>
            </div>

            @if (count($aiByBoard) > 1)
                <x-ui.card class="mb-6" title="AI activity per board">
                    <x-charts.exportable
                        dataset="ai-by-board"
                        :filters="$exportFilters"
                        :image="false"
                    >
                    <div class="-mx-5 overflow-x-auto">
                        <table class="w-full min-w-lg text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                    <th class="px-5 py-2 font-medium">Board</th>
                                    <th class="px-3 py-2 text-right font-medium">Runs</th>
                                    <th class="px-3 py-2 text-right font-medium">Completed</th>
                                    <th class="px-3 py-2 text-right font-medium">Failed</th>
                                    <th class="px-5 py-2 text-right font-medium">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($aiByBoard as $row)
                                    <tr wire:key="ai-board-{{ $loop->index }}">
                                        <td class="px-5 py-2 text-slate-700">{{ $row['label'] }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-xs">{{ number_format($row['runs']) }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-xs text-emerald-700">
                                            {{ number_format($row['completed']) }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono text-xs {{ $row['failed'] > 0 ? 'text-rose-700' : 'text-slate-400' }}">
                                            {{ number_format($row['failed']) }}
                                        </td>
                                        <td class="px-5 py-2 text-right font-mono text-xs">
                                            {{ $aiStats->formatCost($row['known_cost']) }}
                                            @if ($row['unpriced_runs'] > 0)
                                                <span class="text-amber-600" title="{{ $row['unpriced_runs'] }} unpriced runs excluded">*</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    </x-charts.exportable>
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
