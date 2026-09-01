<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\TicketEventType;
use App\Models\BoardColumn;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How work actually moved, measured from `ticket_events`.
 *
 * The distinction that makes this file worth having: a ticket's *current*
 * state cannot answer a single question on this page. "Twelve tickets are in
 * Done" is not throughput — those twelve might have arrived over two years, and
 * the eight that were finished last month and then reopened are invisible.
 * Flow is a property of transitions, so every number below is derived from the
 * append-only history and none of them reads `tickets.board_column_id`.
 *
 * The history was designed for this from the first migration: `ticket_events`
 * carries `board_id`, `to_column_id` and `from_column_id` as indexed columns
 * precisely so "which tickets entered a done column on this board last week"
 * is one index range scan.
 *
 * Two honest limitations, both surfaced to the reader rather than hidden:
 *
 *   - a ticket that has never left its column contributes no interval to the
 *     time-in-column figures. Only completed stays are measured, because an
 *     open-ended one has no length yet and counting "so far" would make a
 *     healthy board look worse the longer it stays healthy.
 *   - the fold is bounded (see MAX_TICKETS). A range wide enough to exceed it
 *     reports that it was truncated instead of quietly averaging a subset.
 */
class FlowMetrics
{
    /**
     * How many tickets one report may fold transitions for.
     *
     * The aggregates above this are pure SQL and unbounded; only the
     * interval-by-interval fold loads rows into PHP, and it is the one place a
     * three-year custom range could turn a reporting screen into a memory
     * problem. Ten thousand tickets is far past any real question.
     */
    public const MAX_TICKETS = 10000;

    /**
     * Per-scope memoisation, for the life of one report.
     *
     * The flow section asks the same two questions repeatedly: throughput, the
     * weekly chart, the cycle-time summary and the cycle-time trend are four
     * views of one set of closures, and the interval fold and the truncation
     * warning both need the same list of moved tickets. Without a memo one page
     * runs each aggregate three or four times.
     *
     * Keyed on the scope's object identity rather than on its contents: a scope
     * is immutable and is built once per render, so identity is exactly the
     * right key and it needs no hashing of board ids and dates. The service is
     * resolved per request, so nothing here outlives a single report.
     *
     * @var array<int, Collection<int, CarbonImmutable>>
     */
    private array $closures = [];

    /** @var array<int, array<int, int>> */
    private array $movedTicketIds = [];

    /**
     * Tickets that reached a done column during the period, keyed by ticket id.
     *
     * The value is the *first* arrival within the range: a ticket that was
     * finished, reopened and finished again inside one report is one closed
     * ticket, counted on the day it first got there.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function closures(StatisticsScope $scope): Collection
    {
        return $this->closures[spl_object_id($scope)] ??= $this->queryClosures($scope);
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function queryClosures(StatisticsScope $scope): Collection
    {
        $doneColumnIds = $scope->doneColumnIds();

        if ($doneColumnIds === [] || $scope->isEmpty()) {
            return collect();
        }

        return $scope->events()
            ->transitions()
            ->whereIn('ticket_events.to_column_id', $doneColumnIds)
            ->selectRaw('ticket_events.ticket_id, MIN(ticket_events.created_at) as closed_at')
            ->groupBy('ticket_events.ticket_id')
            ->pluck('closed_at', 'ticket_id')
            ->mapWithKeys(fn ($closedAt, $ticketId): array => [
                (int) $ticketId => CarbonImmutable::parse((string) $closedAt),
            ]);
    }

    /**
     * Tickets closed per week, oldest week first.
     *
     * Weeks with no closures are present as zeroes: a chart that skips quiet
     * weeks compresses the gaps and makes a stalled month look busy.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function throughputByWeek(StatisticsScope $scope): array
    {
        $buckets = array_fill_keys($scope->period->weekKeys(), 0);

        foreach ($this->closures($scope) as $closedAt) {
            $key = $scope->period->weekKeyFor($closedAt);

            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        $labels = [];

        foreach ($scope->period->weeks() as $week) {
            $labels[$scope->period->weekKeyFor($week['start'])] = $week['label'];
        }

        $series = [];

        foreach ($buckets as $key => $value) {
            $series[] = ['label' => $labels[$key] ?? $key, 'value' => $value];
        }

        return $series;
    }

    /**
     * Closed tickets per day of the period, as an average.
     *
     * The single number a delivery team quotes when asked "how fast are we
     * going": completed work divided by elapsed time, both measured over the
     * same window.
     */
    public function throughputPerWeek(StatisticsScope $scope): float
    {
        $days = $scope->period->days();

        return round($this->closures($scope)->count() / max(1, $days) * 7, 1);
    }

