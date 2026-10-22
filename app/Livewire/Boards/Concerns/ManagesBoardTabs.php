<?php

declare(strict_types=1);

namespace App\Livewire\Boards\Concerns;

use App\Actions\Tabs\CreateTab;
use App\Actions\Tabs\DeleteTab;
use App\Actions\Tabs\RenameTab;
use App\Actions\Tabs\ReorderTabs;
use App\Models\Board;
use App\Models\BoardTab;
use App\Support\TabColumnMapping;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adding, renaming, reordering and removing a board's tabs from the tab strip
 * on the board itself — the Chrome-like way: "+" adds a tab next to the
 * others, and the tab menu renames, moves or removes the one on screen.
 *
 * Every method re-authorizes (BoardPolicy::manageTabs — the same people who
 * may change the board's columns) and resolves every tab id within this board,
 * 404 otherwise. The rules themselves (names, the last tab, where tickets go)
 * live in App\Actions\Tabs\*, so they hold however a tab is changed.
 *
 * @property Board $board
 * @property string|null $notice
 */
trait ManagesBoardTabs
{
    /** The tab awaiting a delete decision, and the tab its tickets should go to. */
    public ?int $deletingTabId = null;

    public ?int $destinationTabId = null;

    abstract protected function activeTab(): ?BoardTab;

    abstract protected function switchTo(?BoardTab $tab): void;

    abstract protected function leaveTab(): void;

    /**
     * Add a tab to the right of the others, with the same columns as the tab
     * on screen, and open it.
     */
    public function createTab(string $name, CreateTab $create): void
    {
        $this->authorize('manageTabs', $this->board);
        $this->resetErrorBag('tabName');

        $source = $this->activeTab();

        try {
            $tab = $create->handle($this->board, $name, $source, auth()->user());
        } catch (RuntimeException $exception) {
            $this->addError('tabName', $exception->getMessage());

            return;
        }

        $this->board->forgetTabs();
        $this->leaveTab();
        $this->switchTo($tab);

        $this->notice = $source instanceof BoardTab
            ? 'Added the '.$tab->name.' tab, with the same columns as '.$source->name.'.'
            : 'Added the '.$tab->name.' tab.';
    }

    public function renameTab(int $tabId, string $name, RenameTab $rename): void
    {
        $this->authorize('manageTabs', $this->board);
        $this->resetErrorBag('tabName');

        $tab = $this->tabOnBoard($tabId);

        try {
            $rename->handle($tab, $name, auth()->user());
        } catch (RuntimeException $exception) {
            $this->addError('tabName', $exception->getMessage());

            return;
        }

        $this->board->forgetTabs();
    }

    /**
     * Persist a tab drag on the strip.
     *
     * Like the column drag in Settings: only "this tab, now at this index"
     * arrives, and the new order is rebuilt from the board's current order
     * rather than trusting a list of ids from the browser.
     */
    public function reorderTab(int $tabId, int $position, ReorderTabs $reorder): void
    {
        $this->authorize('manageTabs', $this->board);

        $this->tabOnBoard($tabId);

        $ordered = $this->board->tabs()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ordered = array_values(array_filter($ordered, static fn (int $id): bool => $id !== $tabId));

        array_splice($ordered, max(0, min($position, count($ordered))), 0, [$tabId]);

        $reorder->handle($this->board, $ordered, auth()->user());

        $this->board->forgetTabs();
    }

    /**
     * Move a tab one place left (-1) or right (+1) — the keyboard's way of
     * doing what dragging does.
     */
    public function moveTab(int $tabId, int $offset, ReorderTabs $reorder): void
    {
        $this->authorize('manageTabs', $this->board);

        $this->tabOnBoard($tabId);

        $ordered = $this->board->tabs()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $index = array_search($tabId, $ordered, true);

        if ($index === false) {
            return;
        }

        $this->reorderTab($tabId, max(0, $index + ($offset < 0 ? -1 : 1)), $reorder);
    }

    /**
     * Open the delete panel for a tab. Nothing is removed until the panel is
     * confirmed, and the panel says exactly where every ticket will go.
     */
    public function startDeletingTab(int $tabId): void
    {
        $this->authorize('manageTabs', $this->board);
        $this->resetErrorBag('tabName');

        $tab = $this->tabOnBoard($tabId);
        $tabs = $this->board->tabs()->get();

        if ($tabs->count() <= 1) {
            $this->addError('tabName', 'A board needs at least one tab.');

            return;
        }

        $this->deletingTabId = $tab->getKey();

        // The first other tab, so the panel opens with a real choice made.
        $this->destinationTabId = $tabs->first(fn (BoardTab $other): bool => ! $other->is($tab))?->getKey();
    }

    public function cancelDeletingTab(): void
    {
        $this->deletingTabId = null;
        $this->destinationTabId = null;
    }

    public function confirmDeleteTab(DeleteTab $delete): void
    {
        $this->authorize('manageTabs', $this->board);

        $tab = $this->tabOnBoard((int) $this->deletingTabId);

        // Looked up within the board, so a forged id resolves to nothing and
        // DeleteTab refuses rather than moving tickets somewhere unexpected.
        $destination = $this->destinationTabId !== null
            ? $this->board->tabs()->whereKey($this->destinationTabId)->first()
            : null;

        $wasOnScreen = $tab->is($this->activeTab());
        $name = (string) $tab->name;

        try {
            $moved = $delete->handle($tab, $destination, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('dialog', type: 'error', title: 'The tab was not removed', body: $exception->getMessage());

            return;
        }

        $this->board->forgetTabs();
        $this->cancelDeletingTab();

        if ($wasOnScreen) {
            $this->leaveTab();
            $this->switchTo($moved > 0 ? $destination : null);
        }

        $this->notice = $moved > 0 && $destination instanceof BoardTab
            ? 'Removed the '.$name.' tab. Its '.$moved.' '.Str::plural('ticket', $moved).' moved to '.$destination->name.'.'
            : 'Removed the '.$name.' tab.';
    }

    /**
     * What the delete panel shows: the tab, how many tickets it holds, and —
     * once a destination is chosen — where each column's tickets will land,
     * from the same mapping DeleteTab uses.
     *
     * @return array<string, mixed>
     */
    protected function deleteTabPanel(): array
    {
        $tab = $this->deletingTabId !== null
            ? $this->board->tabs()->whereKey($this->deletingTabId)->first()
            : null;

        if (! $tab instanceof BoardTab) {
            return ['deletingTab' => null, 'deletingTabTickets' => 0, 'deletionMap' => []];
        }

        $columns = $tab->columns()->withCount('tickets')->get();
        $tab->setRelation('columns', $columns);

        $tickets = (int) $columns->sum('tickets_count');

        $destination = $this->destinationTabId !== null
            ? $this->board->tabs()->whereKey($this->destinationTabId)->first()
            : null;

        $rows = [];

        if ($tickets > 0 && $destination instanceof BoardTab && ! $destination->is($tab)) {
            $map = TabColumnMapping::map($tab, $destination);

            foreach ($columns as $column) {
                if ((int) $column->tickets_count === 0 || ! isset($map[$column->getKey()])) {
                    continue;
                }

                $rows[] = [
                    'from' => (string) $column->name,
                    'count' => (int) $column->tickets_count,
                    'to' => (string) $map[$column->getKey()]['column']->name,
                    'reason' => $map[$column->getKey()]['reason'],
                ];
            }
        }

        return [
            'deletingTab' => $tab,
            'deletingTabTickets' => $tickets,
            'deletionMap' => $rows,
        ];
    }

    /**
     * A tab id from the browser, resolved within this board — 404 for
     * anything else, so a swapped id never reaches another board's tab.
     */
    protected function tabOnBoard(int $tabId): BoardTab
    {
        $tab = $this->board->tabs()->whereKey($tabId)->first();

        abort_unless($tab instanceof BoardTab, 404);

        return $tab;
    }
}
