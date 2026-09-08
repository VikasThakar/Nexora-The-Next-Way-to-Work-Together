<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use App\Models\Ticket;
use App\Services\BoardAccess;
use App\Services\TicketFinder;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A shallow roll-up across every board the asker can reach.
 *
 * The sibling of App\Services\AI\BoardContextBuilder, and deliberately the
 * shallow one. That class answers "what is going on with this board" and can
 * afford ticket descriptions, documentation bodies and repository metadata,
 * because it is looking at one board. This one answers "what is going on across
 * my work", and the same generosity multiplied by twelve boards would spend the
 * whole context window on material the question probably does not need.
 *
 * So the trade is explicit: breadth is bought with depth. Per board the model
 * gets the name, the ticket prefix, how many tickets the asker can see, and a
 * handful of the most recently updated ones as one line each — key, column,
 * title. No descriptions, no documentation bodies, no activity history. If the
 * answer needs that, the assistant can say which board to switch to, and the
 * person switches the selector and asks again with the deep context.
 *
 * Authorization
 * -------------
 * Two layers, and neither is new:
 *
 *   which boards   BoardAccess::query() — the same query the sidebar and the
 *                  board index use, so a board absent from this roll-up is a
 *                  board absent from that person's navigation.
 *   which tickets  TicketFinder::query() — board membership and the customer
 *                  boundary, per ticket, with the asking user as the viewer.
 *
 * There is no visibility rule written in this file, which is the point. The
 * cross-board reach is new; the rules governing it are not.
 */
class WorkspaceContextBuilder
{
    public function __construct(
        private readonly BoardAccess $access,
        private readonly TicketFinder $tickets,
    ) {}

    /**
     * The whole roll-up for one question.
     */
    public function build(?Authenticatable $viewer): string
    {
        $boardLimit = max(1, (int) config('ai.chat.context.workspace.boards', 12));

        $boards = $this->access->query($viewer)
            ->notArchived()
            ->orderBy('name')
            ->limit($boardLimit)
            ->get();

        if ($boards->isEmpty()) {
            return "WORKSPACE CONTEXT\nYou are not a member of any board, so there is nothing to summarise.";
        }

        $sections = [];

        foreach ($boards as $board) {
            $sections[] = $this->boardSummary($board, $viewer);
        }

        $header = 'WORKSPACE CONTEXT (a summary of the '.$boards->count().' '
            .($boards->count() === 1 ? 'board' : 'boards')
            .' the person asking can see; everything below is what they are allowed to see)';

        // Said plainly, because the model will otherwise answer "which ticket
        // mentions X" as though it had been shown every ticket.
        $header .= "\nThis is a summary, not the full contents of each board. "
            .'For detail on one board, say which board the person should select.';

        return $header."\n\n".implode("\n\n", $sections);
    }

    // -----------------------------------------------------------------

    private function boardSummary(Board $board, ?Authenticatable $viewer): string
    {
        $perBoard = max(1, (int) config('ai.chat.context.workspace.tickets_per_board', 6));

        $visible = $this->tickets->query($board, $viewer);

        // Counted before the limit is applied, so the model can tell the
        // difference between "this board has six tickets" and "here are six of
        // this board's ninety".
        $total = (clone $visible)->count();

        $recent = $visible
            ->with(['board', 'column'])
            ->orderByDesc('tickets.updated_at')
            ->limit($perBoard)
            ->get();

        $lines = [sprintf(
            'BOARD: %s (ticket prefix %s) — %d %s visible to you',
            $board->name,
            $board->ticket_prefix,
            $total,
            $total === 1 ? 'ticket' : 'tickets',
        )];

        if (filled($board->description)) {
            $lines[] = '  '.mb_substr(trim((string) $board->description), 0, 200);
        }

        if ($recent->isEmpty()) {
            $lines[] = '  No tickets you can see.';

            return implode("\n", $lines);
        }

        $lines[] = '  Most recently updated:';

        foreach ($recent as $ticket) {
            /** @var Ticket $ticket */
            $lines[] = sprintf(
                '  - %s [%s] %s%s',
                $ticket->key(),
                $ticket->column?->name ?? 'no column',
                mb_substr($ticket->title, 0, 120),
                $ticket->customer_visible ? '' : ' · internal',
            );
        }

        if ($total > $recent->count()) {
            $lines[] = sprintf('  (%d more not listed)', $total - $recent->count());
        }

        return implode("\n", $lines);
    }
}
