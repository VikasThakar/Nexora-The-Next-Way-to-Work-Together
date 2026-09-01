<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiRun;
use App\Models\Board;
use App\Models\Ticket;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only supported way to read AI runs.
 *
 * Modelled on TicketFinder and CommentReader for the same reason: there is no
 * method here that returns an unscoped query, so a caller cannot skip the rule
 * by forgetting a scope.
 *
 * The rule is stronger than the ones those readers apply, and simpler for it —
 * `AiRun::visibleTo()` refuses customers outright. So a count is safe to expose
 * here in a way it is not for comments: any viewer who gets a number at all is
 * staff on the board, and for everybody else the answer is zero because the
 * query returns nothing.
 */
class AiRunReader
{
    /**
     * @return Builder<AiRun>
     */
    public function query(Board $board, ?Authenticatable $viewer): Builder
    {
        return AiRun::query()
            ->visibleTo($viewer)
            ->forBoard($board);
    }

    /**
     * Every run on a ticket, newest first.
     *
     * @return Collection<int, AiRun>
     */
    public function forTicket(Ticket $ticket, ?Authenticatable $viewer, int $limit = 20): Collection
    {
        $ticket->loadMissing('board');

        return $this->query($ticket->board, $viewer)
            ->forTicket($ticket)
            ->with(['triggeredBy', 'boardRepository'])
            ->ordered()
            ->limit($limit)
            ->get();
    }

    /**
     * Is a run already queued or running for this ticket?
     *
     * Used to keep the manual button from starting a second run on top of a
     * first. Not a concurrency guarantee — two clicks a millisecond apart can
     * still both pass — which is why the job also refuses to execute a run it
     * cannot claim. This is the courtesy; that is the correctness.
     */
    public function hasActiveRun(Ticket $ticket, ?Authenticatable $viewer): bool
    {
        $ticket->loadMissing('board');

        return $this->query($ticket->board, $viewer)
            ->forTicket($ticket)
            ->whereIn('ai_runs.status', ['queued', 'running'])
            ->exists();
    }

    /**
     * Runs on a board, newest first, for a cost or activity summary.
     *
     * @return Collection<int, AiRun>
     */
    public function forBoard(Board $board, ?Authenticatable $viewer, int $limit = 50): Collection
    {
        return $this->query($board, $viewer)
            ->with(['ticket', 'triggeredBy'])
            ->ordered()
            ->limit($limit)
            ->get();
    }

    /**
     * Resolve one run within a board, or fail as 404.
     *
     * 404 rather than 403, as everywhere else: for a customer an AI run and a
     * row that never existed must look the same.
     */
    public function findOrFail(Board $board, int $id, ?Authenticatable $viewer): AiRun
    {
        $run = $this->query($board, $viewer)->whereKey($id)->first();

        if (! $run instanceof AiRun) {
            throw new NotFoundHttpException;
        }

        return $run;
    }
}
