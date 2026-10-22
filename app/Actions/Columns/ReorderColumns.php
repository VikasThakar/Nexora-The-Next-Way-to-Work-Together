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

class ReorderColumns
{
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * Rewrite the column order of one tab from an ordered list of ids.
     *
     * Ids that do not belong to this tab are ignored rather than trusted: the
     * list arrives from the browser, so it is treated as a request rather than
     * an instruction. Any column omitted from the list keeps its relative
     * order at the end, so a stale payload can reorder but never orphan.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function handle(BoardTab $tab, array $orderedIds, ?User $actor = null): void
    {
        DB::transaction(function () use ($tab, $orderedIds, $actor): void {
            $this->lockBoard((int) $tab->board_id);

            $owned = $tab->columns()->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $requested = array_values(array_unique(array_filter(
                array_map('intval', $orderedIds),
                static fn (int $id): bool => in_array($id, $owned, true)
            )));

            // Anything the client did not mention keeps its existing relative order.
            $remaining = array_values(array_diff($owned, $requested));

            $final = array_merge($requested, $remaining);

            // Dropping a column back where it came from is not a change. The
            // settings screen calls this on every drop, so without the comparison
            // the feed would fill with reorderings that reordered nothing.
            if ($final === [] || $final === $owned) {
                return;
            }

            foreach ($final as $position => $id) {
                BoardColumn::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->activity->columnsReordered($tab, $actor);
            $this->broadcaster->structureChanged((int) $tab->board_id);
        });
    }
}
