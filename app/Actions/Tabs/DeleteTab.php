<?php

declare(strict_types=1);

namespace App\Actions\Tabs;

use App\Actions\Concerns\LocksBoardStructure;
use App\Enums\TicketEventType;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use App\Services\TicketActivity;
use App\Support\TabColumnMapping;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Delete a tab without ever destroying the tickets in it.
 *
 * Built like App\Actions\Columns\DeleteColumn, one level up:
 *
 *  1. A board keeps at least one tab.
 *  2. A tab that holds tickets is only deleted with a destination: another
 *     tab of the same board that has at least one column. Its tickets move
 *     there, each to the column TabColumnMapping picks (same name, else done
 *     to done, else the first column), appended at the end in the order they
 *     had — the same rule the delete panel previews.
 *  3. Everything happens in one transaction under the board lock, with the
 *     tab's columns and tickets locked in that order, so a ticket moved or
 *     created into the tab at the same moment cannot slip through.
 *  4. tickets.board_column_id is RESTRICT: if a ticket were ever left behind,
 *     deleting the columns fails rather than taking the ticket with it.
 *
 * Each ticket's move is in its own history with the reason, and with column
 * ids so time-in-column stays right; the reason also keeps a done ticket
 * carried into another done column from counting as closed again. Nobody is
 * notified per card, and Slack is not told about tickets that were already
 * done — this is tidying, not work.
 */
class DeleteTab
{
    use LocksBoardStructure;

    public function __construct(
        private readonly TicketActivity $ticketActivity,
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * @return int how many tickets were moved to the destination
     */
    public function handle(BoardTab $tab, ?BoardTab $destination = null, ?User $actor = null): int
    {
        $tab->loadMissing('board');
        $board = $tab->board;
        $name = (string) $tab->name;

        $moved = DB::transaction(function () use ($tab, $destination, $actor, $board, $name): int {
            $this->lockBoard((int) $tab->board_id);

            if (! BoardTab::query()->whereKey($tab->getKey())->exists()) {
                throw new RuntimeException('That tab has already been removed.');
            }

            if (BoardTab::query()->where('board_id', $tab->board_id)->count() <= 1) {
                throw new RuntimeException('A board needs at least one tab.');
            }

            $columns = BoardColumn::query()
                ->where('board_tab_id', $tab->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tickets = Ticket::query()
                ->whereIn('board_column_id', $columns->modelKeys())
                ->ordered()
                ->lockForUpdate()
                ->get();

            $moved = 0;

            if ($tickets->isNotEmpty()) {
                $this->assertUsableDestination($tab, $destination);
                $moved = $this->moveTickets($tab, $destination, $columns, $tickets, $actor);
            }

            // Explicitly, columns first: the cascade would do it, but the order
            // is then written down rather than left to the engine.
            BoardColumn::query()->where('board_tab_id', $tab->getKey())->delete();
            BoardTab::query()->whereKey($tab->getKey())->delete();

            if ($board instanceof Board) {
                $this->activity->tabDeleted($board, $name, $moved > 0 ? $destination : null, $moved, $actor);
            }

            $this->broadcaster->structureChanged((int) $tab->board_id);

            return $moved;
        });

        $board?->forgetTabs();

        return $moved;
    }

    /**
     * Can this tab be deleted without asking which tab its tickets go to?
     */
    public function requiresDestination(BoardTab $tab): bool
    {
        return Ticket::query()->inTab($tab)->exists();
    }

    /** @phpstan-assert BoardTab $destination */
    private function assertUsableDestination(BoardTab $tab, ?BoardTab $destination): void
    {
        if (! $destination instanceof BoardTab) {
            throw new RuntimeException('Choose a tab to move this tab\'s tickets to before deleting it.');
        }

        if ((int) $destination->board_id !== (int) $tab->board_id) {
            throw new RuntimeException('Tickets can only be moved to another tab on the same board.');
        }

        if ($destination->is($tab)) {
            throw new RuntimeException('Tickets cannot be moved into the tab being deleted.');
        }

        if (! BoardTab::query()->whereKey($destination->getKey())->exists()) {
            throw new RuntimeException('The tab chosen for the tickets no longer exists.');
        }

        if (! BoardColumn::query()->where('board_tab_id', $destination->getKey())->exists()) {
            throw new RuntimeException('The '.$destination->name.' tab has no columns to move the tickets into.');
        }
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns  the tab's columns, in order
     * @param  Collection<int, Ticket>  $tickets  their tickets, in order
     */
    private function moveTickets(BoardTab $tab, BoardTab $destination, Collection $columns, Collection $tickets, ?User $actor): int
    {
        $tab->setRelation('columns', $columns);
        $destination->setRelation('columns', $destination->columns()->get());

        $map = TabColumnMapping::map($tab, $destination);

        // Each target column takes the tickets after whatever it already holds.
        $next = [];

        foreach ($map as $entry) {
            $targetId = (int) $entry['column']->getKey();
            $next[$targetId] ??= (int) Ticket::query()->where('board_column_id', $targetId)->max('position') + 1;
        }

        $byColumn = $tickets->groupBy('board_column_id');
        $moved = 0;

        foreach ($columns as $column) {
            $target = $map[(int) $column->getKey()]['column'] ?? null;

            if (! $target instanceof BoardColumn) {
                continue;
            }

            foreach ($byColumn->get($column->getKey(), []) as $ticket) {
                // Tidying, not work: no bell per card, no Slack per done ticket.
                $ticket->withoutStatusNotification = true;

                $ticket->forceFill([
                    'board_column_id' => $target->getKey(),
                    'position' => $next[(int) $target->getKey()]++,
                ])->save();

                $this->ticketActivity->record($ticket, TicketEventType::TicketMoved, [
                    'from_column' => $column->name,
                    'to_column' => $target->name,
                    'from_column_id' => $column->getKey(),
                    'to_column_id' => $target->getKey(),
                    'to_done' => (bool) $target->is_done,
                    'from_tab' => $tab->name,
                    'to_tab' => $destination->name,
                    'reason' => TicketActivity::REASON_TAB_DELETED,
                ], $actor);

                $moved++;
            }
        }

        // Defensive: if anything at all still points into this tab, stop
        // rather than let the database decide what happens next.
        if (Ticket::query()->whereIn('board_column_id', $columns->modelKeys())->exists()) {
            throw new RuntimeException('Tickets remain in this tab; deletion aborted.');
        }

        return $moved;
    }
}
