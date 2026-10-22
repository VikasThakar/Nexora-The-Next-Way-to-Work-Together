<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Board;
use App\Models\BoardTab;
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
     * With a tab, only the tickets in that tab's columns — the board shows one
     * tab at a time. A tab of another board is refused as 404, never quietly
     * answered with nothing.
     *
     * @return Collection<int, Ticket>
     */
    public function forBoard(Board $board, ?Authenticatable $viewer, ?TicketFilters $filters = null, ?BoardTab $tab = null): Collection
    {
        if ($tab instanceof BoardTab && (int) $tab->board_id !== (int) $board->getKey()) {
            throw new NotFoundHttpException;
        }

        $query = $this->query($board, $viewer)
            ->when($tab instanceof BoardTab, fn (Builder $query) => $query->inTab($tab))
            ->with([
                'board',
                'assignee',
                'labels',
                // The pictures, newest first, for the card's cover. Only image
                // types: a card has no use for a PDF's row, and a board with
                // many tickets should not carry every attachment row along.
                'attachments' => fn ($q) => $q->where('mime_type', 'like', 'image/%')->latest('id'),
            ])
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
     * How many tickets each tab of the board holds for this viewer, under the
     * same filters as the board — the counts on the tab strip.
     *
     * One grouped query over the same visibility scope as the cards
     * themselves, so a count can never include a ticket its viewer could not
     * open: a customer's counts leave out internal work exactly as their board
     * does. Tabs with nothing matching are simply absent.
     *
     * @return array<int, int> tab id => ticket count
     */
    public function countsByTab(Board $board, ?Authenticatable $viewer, ?TicketFilters $filters = null): array
    {
        $query = $this->query($board, $viewer)
            ->join('board_columns', 'board_columns.id', '=', 'tickets.board_column_id');

        ($filters ?? new TicketFilters)->apply($query);

        return $query
            ->reorder()
            ->groupBy('board_columns.board_tab_id')
            ->selectRaw('board_columns.board_tab_id as tab_id, count(*) as aggregate')
            ->pluck('aggregate', 'tab_id')
            ->mapWithKeys(fn ($count, $tabId): array => [(int) $tabId => (int) $count])
            ->all();
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
