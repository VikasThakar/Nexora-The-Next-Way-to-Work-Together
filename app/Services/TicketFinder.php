<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Board;
use App\Models\Ticket;
use App\Support\TicketFilters;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only supported way to read tickets.
 *
 * Every entry point starts from Ticket::visibleTo($viewer), so board membership
 * and the customer rule are applied in SQL before anything else happens. A
 * caller cannot accidentally skip them by forgetting a scope, because there is
 * no method here that returns an unscoped query.
 */
class TicketFinder
{
    /**
     * Base query: tickets on this board that this viewer may read.
     *
     * @return Builder<Ticket>
     */
    public function query(Board $board, ?Authenticatable $viewer): Builder
    {
        return Ticket::query()
            ->visibleTo($viewer)
            ->forBoard($board);
    }

    /**
     * Every ticket to render on the Kanban board, already filtered and ordered.
     *
     * Loaded in a single query and grouped in PHP rather than one query per
     * column: a board with eight columns would otherwise cost eight round trips
     * plus eight more for the eager loads.
     *
     * @return Collection<int, Ticket>
     */
    public function forBoard(Board $board, ?Authenticatable $viewer, ?TicketFilters $filters = null): Collection
    {
        $query = $this->query($board, $viewer)
            ->with(['board', 'assignee', 'labels'])
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($q) => $q->where('completed', true),
                'attachments',
            ])
            ->ordered();

        ($filters ?? new TicketFilters)->apply($query);

        return $query->get();
    }

    /**
     * Resolve one ticket by its per-board number, or fail as 404.
     *
     * 404 rather than 403 for the same reason board access does: telling a
     * customer that AQD-42 exists but is not for them is itself a leak. An
     * internal ticket and a nonexistent number are indistinguishable.
     */
    public function findOrFail(Board $board, int $number, ?Authenticatable $viewer): Ticket
    {
        $ticket = $this->query($board, $viewer)
            ->where('tickets.number', $number)
            ->first();

        if (! $ticket instanceof Ticket) {
            throw new NotFoundHttpException;
        }

        return $ticket;
    }

    /**
     * Search across every board the viewer can reach.
     *
     * Used by the global ticket search. Internal tickets are excluded for
     * customers by the same scope that protects the board view, so search can
     * never become a side channel around it.
     *
     * @return Collection<int, Ticket>
     */
    public function searchAcrossBoards(?Authenticatable $viewer, string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        return Ticket::query()
            ->visibleTo($viewer)
            ->search($term)
            ->with('board')
            ->orderByDesc('tickets.updated_at')
            ->limit($limit)
            ->get();
    }
}
