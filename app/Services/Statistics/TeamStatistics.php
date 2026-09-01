<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\TicketPriority;
use App\Models\Label;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The counts on the team statistics screen.
 *
 * Everything here is a question about the present — "how many tickets are open
 * right now, and where are they" — which is why it reads from `tickets` rather
 * than from the history. The questions about *movement* live in FlowMetrics and
 * are deliberately a separate class, because mixing the two is how a report
 * ends up quoting "tickets in Done" as throughput.
 *
 * Every query starts from StatisticsScope, so the counts are already restricted
 * to boards the viewer may see. This class adds no visibility rules of its own
 * and must not: a second definition of the customer boundary living inside a
 * GROUP BY is exactly the kind of copy that goes stale unnoticed.
 *
 * Each breakdown is one grouped query. A per-assignee table built by counting
 * tickets per member in PHP would be one query per person on the board, on a
 * page that already runs a dozen.
 */
class TeamStatistics
{
    /**
     * Headline counts.
     *
     * `closed` is "currently sitting in a column the board marks as done",
     * which is a different figure from FlowMetrics' throughput and is labelled
     * differently on screen. `created` and `closedInPeriod` are the two that
     * respect the date filter; the rest describe the board as it stands.
     *
     * @return array{total: int, active: int, closed: int, created: int, unassigned: int, overdue: int}
     */
    public function counts(StatisticsScope $scope): array
    {
        $doneColumnIds = $scope->doneColumnIds();

        $total = (clone $scope->tickets())->count();

        $closed = $doneColumnIds === []
            ? 0
            : (clone $scope->tickets())->whereIn('tickets.board_column_id', $doneColumnIds)->count();

        return [
            'total' => $total,
            'active' => $total - $closed,
            'closed' => $closed,
            'created' => (clone $scope->ticketsCreatedInPeriod())->count(),
            'unassigned' => (clone $scope->tickets())
                ->whereNull('tickets.assignee_id')
                ->when($doneColumnIds !== [], fn (Builder $q) => $q->whereNotIn('tickets.board_column_id', $doneColumnIds))
                ->count(),
            'overdue' => (clone $scope->tickets())
                ->whereNotNull('tickets.due_date')
                ->whereDate('tickets.due_date', '<', now())
                ->when($doneColumnIds !== [], fn (Builder $q) => $q->whereNotIn('tickets.board_column_id', $doneColumnIds))
                ->count(),
        ];
    }

    /**
     * Tickets per column, in board order.
     *
     * @return array<int, array{label: string, value: int, is_done: bool}>
     */
    public function byColumn(StatisticsScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $multiple = count($scope->boardIds()) > 1;

        return (clone $scope->tickets())
            ->join('board_columns', 'board_columns.id', '=', 'tickets.board_column_id')
            ->join('boards', 'boards.id', '=', 'board_columns.board_id')
            ->groupBy('board_columns.id', 'board_columns.name', 'board_columns.position', 'board_columns.is_done', 'boards.ticket_prefix')
            ->orderBy('board_columns.position')
            ->orderBy('boards.ticket_prefix')
            ->get([
                'board_columns.name',
                'board_columns.is_done',
                'boards.ticket_prefix',
                DB::raw('COUNT(tickets.id) as aggregate_total'),
            ])
            ->map(fn ($row): array => [
                'label' => $multiple ? $row->ticket_prefix.' · '.$row->name : $row->name,
                'value' => (int) $row->aggregate_total,
                'is_done' => (bool) $row->is_done,
            ])
            ->all();
    }

    /**
     * Tickets per priority, most urgent first.
     *
     * Every priority is present even at zero, because "no critical tickets" is
     * information and an absent row reads as an oversight.
     *
     * @return array<int, array{label: string, value: int, variant: string}>
     */
    public function byPriority(StatisticsScope $scope): array
    {
        $counts = (clone $scope->tickets())
            ->groupBy('tickets.priority')
            ->pluck(DB::raw('COUNT(*)'), 'tickets.priority')
            ->all();

        $rows = [];

        foreach (TicketPriority::ordered() as $priority) {
            $rows[] = [
                'label' => $priority->label(),
                'value' => (int) ($counts[$priority->value] ?? 0),
                'variant' => $priority->badgeVariant(),
            ];
        }

        return $rows;
    }