    /**
     * Time from a ticket being created to the first time it reached done.
     *
     * The start is read from the `ticket_created` event, not from
     * `tickets.created_at`, so the measurement comes entirely from the history
     * the spec asks it to come from. The row's own timestamp is used only as a
     * fallback for tickets that predate the event or were created outside the
     * action — it is the same instant when both exist.
     *
     * Only tickets whose closure falls inside the period are measured. That is
     * the useful question ("how long did the work we finished this month
     * take?") and it is also the only one that terminates: measuring open
     * tickets would mean measuring against now, which changes every time the
     * page is refreshed.
     */
    public function cycleTime(StatisticsScope $scope): DurationSummary
    {
        return DurationSummary::of(array_values($this->cycleTimeHours($scope)));
    }

    /**
     * Median cycle time per week of closure, for the trend chart.
     *
     * Median rather than mean, for the reason DurationSummary explains: one
     * ancient backlog item closed in a quiet week would otherwise produce a
     * spike that says nothing about that week's work.
     *
     * @return array<int, array{label: string, value: float}>
     */
    public function cycleTimeTrend(StatisticsScope $scope): array
    {
        $closures = $this->closures($scope);
        $hours = $this->cycleTimeHours($scope);

        /** @var array<string, array<int, float>> $buckets */
        $buckets = array_fill_keys($scope->period->weekKeys(), []);

        foreach ($hours as $ticketId => $value) {
            $closedAt = $closures->get($ticketId);

            if ($closedAt === null) {
                continue;
            }

            $key = $scope->period->weekKeyFor($closedAt);

            if (array_key_exists($key, $buckets)) {
                $buckets[$key][] = $value;
            }
        }

        $series = [];

        foreach ($scope->period->weeks() as $week) {
            $key = $scope->period->weekKeyFor($week['start']);
            $summary = DurationSummary::of($buckets[$key] ?? []);

            $series[] = [
                'label' => $week['label'],
                // A week that closed nothing has no median. Zero would read as
                // "instant" on a chart, so it is reported as a gap.
                'value' => $summary->medianHours ?? 0.0,
                'measured' => ! $summary->isEmpty(),
            ];
        }

        return $series;
    }

    /**
     * How long tickets stayed in each column before moving on.
     *
     * Measured by folding each ticket's transitions into consecutive intervals:
     * the stay in the column an event moved the ticket *to* ends at the next
     * event, whatever that is. Only intervals that ended inside the period are
     * counted, so a ticket sitting in Review since March does not inflate this
     * month's Review figure — it simply has not finished its stay yet.
     *
     * @return array<int, array{column: string, column_id: int, summary: DurationSummary}>
     */
    public function timeInColumn(StatisticsScope $scope): array
    {
        $names = $this->columnNames($scope);

        /** @var array<int, array<int, float>> $intervals */
        $intervals = [];

        foreach ($this->transitionsByTicket($scope) as $events) {
            $count = count($events);

            for ($i = 0; $i < $count - 1; $i++) {
                $enteredAt = $events[$i]['at'];
                $leftAt = $events[$i + 1]['at'];
                $columnId = $events[$i]['to'];

                if ($columnId === null) {
                    continue;
                }

                // The stay is attributed to the period it *ended* in.
                if ($leftAt->lessThan($scope->period->from) || $leftAt->greaterThan($scope->period->to)) {
                    continue;
                }

                $intervals[$columnId][] = $enteredAt->diffInMinutes($leftAt) / 60;
            }
        }

        $rows = [];

        foreach ($intervals as $columnId => $hours) {
            $rows[] = [
                'column_id' => $columnId,
                // An id with no matching row is a column the team has since
                // deleted. The history is still true, so it is reported rather
                // than dropped.
                'column' => $names[$columnId] ?? 'Archived column',
                'summary' => DurationSummary::of($hours),
            ];
        }

        // Slowest first: the point of this table is to find where work waits.
        usort(
            $rows,
            fn (array $a, array $b): int => ($b['summary']->averageHours ?? 0) <=> ($a['summary']->averageHours ?? 0)
        );

        return $rows;
    }

    /**
     * Whether the interval fold hit its ceiling for this scope.
     *
     * Surfaced on the screen: an average silently computed over the first ten
     * thousand of thirty thousand tickets is worse than no average at all.
     */
    public function wasTruncated(StatisticsScope $scope): bool
    {
        return count($this->ticketIdsWithTransitions($scope)) >= self::MAX_TICKETS;
    }

    // -----------------------------------------------------------------

