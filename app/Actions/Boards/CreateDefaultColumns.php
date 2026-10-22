<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Give a tab the standard workflow: Backlog, To Do, In Progress, Review, Done.
 *
 * With no tab given it works on the board's default tab, creating the "Main"
 * tab first when the board has none — which is how every new board gets its
 * one tab and its five columns.
 *
 * Idempotent per tab, so it can also be used to repair a tab that somehow
 * ended up with no columns without duplicating the ones it already has.
 *
 * Deliberately silent: no activity and no broadcast. Creating a board is the
 * event worth recording (App\Actions\Boards\CreateBoard does), and its
 * starting tab and columns are part of that, not changes of their own.
 */
class CreateDefaultColumns
{
    /** @return Collection<int, BoardColumn> */
    public function handle(Board $board, ?BoardTab $tab = null): Collection
    {
        if ($tab instanceof BoardTab && (int) $tab->board_id !== (int) $board->getKey()) {
            throw new RuntimeException('That tab belongs to another board.');
        }

        $tab ??= $board->defaultTab() ?? $this->createMainTab($board);

        if ($tab->columns()->exists()) {
            return $tab->columns()->get();
        }

        $position = 0;

        foreach (BoardColumn::defaults() as $definition) {
            $column = new BoardColumn([
                'name' => $definition['name'],
                'is_done' => $definition['is_done'],
                'position' => $position++,
            ]);
            $column->board_id = $board->getKey();
            $column->board_tab_id = $tab->getKey();
            $column->save();
        }

        return $tab->columns()->get();
    }

    private function createMainTab(Board $board): BoardTab
    {
        $tab = new BoardTab(['name' => BoardTab::DEFAULT_NAME, 'position' => 0]);
        $tab->board_id = $board->getKey();
        $tab->slug = 'main';
        $tab->save();

        $board->forgetTabs();

        return $tab;
    }
}
