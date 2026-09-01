<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Enums\TicketEventType;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Delete a column without ever destroying the tickets in it.
 *
 * Three things have to line up for that guarantee to hold:
 *
 *  1. This action refuses to run when the column still holds tickets and no
 *     destination was named.
 *  2. The destination is verified to be a different column on the same board,
 *     so a hostile id cannot move tickets onto someone else's board.
 *  3. tickets.board_column_id is restrictOnDelete, so if either check were ever
 *     bypassed the database would reject the delete rather than cascade it.
 *
 * The move is recorded in each ticket's history, because "my ticket is suddenly
 * in a different column" should be explainable afterwards.
 */
class DeleteColumn
{
    public function __construct(private readonly TicketActivity $activity) {}

    public function handle(BoardColumn $column, ?BoardColumn $destination = null, ?User $actor = null): void
    {
        $ticketCount = $column->tickets()->count();

        if ($ticketCount > 0) {
            $this->assertUsableDestination($column, $destination);
        }

        DB::transaction(function () use ($column, $destination, $ticketCount, $actor): void {
            if ($ticketCount > 0 && $destination instanceof BoardColumn) {
                $this->moveTickets($column, $destination, $actor);
            }

            $column->delete();
        });
    }

    /**
     * Can this column be deleted without asking the user anything further?
     */
    public function requiresDestination(BoardColumn $column): bool
    {
        return $column->tickets()->exists();
    }

    private function assertUsableDestination(BoardColumn $column, ?BoardColumn $destination): void
    {
        if (! $destination instanceof BoardColumn) {
            throw new RuntimeException('Choose a column to move the existing tickets into before deleting this one.');
        }

        if ($destination->board_id !== $column->board_id) {
            throw new RuntimeException('Tickets can only be moved to another column on the same board.');
        }

        if ($destination->is($column)) {
            throw new RuntimeException('Tickets cannot be moved into the column being deleted.');
        }
    }

    private function moveTickets(BoardColumn $column, BoardColumn $destination, ?User $actor): void
    {
        // Appended after whatever is already in the destination, keeping the
        // relative order they had in the old column.
        $offset = (int) $destination->tickets()->max('position') + 1;

        $tickets = $column->tickets()->ordered()->get();

        foreach ($tickets as $index => $ticket) {
            $ticket->forceFill([
                'board_column_id' => $destination->getKey(),
                'position' => $offset + $index,
            ])->save();

            $this->activity->record($ticket, TicketEventType::TicketMoved, [
                'from_column' => $column->name,
                'to_column' => $destination->name,
                'reason' => 'column_deleted',
            ], $actor);
        }

        // Defensive: if anything at all is still pointing at the column, stop
        // rather than let the database decide what happens next.
        if (Ticket::query()->where('board_column_id', $column->getKey())->exists()) {
            throw new RuntimeException('Tickets remain in this column; deletion aborted.');
        }
    }
}