    /**
     * Cycle time in hours, keyed by ticket id.
     *
     * @return array<int, float>
     */
    private function cycleTimeHours(StatisticsScope $scope): array
    {
        $closures = $this->closures($scope);

        if ($closures->isEmpty()) {
            return [];
        }

        $ticketIds = $closures->keys()->all();
        $created = $this->creationTimes($scope, $ticketIds);

        $hours = [];

        foreach ($closures as $ticketId => $closedAt) {
            $start = $created[$ticketId] ?? null;

            if ($start === null || $start->greaterThan($closedAt)) {
                continue;
            }

            $hours[$ticketId] = $start->diffInMinutes($closedAt) / 60;
        }

        return $hours;
    }

    /**
     * When each of these tickets was created, from the history first.
     *
     * @param  array<int, int>  $ticketIds
     * @return array<int, CarbonImmutable>
     */
    private function creationTimes(StatisticsScope $scope, array $ticketIds): array
    {
        $times = $scope->allEvents()
            ->where('ticket_events.type', TicketEventType::TicketCreated->value)
            ->whereIn('ticket_events.ticket_id', $ticketIds)
            ->selectRaw('ticket_events.ticket_id, MIN(ticket_events.created_at) as created_at')
            ->groupBy('ticket_events.ticket_id')
            ->pluck('created_at', 'ticket_id')
            ->mapWithKeys(fn ($at, $id): array => [(int) $id => CarbonImmutable::parse((string) $at)])
            ->all();

        $missing = array_values(array_diff($ticketIds, array_keys($times)));

        if ($missing === []) {
            return $times;
        }

        // Tickets with no creation event: imported, or created before the
        // history existed. The row's own timestamp is the same instant the
        // event would have carried, so the measurement stays correct rather
        // than dropping those tickets out of the average.
        $fallback = Ticket::query()
            ->visibleTo($scope->viewer)
            ->whereIn('tickets.id', $missing)
            ->pluck('tickets.created_at', 'tickets.id');

        foreach ($fallback as $id => $at) {
            $times[(int) $id] = CarbonImmutable::parse((string) $at);
        }

        return $times;
    }

    /**
     * Every transition of every ticket that moved during the period, ordered.
     *
     * The full history of those tickets is loaded, not just the part inside the
     * range, because an interval that *ends* in the range usually *starts*
     * before it — and clipping the start would report a stay as shorter than
     * it was.
     *
     * @return array<int, array<int, array{at: CarbonImmutable, to: ?int}>>
     */
    private function transitionsByTicket(StatisticsScope $scope): array
    {
        $ticketIds = $this->ticketIdsWithTransitions($scope);

        if ($ticketIds === []) {
            return [];
        }

        $byTicket = [];

        $scope->allEvents()
            ->transitions()
            ->whereIn('ticket_events.ticket_id', $ticketIds)
            ->orderBy('ticket_events.ticket_id')
            ->orderBy('ticket_events.created_at')
            ->orderBy('ticket_events.id')
            ->select(['ticket_events.ticket_id', 'ticket_events.to_column_id', 'ticket_events.created_at'])
            ->chunk(2000, function ($events) use (&$byTicket): void {
                foreach ($events as $event) {
                    $byTicket[(int) $event->ticket_id][] = [
                        'at' => CarbonImmutable::parse((string) $event->created_at),
                        'to' => $event->to_column_id === null ? null : (int) $event->to_column_id,
                    ];
                }
            });

        return $byTicket;
    }

    /**
     * Tickets with at least one transition inside the period.
     *
     * @return array<int, int>
     */
    private function ticketIdsWithTransitions(StatisticsScope $scope): array
    {
        return $this->movedTicketIds[spl_object_id($scope)] ??= $this->queryMovedTicketIds($scope);
    }

    /**
     * @return array<int, int>
     */
    private function queryMovedTicketIds(StatisticsScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        return $scope->events()
            ->transitions()
            ->distinct()
            ->limit(self::MAX_TICKETS)
            ->pluck('ticket_events.ticket_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Column names for the boards in scope, keyed by id.
     *
     * @return array<int, string>
     */
    private function columnNames(StatisticsScope $scope): array
    {
        $boardIds = $scope->boardIds();

        if ($boardIds === []) {
            return [];
        }

        // Across several boards the same column name appears many times, and
        // "In Progress" on two boards is two different columns. Prefixing keeps
        // the table readable without pretending they are one.
        $multiple = count($boardIds) > 1;

        return BoardColumn::query()
            ->whereIn('board_columns.board_id', $boardIds)
            ->join('boards', 'boards.id', '=', 'board_columns.board_id')
            ->get(['board_columns.id', 'board_columns.name', 'boards.ticket_prefix'])
            ->mapWithKeys(fn (BoardColumn $column): array => [
                (int) $column->id => $multiple
                    ? $column->ticket_prefix.' · '.$column->name
                    : $column->name,
            ])
            ->all();
    }
}
