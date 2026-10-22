<?php

declare(strict_types=1);

namespace App\Actions\Tabs;

use App\Actions\Concerns\LocksBoardStructure;
use App\Actions\Tabs\Concerns\ChecksTabNames;
use App\Models\BoardTab;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Rename a tab. Its slug — what links to the tab carry — stays as it was, so
 * nothing that points at the tab breaks.
 */
class RenameTab
{
    use ChecksTabNames;
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    public function handle(BoardTab $tab, string $name, ?User $actor = null): BoardTab
    {
        $name = $this->cleanTabName($name);

        // The same name is not a change. A change of case is, and is allowed.
        if ($name === $tab->name) {
            return $tab;
        }

        try {
            DB::transaction(function () use ($tab, $name, $actor): void {
                $this->lockBoard((int) $tab->board_id);

                $others = BoardTab::query()
                    ->where('board_id', $tab->board_id)
                    ->whereKeyNot($tab->getKey())
                    ->get(['id', 'name']);

                $this->assertTabNameFree($name, $others);

                $from = (string) $tab->name;

                $tab->name = $name;
                $tab->save();

                $this->activity->tabRenamed($tab, $from, $actor);
                $this->broadcaster->structureChanged((int) $tab->board_id);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateTabName($name);
        }

        if ($tab->relationLoaded('board')) {
            $tab->board?->forgetTabs();
        }

        return $tab;
    }
}
