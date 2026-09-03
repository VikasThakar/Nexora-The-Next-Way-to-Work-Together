<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Services\ActivityLogger;

class CreateColumn
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param  array{name: string, is_done?: bool}  $attributes
     */
    public function handle(Board $board, array $attributes): BoardColumn
    {
        // New columns land at the end. Position is a sparse integer, so taking
        // max+1 is enough and avoids rewriting the whole board.
        $position = (int) $board->columns()->max('position');

        $column = $board->columns()->create([
            'name' => trim($attributes['name']),
            'is_done' => (bool) ($attributes['is_done'] ?? false),
            'position' => $board->columns()->exists() ? $position + 1 : 0,
        ]);

        // Changing the shape of a board's workflow is a board-level decision
        // and belongs in the activity feed alongside its other settings — the
        // column names are what every ticket movement then reads as.
        $this->activity->columnCreated($column);

        return $column;
    }
}
