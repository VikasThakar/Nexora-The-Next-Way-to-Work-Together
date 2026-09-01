<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\Board;
use App\Models\GithubLink;
use App\Models\Ticket;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The only supported way to read GitHub links.
 *
 * Same shape as TicketFinder, CommentReader and AiRunReader, and for the same
 * reason: there is no method here that returns an unscoped query, so a caller
 * cannot skip the rule by forgetting a scope. Every entry point starts from
 * `GithubLink::visibleTo($viewer)`, which refuses customers outright.
 */
class GithubLinkReader
{
    /**
     * @return Builder<GithubLink>
     */
    public function query(?Authenticatable $viewer): Builder
    {
        return GithubLink::query()->visibleTo($viewer);
    }

    /**
     * Everything linked to one ticket, ordered for the panel.
     *
     * @return Collection<int, GithubLink>
     */
    public function forTicket(Ticket $ticket, ?Authenticatable $viewer, int $limit = 30): Collection
    {
        return $this->query($viewer)
            ->forTicket($ticket)
            ->ordered()
            ->limit($limit)
            ->get();
    }

    public function countForTicket(Ticket $ticket, ?Authenticatable $viewer): int
    {
        return $this->query($viewer)->forTicket($ticket)->count();
    }

    /**
     * Recent activity across one board, for a future board-level view.
     *
     * @return Collection<int, GithubLink>
     */
    public function forBoard(Board $board, ?Authenticatable $viewer, int $limit = 25): Collection
    {
        return $this->query($viewer)
            ->forBoard($board)
            ->with('ticket')
            ->orderByDesc('github_links.updated_at')
            ->limit($limit)
            ->get();
    }
}
