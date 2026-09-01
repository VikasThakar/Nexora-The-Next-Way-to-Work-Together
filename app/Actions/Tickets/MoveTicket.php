<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a ticket to a column and a position within it.
 *
 * Drives drag-and-drop, and is also used by the column-delete flow, so the
 * ordering rules exist once.
 *
 * The browser sends a ticket id, a column id and an index. All three are
 * untrusted: the column is checked to belong to the same board as the ticket,
 * and the index is clamped to the column's actual length. Nothing here can move
 * a ticket onto a different board.
 *
 * Positions are rewritten as a dense 0..n-1 sequence for the columns involved.
 * Only rows whose position actually changes are written, so dropping a card
 * back where it came from costs no updates at all.
 */
class MoveTicket
{
    public function __construct(private readonly TicketActivity $activity) {}

    public function handle(Ticket $ticket, BoardColumn $column, int $position, ?User $actor = null): Ticket
    {
        if ($column->board_id !== $ticket->board_id) {
            throw new RuntimeException('A ticket cannot be moved to a column on another board.');
        }

        return DB::transaction(function () use ($ticket, $column, $position, $actor): Ticket {
            $sourceColumnId = $ticket->board_column_id;
            $movedColumns = $sourceColumnId !== $column->getKey();

            $ticket->board_column_id = $column->getKey();
            $ticket->save();

            $this->resequence($column->getKey(), $ticket->getKey(), $position);

            if ($movedColumns) {
                $this->resequence($sourceColumnId, null, null);

                $from = BoardColumn::query()->find($sourceColumnId);

                $this->activity->record($ticket, TicketEventType::TicketMoved, [
                    'from_column' => $from?->name,
                    'to_column' => $column->name,
                    'from_column_id' => $sourceColumnId,
                    'to_column_id' => $column->getKey(),
                    'position' => $ticket->refresh()->position,
                ], $actor);
            }

            return $ticket;
        });
    }

    /**
     * Rewrite a column into a dense ordering, optionally placing one ticket at
     * a chosen index.
     */
    private function resequence(int $columnId, ?int $ticketId, ?int $position): void
    {
        $ids = Ticket::query()
            ->where('board_column_id', $columnId)
            ->when($ticketId !== null, fn ($query) => $query->whereKeyNot($ticketId))
            ->ordered()
            ->pluck('id')
            ->all();

        if ($ticketId !== null) {
            $index = max(0, min($position ?? count($ids), count($ids)));
            array_splice($ids, $index, 0, [$ticketId]);
        }

        $current = Ticket::query()
            ->whereIn('id', $ids)
            ->pluck('position', 'id')
            ->all();

        foreach ($ids as $index => $id) {
            if (($current[$id] ?? null) === $index) {
                continue;
            }

            Ticket::query()->whereKey($id)->update(['position' => $index]);
        }
    }
}
