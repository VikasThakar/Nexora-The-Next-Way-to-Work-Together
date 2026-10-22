<?php

declare(strict_types=1);

namespace App\Actions\Tabs;

use App\Actions\Concerns\LocksBoardStructure;
use App\Models\Board;
use App\Models\BoardTab;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite a board's tab order from an ordered list of ids.
 *
 * Works exactly like App\Actions\Columns\ReorderColumns: ids that are not this
 * board's tabs are ignored rather than trusted, tabs the list leaves out keep
 * their relative order at the end, and dropping a tab back where it was is
 * not a change.
 *
 * The leftmost tab is the board's default — where a ticket created without a
 * tab lands and what the board opens on — so this is also how that changes.
 */
class ReorderTabs
{
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function handle(Board $board, array $orderedIds, ?User $actor = null): void
    {
        DB::transaction(function () use ($board, $orderedIds, $actor): void {
            $this->lockBoard((int) $board->getKey());

            $owned = BoardTab::query()
                ->where('board_id', $board->getKey())
                ->ordered()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $requested = array_values(array_unique(array_filter(
                array_map('intval', $orderedIds),
                static fn (int $id): bool => in_array($id, $owned, true)
            )));

            $final = array_merge($requested, array_values(array_diff($owned, $requested)));

            if ($final === [] || $final === $owned) {
                return;
            }

            foreach ($final as $position => $id) {
                BoardTab::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->activity->tabsReordered($board, $actor);
            $this->broadcaster->structureChanged((int) $board->getKey());
        });

        $board->forgetTabs();
    }
}
