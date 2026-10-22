<?php

declare(strict_types=1);

namespace App\Actions\Tabs;

use App\Actions\Concerns\LocksBoardStructure;
use App\Actions\Tabs\Concerns\ChecksTabNames;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BoardBroadcaster;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Add a tab to the right of a board's existing tabs.
 *
 * The new tab starts with a copy of another tab's columns — names, order and
 * which ones count as done — because a team that split its board usually
 * wants the same workflow in each part. From the board that is the tab being
 * looked at; with nothing to copy (or a tab that somehow has no columns) it
 * gets the standard five. Either way it has at least one column, so a ticket
 * can always be created in it.
 *
 * The slug the URL carries is made here, once, from the name, and never
 * changes afterwards — renaming a tab keeps every link to it working.
 */
class CreateTab
{
    use ChecksTabNames;
    use LocksBoardStructure;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BoardBroadcaster $broadcaster,
    ) {}

    public function handle(Board $board, string $name, ?BoardTab $copyColumnsFrom = null, ?User $actor = null): BoardTab
    {
        $name = $this->cleanTabName($name);

        if ($copyColumnsFrom instanceof BoardTab && (int) $copyColumnsFrom->board_id !== (int) $board->getKey()) {
            throw new RuntimeException('That tab belongs to another board.');
        }

        try {
            $tab = DB::transaction(function () use ($board, $name, $copyColumnsFrom, $actor): BoardTab {
                $this->lockBoard((int) $board->getKey());

                $tabs = BoardTab::query()->where('board_id', $board->getKey())->get(['id', 'name', 'slug', 'position']);

                $this->assertTabNameFree($name, $tabs);

                $tab = new BoardTab([
                    'name' => $name,
                    'position' => $tabs->isEmpty() ? 0 : (int) $tabs->max('position') + 1,
                ]);
                $tab->board_id = $board->getKey();
                $tab->slug = $this->uniqueSlug($name, $tabs->pluck('slug')->all());
                $tab->save();

                $this->createColumns($tab, $copyColumnsFrom);

                $this->activity->tabCreated($tab, $actor);
                $this->broadcaster->structureChanged((int) $board->getKey());

                return $tab;
            });
        } catch (UniqueConstraintViolationException) {
            // MySQL's collation can treat two names as one that the check above
            // told apart (accents, for one). Same answer, same words.
            throw $this->duplicateTabName($name);
        }

        $board->forgetTabs();

        return $tab;
    }

    private function createColumns(BoardTab $tab, ?BoardTab $source): void
    {
        $definitions = $source instanceof BoardTab
            ? BoardColumn::query()
                ->where('board_tab_id', $source->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->get(['name', 'is_done'])
                ->map(fn (BoardColumn $column): array => ['name' => (string) $column->name, 'is_done' => (bool) $column->is_done])
                ->all()
            : [];

        if ($definitions === []) {
            $definitions = BoardColumn::defaults();
        }

        foreach (array_values($definitions) as $position => $definition) {
            $column = new BoardColumn([
                'name' => $definition['name'],
                'is_done' => $definition['is_done'],
                'position' => $position,
            ]);
            $column->board_id = $tab->board_id;
            $column->board_tab_id = $tab->getKey();
            $column->save();
        }
    }

    /**
     * "backend-api", or "backend-api-2" when that is taken on this board.
     *
     * @param  array<int, string>  $taken
     */
    private function uniqueSlug(string $name, array $taken): string
    {
        $base = Str::limit(Str::slug($name), 60, '');

        if ($base === '') {
            $base = 'tab';
        }

        $candidate = $base;
        $suffix = 1;

        while (in_array($candidate, $taken, true)) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
