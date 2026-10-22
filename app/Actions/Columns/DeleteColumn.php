<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Actions\Concerns\LocksBoardStructure;
use App\Enums\TicketEventType;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use App\Services\TicketActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Delete a column without ever destroying the tickets in it.
 *
 * Four things have to line up for that guarantee to hold:
 *
 *  1. This action refuses to run when the column still holds tickets and no
 *     destination was named.
 *  2. The destination is verified to be a different column in the same tab
 *     of the same board, so a hostile id cannot move tickets onto someone
 *     else's board, nor quietly into another tab.
 *  3. A tab always keeps at least one column, checked here under the board
 *     lock so two deletions at once cannot both pass.
 *  4. tickets.board_column_id is restrictOnDelete, so if any check were ever
 *     bypassed the database would reject the delete rather than cascade it.
 *
 * The move is recorded in each ticket's history, because "my ticket is suddenly
 * in a different column" should be explainable afterwards.
 */
class DeleteColumn
{
    use LocksBoardStructure;

    public function __construct(
        private readonly TicketActivity $activity,
        private readonly ActivityLogger $workspaceActivity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    public function handle(BoardColumn $column, ?BoardColumn $destination = null, ?User $actor = null): void
    {
        $column->loadMissing('board', 'tab');
        $board = $column->board;
        $name = (string) $column->name;

        DB::transaction(function () use ($column, $destination, $actor, $board, $name): void {
            $this->lockBoard((int) $column->board_id);

            if (! BoardColumn::query()->whereKey($column->getKey())->exists()) {
                throw new RuntimeException('That column has already been removed.');
            }

            $multipleTabs = $board instanceof Board && $board->hasMultipleTabs();

            if (BoardColumn::query()->where('board_tab_id', $column->board_tab_id)->count() <= 1) {
                throw new RuntimeException($multipleTabs ? 'A tab needs at least one column.' : 'A board needs at least one column.');
            }

            $tickets = Ticket::query()
                ->where('board_column_id', $column->getKey())
                ->ordered()
                ->lockForUpdate()
                ->get();

            if ($tickets->isNotEmpty()) {
                $this->assertUsableDestination($column, $destination);
                $this->moveTickets($column, $destination, $tickets, $multipleTabs, $actor);
            }

            $column->delete();

            /*
             * Two kinds of record, deliberately.
             *
             * Each ticket that had to move out is a movement in its own
             * timeline, written above through TicketActivity — which also puts
             * each one in the workspace feed, marked with the reason, so
             * "everything jumped columns at 4am" is explainable.
             *
             * This is the other half: the column itself going away. Its subject
             * is the board rather than the column, because the column row no
             * longer exists to point at.
             */
            if ($board !== null) {
                $this->workspaceActivity->columnDeleted(
                    $board,
                    $name,
                    $destination,
                    $actor,
                    $multipleTabs ? $column->tab?->name : null,
                );
            }

            $this->broadcaster->structureChanged((int) $column->board_id);
        });
    }

    /**
     * Can this column be deleted without asking the user anything further?
     */
    public function requiresDestination(BoardColumn $column): bool
    {
        return $column->tickets()->exists();
    }

    /** @phpstan-assert BoardColumn $destination */
    private function assertUsableDestination(BoardColumn $column, ?BoardColumn $destination): void
    {
        if (! $destination instanceof BoardColumn) {
            throw new RuntimeException('Choose a column to move the existing tickets into before deleting this one.');
        }

        if ((int) $destination->board_id !== (int) $column->board_id) {
            throw new RuntimeException('Tickets can only be moved to another column on the same board.');
        }

        if ((int) $destination->board_tab_id !== (int) $column->board_tab_id) {
            throw new RuntimeException('Tickets can only be moved to another column in the same tab.');
        }

        if ($destination->is($column)) {
            throw new RuntimeException('Tickets cannot be moved into the column being deleted.');
        }

        if (! BoardColumn::query()->whereKey($destination->getKey())->exists()) {
            throw new RuntimeException('The column chosen for the tickets no longer exists.');
        }
    }

    /**
     * @param  Collection<int, Ticket>  $tickets  the column's tickets, in order
     */
    private function moveTickets(BoardColumn $column, BoardColumn $destination, Collection $tickets, bool $multipleTabs, ?User $actor): void
    {
        // Appended after whatever is already in the destination, keeping the
        // relative order they had in the old column.
        $offset = (int) $destination->tickets()->max('position') + 1;

        $tabName = $multipleTabs ? $column->tab?->name : null;

        foreach ($tickets->values() as $index => $ticket) {
            /*
             * One notification per card would be a notification per card.
             *
             * The observer reads a status change out of the saved diff and
             * cannot tell a person dragging one ticket from this loop tidying a
             * board — so it is told here, on the instances that belong to this
             * loop. The move is still broadcast and still recorded in the
             * timeline, where it says the column was removed.
             */
            $ticket->withoutStatusNotification = true;

            $ticket->forceFill([
                'board_column_id' => $destination->getKey(),
                'position' => $offset + $index,
            ])->save();

            // Ids as well as names, so the time-in-column figures stay right;
            // the reason keeps FlowMetrics from counting a done ticket carried
            // into another done column as closed a second time.
            $this->activity->record($ticket, TicketEventType::TicketMoved, array_filter([
                'from_column' => $column->name,
                'to_column' => $destination->name,
                'from_column_id' => $column->getKey(),
                'to_column_id' => $destination->getKey(),
                'to_done' => (bool) $destination->is_done,
                'from_tab' => $tabName,
                'to_tab' => $tabName,
                'reason' => TicketActivity::REASON_COLUMN_DELETED,
            ], static fn ($value): bool => $value !== null), $actor);
        }

        // Defensive: if anything at all is still pointing at the column, stop
        // rather than let the database decide what happens next.
        if (Ticket::query()->where('board_column_id', $column->getKey())->exists()) {
            throw new RuntimeException('Tickets remain in this column; deletion aborted.');
        }
    }
}
