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
 * Drives drag-and-drop and the Status select, and sits under every move to
 * another tab (App\Actions\Tickets\MoveTicketToTab), so the ordering rules
 * exist once.
 *
 * The browser sends a ticket id, a column id and an index. All three are
 * untrusted: the column is checked to belong to the same board as the ticket,
 * and the index is clamped to the column's actual length. Nothing here can move
 * a ticket onto a different board.
 *
 * Nor into another tab, unless the caller says that is what it means
 * ($acrossTabs). Dragging a card between columns is a move within its tab;
 * changing tab is a separate, explicit act — so a stale or forged column id
 * can never carry a ticket somewhere its board view does not show.
 *
 * The destination column is locked (shared) and the ticket locked and re-read
 * before anything changes, in the board → columns → tickets order every
 * structural action uses: a column or tab being deleted at the same moment
 * either finishes first, and this then sees where the ticket really is, or
 * waits for this move.
 *
 * Positions are rewritten as a dense 0..n-1 sequence for the columns involved.
 * Only rows whose position actually changes are written, so dropping a card
 * back where it came from costs no updates at all.
 */
class MoveTicket
{
    public function __construct(private readonly TicketActivity $activity) {}

    public function handle(
        Ticket $ticket,
        BoardColumn $column,
        int $position,
        ?User $actor = null,
        bool $acrossTabs = false,
    ): Ticket {
        if ((int) $column->board_id !== (int) $ticket->board_id) {
            throw new RuntimeException('A ticket cannot be moved to a column on another board.');
        }

        return DB::transaction(function () use ($ticket, $column, $position, $actor, $acrossTabs): Ticket {
            $destination = BoardColumn::query()->whereKey($column->getKey())->sharedLock()->first();

            if (! $destination instanceof BoardColumn) {
                throw new RuntimeException('That column no longer exists. Nothing was moved.');
            }

            $fresh = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();

            if (! $fresh instanceof Ticket) {
                throw new RuntimeException('That ticket no longer exists. Nothing was moved.');
            }

            // Start from where the ticket is now, not where the caller last saw
            // it. Merged, so anything the caller loaded alongside (counts) stays.
            $ticket->setRawAttributes(array_merge($ticket->getAttributes(), $fresh->getAttributes()), true);

            $sourceColumnId = (int) $ticket->board_column_id;
            $source = BoardColumn::query()->find($sourceColumnId);
            $movedColumns = $sourceColumnId !== (int) $destination->getKey();
            $crossesTabs = $source instanceof BoardColumn
                && (int) $source->board_tab_id !== (int) $destination->board_tab_id;

            if ($crossesTabs && ! $acrossTabs) {
                throw new RuntimeException('That column is in another tab. Move the ticket to that tab instead. Nothing was moved.');
            }

            $ticket->board_column_id = $destination->getKey();
            $ticket->save();
            $ticket->unsetRelation('column');

            $this->resequence((int) $destination->getKey(), (int) $ticket->getKey(), $position);

            if ($movedColumns) {
                $this->resequence($sourceColumnId, null, null);

                $payload = [
                    'from_column' => $source?->name,
                    'to_column' => $destination->name,
                    'from_column_id' => $sourceColumnId,
                    'to_column_id' => $destination->getKey(),
                    // Kept for the day the column is deleted (FlowMetrics).
                    'to_done' => (bool) $destination->is_done,
                    'position' => $ticket->refresh()->position,
                ];

                // On a board split into tabs the history says where, not only
                // which column — two tabs can both have a "To Do".
                if ($ticket->board->hasMultipleTabs()) {
                    $payload['from_tab'] = $source?->tab?->name;
                    $payload['to_tab'] = $destination->tab?->name;
                }

                $this->activity->record($ticket, TicketEventType::TicketMoved, array_filter(
                    $payload,
                    static fn ($value): bool => $value !== null,
                ), $actor);
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