    /**
     * Open tickets per assignee, busiest first, with unassigned work last.
     *
     * Only tickets that are not done: a table of who closed the most tickets
     * two years ago is not a workload view, and workload is what this is for.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function byAssignee(StatisticsScope $scope, int $limit = 15): array
    {
        $doneColumnIds = $scope->doneColumnIds();

        $base = fn (): Builder => (clone $scope->tickets())
            ->when($doneColumnIds !== [], fn (Builder $q) => $q->whereNotIn('tickets.board_column_id', $doneColumnIds));

        $rows = $base()
            ->join('users', 'users.id', '=', 'tickets.assignee_id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc(DB::raw('COUNT(tickets.id)'))
            ->orderBy('users.name')
            ->limit($limit)
            ->get(['users.name', DB::raw('COUNT(tickets.id) as aggregate_total')])
            ->map(fn ($row): array => [
                'label' => (string) $row->name,
                'value' => (int) $row->aggregate_total,
            ])
            ->all();

        $unassigned = $base()->whereNull('tickets.assignee_id')->count();

        if ($unassigned > 0) {
            $rows[] = ['label' => 'Unassigned', 'value' => $unassigned];
        }

        return $rows;
    }

    /**
     * Tickets per label, most used first.
     *
     * Labels are per board, so two boards using the same word are two labels.
     * They are merged by name here on purpose: "which kinds of work are we
     * doing" is the question, and splitting it by board would answer a
     * different one.
     *
     * @return array<int, array{label: string, value: int, color: string}>
     */
    public function byLabel(StatisticsScope $scope, int $limit = 15): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        return (clone $scope->tickets())
            ->join('ticket_label', 'ticket_label.ticket_id', '=', 'tickets.id')
            ->join('labels', 'labels.id', '=', 'ticket_label.label_id')
            ->groupBy('labels.name', 'labels.color')
            ->orderByDesc(DB::raw('COUNT(tickets.id)'))
            ->orderBy('labels.name')
            ->limit($limit)
            ->get(['labels.name', 'labels.color', DB::raw('COUNT(tickets.id) as aggregate_total')])
            ->map(fn ($row): array => [
                'label' => (string) $row->name,
                'value' => (int) $row->aggregate_total,
                'color' => (string) $row->color,
            ])
            ->all();
    }

    /**
     * Tickets created per week over the period, as a counterpart to throughput.
     *
     * Shown alongside closures so the two can be read together: a team closing
     * twelve a week while thirty arrive is not keeping up, and neither line
     * says that on its own.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function createdByWeek(StatisticsScope $scope): array
    {
        $buckets = array_fill_keys($scope->period->weekKeys(), 0);

        (clone $scope->ticketsCreatedInPeriod())
            ->orderBy('tickets.id')
            ->select(['tickets.id', 'tickets.created_at'])
            ->chunk(2000, function ($tickets) use (&$buckets, $scope): void {
                foreach ($tickets as $ticket) {
                    $key = $scope->period->weekKeyFor($ticket->created_at);

                    if (array_key_exists($key, $buckets)) {
                        $buckets[$key]++;
                    }
                }
            });

        $series = [];

        foreach ($scope->period->weeks() as $week) {
            $key = $scope->period->weekKeyFor($week['start']);
            $series[] = ['label' => $week['label'], 'value' => $buckets[$key] ?? 0];
        }

        return $series;
    }

    /**
     * How much of the board is visible to its customers.
     *
     * A team-only figure, and a useful one: a board where nothing is shared is
     * usually a board where somebody forgot to publish, not a board with
     * nothing to say.
     *
     * @return array{customer_visible: int, internal: int}
     */
    public function visibilitySplit(StatisticsScope $scope): array
    {
        $visible = (clone $scope->tickets())->where('tickets.customer_visible', true)->count();
        $total = (clone $scope->tickets())->count();

        return [
            'customer_visible' => $visible,
            'internal' => $total - $visible,
        ];
    }

    /**
     * The most recently touched tickets in scope, for the activity panel.
     *
     * @return Collection<int, Ticket>
     */
    public function recentTickets(StatisticsScope $scope, int $limit = 8)
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        return (clone $scope->tickets())
            ->with(['board', 'column', 'assignee'])
            ->orderByDesc('tickets.updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Labels available on the boards in scope. Used to size the chart legend.
     */
    public function labelCount(StatisticsScope $scope): int
    {
        return $scope->isEmpty()
            ? 0
            : Label::query()->whereIn('board_id', $scope->boardIds())->count();
    }
}
