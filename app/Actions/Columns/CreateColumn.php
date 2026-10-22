<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Actions\Concerns\LocksBoardStructure;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateColumn
{
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * Add a column to the end of one tab.
     *
     * @param  array{name: string, is_done?: bool}  $attributes
     */
    public function handle(BoardTab $tab, array $attributes, ?User $actor = null): BoardColumn
    {
        $name = trim((string) $attributes['name']);

        return DB::transaction(function () use ($tab, $attributes, $name, $actor): BoardColumn {
            $this->lockBoard((int) $tab->board_id);

            $siblings = BoardColumn::query()->where('board_tab_id', $tab->getKey())->get(['name', 'position']);

            // Names are unique per tab, ignoring case — the database's own rule
            // on MySQL, checked here so SQLite and the error message agree.
            if ($siblings->contains(fn (BoardColumn $other): bool => mb_strtolower(trim((string) $other->name)) === mb_strtolower($name))) {
                throw new RuntimeException('This tab already has a column called "'.$name.'".');
            }

            // New columns land at the end of their tab. Position is a sparse
            // integer, so taking max+1 is enough and avoids rewriting the tab.
            $column = new BoardColumn([
                'name' => $name,
                'is_done' => (bool) ($attributes['is_done'] ?? false),
                'position' => $siblings->isEmpty() ? 0 : (int) $siblings->max('position') + 1,
            ]);
            $column->board_id = $tab->board_id;
            $column->board_tab_id = $tab->getKey();
            $column->save();

            // Changing the shape of a board's workflow is a board-level decision
            // and belongs in the activity feed alongside its other settings — the
            // column names are what every ticket movement then reads as.
            $this->activity->columnCreated($column, $actor);

            // Open boards redraw: a new column is part of what everyone sees.
            $this->broadcaster->structureChanged((int) $tab->board_id);

            return $column;
        });
    }
}
