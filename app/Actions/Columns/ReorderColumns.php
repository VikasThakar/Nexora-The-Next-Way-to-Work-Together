<?php

declare(strict_types=1);

namespace App\Actions\Columns;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

class ReorderColumns
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Rewrite column order from an ordered list of ids.
     *
     * Ids that do not belong to this board are ignored rather than trusted:
     * the list arrives from the browser, so it is treated as a request rather
     * than an instruction. Any column omitted from the list keeps its relative
     * order at the end, so a stale payload can reorder but never orphan.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function handle(Board $board, array $orderedIds): void
    {
        $owned = $board->columns()->ordered()->pluck('id')->all();

        $requested = array_values(array_filter(
            array_map('intval', $orderedIds),
            static fn (int $id): bool => in_array($id, $owned, true)
        ));

        // Anything the client did not mention keeps its existing relative order.
        $remaining = array_values(array_diff($owned, $requested));

        $final = array_merge($requested, $remaining);

        if ($final === []) {
            return;
        }

        // Dropping a column back where it came from is not a change. The
        // settings screen calls this on every drop, so without the comparison
        // the feed would fill with reorderings that reordered nothing.
        if ($final === $owned) {
            return;
        }

        DB::transaction(function () use ($final): void {
            foreach ($final as $position => $id) {
                BoardColumn::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        $this->activity->columnsReordered($board);
    }
}
