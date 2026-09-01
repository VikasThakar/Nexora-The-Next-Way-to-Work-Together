<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer-facing figures.
 *
 * A separate class from TeamStatistics rather than a flag on it, and that is
 * the whole design. A shared class with a `$forCustomer` parameter would mean
 * every future metric someone adds is exposed by default and has to be
 * remembered *not* to be — the wrong way round. Here the customer screen can
 * only render what this class offers, and this class offers six things.
 *
 * What it deliberately cannot answer, because the methods do not exist:
 * internal ticket counts, per-assignee workload, cycle time, time in column,
 * AI runs, AI cost, token usage, the internal/visible split, or anything at all
 * about work the customer is not shown.
 *
 * Underneath, the safety is the same one the rest of the application uses:
 * `StatisticsScope::tickets()` starts from `Ticket::visibleTo($viewer)`, so for
 * a customer the rows are already restricted to `customer_visible = true` on
 * boards they belong to, in SQL, before any counting happens. Nothing in this
 * file re-derives that rule — it would be a second definition of the customer
 * boundary, and second definitions drift.
 *
 * A member of staff may open the customer screen too, and should: it is the
 * only way to see what a customer actually sees. When they do, they see their
 * own visibility, not a simulated customer's — the numbers are honest about
 * whose view they are, and the screen says so.
 */
class CustomerStatistics
{
    /**
     * Open and closed counts, plus how many arrived in the period.
     *
     * @return array{open: int, closed: int, total: int, created: int}
     */
    public function counts(StatisticsScope $scope): array
    {
        $doneColumnIds = $scope->doneColumnIds();

        $total = (clone $scope->tickets())->count();

        $closed = $doneColumnIds === []
            ? 0
            : (clone $scope->tickets())->whereIn('tickets.board_column_id', $doneColumnIds)->count();

        return [
            'open' => $total - $closed,
            'closed' => $closed,
            'total' => $total,
            'created' => (clone $scope->ticketsCreatedInPeriod())->count(),
        ];
    }

    /**
     * Priority breakdown of the tickets they can see.
     *
     * Open tickets only. A customer's question is "what is outstanding, and how
     * urgent is it" — a priority chart dominated by two years of finished work
     * answers nothing.
     *
     * @return array<int, array{label: string, value: int, variant: string}>
     */
    public function byPriority(StatisticsScope $scope): array
    {
        $doneColumnIds = $scope->doneColumnIds();

        $counts = (clone $scope->tickets())
            ->when($doneColumnIds !== [], fn ($q) => $q->whereNotIn('tickets.board_column_id', $doneColumnIds))
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
     * Open versus closed, for the status doughnut.
     *
     * Two slices rather than one per column, and this is a product decision
     * rather than a security one — worth saying plainly, because it looks like
     * the latter.
     *
     * Column names are not secret: the board renders the same columns to
     * everyone who can reach it (see App\Models\BoardColumn), so a customer
     * already knows the team has a "Blocked" column. What they do not need is a
     * chart of how their work is distributed across the team's internal
     * workflow, which invites questions about process rather than about their
     * tickets. "Open" and "closed" is the question a customer actually has.
     *
     * @return array<int, array{label: string, value: int, variant: string}>
     */
    public function statusSplit(StatisticsScope $scope): array
    {
        $counts = $this->counts($scope);

        return [
            ['label' => 'Open', 'value' => $counts['open'], 'variant' => 'brand'],
            ['label' => 'Closed', 'value' => $counts['closed'], 'variant' => 'emerald'],
        ];
    }

    /**
     * Tickets raised per week over the period.
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
     * Recent activity on the tickets they can see.
     *
     * Read through `TicketEvent::readableBy()`, which applies the event-type
     * deny-list on top of board scoping. That is what keeps a visibility flip —
     * or any AI event — out of this feed: those types are internal-only, and the
     * scope removes them in SQL rather than the view remembering to skip them.
     *
     * @return Collection<int, TicketEvent>
     */
    public function recentActivity(StatisticsScope $scope, int $limit = 12): Collection
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        return $scope->events()
            ->with(['ticket.board', 'actor'])
            ->orderByDesc('ticket_events.created_at')
            ->orderByDesc('ticket_events.id')
            ->limit($limit)
            ->get();
    }

    /**
     * The customer's most recently updated tickets.
     *
     * @return Collection<int, Ticket>
     */
    public function recentTickets(StatisticsScope $scope, int $limit = 8): Collection
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        return (clone $scope->tickets())
            ->with(['board', 'column'])
            ->orderByDesc('tickets.updated_at')
            ->limit($limit)
            ->get();
    }
}
