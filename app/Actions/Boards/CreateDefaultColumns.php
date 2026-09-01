<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Models\Board;
use App\Models\BoardColumn;
use Illuminate\Support\Collection;

/**
 * Give a board the standard workflow: Backlog, To Do, In Progress, Review, Done.
 *
 * Idempotent, so it can also be used to repair a board that somehow ended up
 * with no columns without duplicating the ones it already has.
 */
class CreateDefaultColumns
{
    /** @return Collection<int, BoardColumn> */
    public function handle(Board $board): Collection
    {
        if ($board->columns()->exists()) {
            return $board->columns()->ordered()->get();
        }

        $position = 0;

        foreach (BoardColumn::defaults() as $definition) {
            $board->columns()->create([
                'name' => $definition['name'],
                'is_done' => $definition['is_done'],
                'position' => $position++,
            ]);
        }

        return $board->columns()->ordered()->get();
    }
}
