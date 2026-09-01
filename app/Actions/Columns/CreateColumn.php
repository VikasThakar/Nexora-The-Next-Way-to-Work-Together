<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Models\Board;
use App\Models\BoardColumn;

class CreateColumn
{
    /**
     * @param  array{name: string, is_done?: bool}  $attributes
     */
    public function handle(Board $board, array $attributes): BoardColumn
    {
        // New columns land at the end. Position is a sparse integer, so taking
        // max+1 is enough and avoids rewriting the whole board.
        $position = (int) $board->columns()->max('position');

        return $board->columns()->create([
            'name' => trim($attributes['name']),
            'is_done' => (bool) ($attributes['is_done'] ?? false),
            'position' => $board->columns()->exists() ? $position + 1 : 0,
        ]);
    }
}
