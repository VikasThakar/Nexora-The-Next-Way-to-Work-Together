<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Serialise changes to one board's tabs and columns.
 *
 * Takes a row lock on the board itself — the same row CreateTicket locks to
 * hand out the next ticket number — so two people adding, reordering or
 * deleting tabs and columns on the same board at the same moment cannot both
 * pass a check ("is this the last column?", "is that name free?") and then
 * both act on it.
 *
 * Lock order everywhere is board → columns → tickets, which is what keeps a
 * tab deletion and a ticket move from deadlocking each other. Call inside a
 * transaction; SQLite ignores the clause, which is fine for the test suite.
 */
trait LocksBoardStructure
{
    private function lockBoard(int $boardId): void
    {
        DB::table('boards')->where('id', $boardId)->lockForUpdate()->value('id');
    }
}
