<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TabColumnMapping;
use RuntimeException;

/**
 * Move a ticket to another tab of its board.
 *
 * The one way a ticket changes tab, whoever asks: a card dropped on a tab,
 * the Tab select on the ticket page, the assistant, an AI app over MCP.
 *
 * Without a column it lands where App\Support\TabColumnMapping says — the
 * column with the same name, else a done column if it was done, else the
 * tab's first column — appended at the end. With a column, that column, which
 * must be in the tab. Either way it goes through MoveTicket, so the ordering,
 * the history ("from Main › To Do to Backend API › To Do") and the
 * notifications are the same as for any other move.
 *
 * Authorization is the caller's job (TicketPolicy::move); this only refuses
 * what can never be right — a tab on another board, a column outside the tab.
 */
class MoveTicketToTab
{
    public function __construct(private readonly MoveTicket $move) {}

    /**
     * @return BoardColumn the column the ticket is in afterwards
     */
    public function handle(Ticket $ticket, BoardTab $tab, ?User $actor = null, ?BoardColumn $column = null): BoardColumn
    {
        if ((int) $tab->board_id !== (int) $ticket->board_id) {
            throw new RuntimeException('A ticket can only be moved to another tab of its own board.');
        }

        $current = BoardColumn::query()->find($ticket->board_column_id);

        if ($column instanceof BoardColumn) {
            if ((int) $column->board_tab_id !== (int) $tab->getKey()) {
                throw new RuntimeException('The column "'.$column->name.'" is not in the '.$tab->name.' tab. Nothing was moved.');
            }

            $target = $column;
        } else {
            // Already in that tab: nothing to do, and nothing to record.
            if ($current instanceof BoardColumn && (int) $current->board_tab_id === (int) $tab->getKey()) {
                return $current;
            }

            $target = $current instanceof BoardColumn
                ? TabColumnMapping::counterpart($current, $tab)
                : $tab->firstColumn();
        }

        if (! $target instanceof BoardColumn) {
            throw new RuntimeException('The '.$tab->name.' tab has no columns to move the ticket into. Nothing was moved.');
        }

        // Callers name where the ticket landed ("Backend API › To Do").
        $target->setRelation('tab', $tab);

        if ($current instanceof BoardColumn && $current->is($target)) {
            return $target;
        }

        $this->move->handle($ticket, $target, PHP_INT_MAX, $actor, acrossTabs: true);

        return $target;
    }
}
