<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Columns\CreateColumn;
use App\Actions\Columns\DeleteColumn;
use App\Actions\Columns\ReorderColumns;
use App\Actions\Columns\UpdateColumn;
use App\Actions\Labels\CreateLabel;
use App\Actions\Labels\DeleteLabel;
use App\Actions\Labels\UpdateLabel;
use App\Enums\LabelColor;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Label;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Board configuration: columns and labels.
 *
 * Separate from the administrator-only board edit screen because these are
 * everyday workflow changes. Any staff member of the board may make them
 * (BoardPolicy::manageColumns); customers may not reach this screen at all.
 *
 * Columns belong to a tab, so this screen edits one tab's columns at a time —
 * the tab named by ?tab=<slug>, or the board's default tab. Tabs themselves are
 * added, renamed, reordered and removed from the tab strip on the board.
 *
 * Both entry points re-authorize on every action, not just on mount: each
 * Livewire call is its own HTTP request and must not trust what the previous
 * render decided.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    public Board $board;

    /** The tab whose columns are being edited, by slug; '' is the default tab. */
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public string $newColumnName = '';

    public ?int $editingColumnId = null;

    public string $editingColumnName = '';

    /** Column awaiting a delete decision, and where its tickets should go. */
    public ?int $deletingColumnId = null;

    public ?int $destinationColumnId = null;

    public string $newLabelName = '';

    public string $newLabelColor = 'slate';

    public ?int $editingLabelId = null;

    public string $editingLabelName = '';

    public string $editingLabelColor = 'slate';

    public function mount(Board $board): void
    {
        $this->authorize('manageColumns', $board);

        $this->board = $board;

        $this->normaliseTab();
    }

    public function updatedTab(): void
    {
        $this->normaliseTab();

        $this->cancelEditingColumn();
        $this->cancelDeletingColumn();
        $this->newColumnName = '';
    }

    // -----------------------------------------------------------------
    // Columns
    // -----------------------------------------------------------------

    public function addColumn(CreateColumn $createColumn): void
    {
        $this->authorize('manageColumns', $this->board);

        $tab = $this->selectedTab();

        $validated = $this->validate([
            'newColumnName' => [
                'required', 'string', 'max:60',
                Rule::unique('board_columns', 'name')->where('board_tab_id', $tab->getKey()),
            ],
        ], attributes: ['newColumnName' => 'column name']);

        try {
            $createColumn->handle($tab, ['name' => $validated['newColumnName']], auth()->user());
        } catch (RuntimeException $exception) {
            $this->addError('newColumnName', $exception->getMessage());

            return;
        }

        $this->newColumnName = '';

        session()->flash('status', 'Column added.');
    }

    public function startEditingColumn(int $columnId): void
    {
        $column = $this->column($columnId);

        $this->editingColumnId = $column->getKey();
        $this->editingColumnName = $column->name;
        $this->resetValidation();
    }

    public function cancelEditingColumn(): void
    {
        $this->editingColumnId = null;
        $this->editingColumnName = '';
        $this->resetValidation();
    }

    public function saveColumn(UpdateColumn $updateColumn): void
    {
        $this->authorize('manageColumns', $this->board);

        $column = $this->column((int) $this->editingColumnId);

        $validated = $this->validate([
            'editingColumnName' => [
                'required', 'string', 'max:60',
                Rule::unique('board_columns', 'name')
                    ->where('board_tab_id', $column->board_tab_id)
                    ->ignore($column->getKey()),
            ],
        ], attributes: ['editingColumnName' => 'column name']);

        try {
            $updateColumn->handle($column, ['name' => $validated['editingColumnName']], auth()->user());
        } catch (RuntimeException $exception) {
            $this->addError('editingColumnName', $exception->getMessage());

            return;
        }

        $this->cancelEditingColumn();

        session()->flash('status', 'Column renamed.');
    }

    public function toggleDoneColumn(int $columnId, UpdateColumn $updateColumn): void
    {
        $this->authorize('manageColumns', $this->board);

        $column = $this->column($columnId);

        $updateColumn->handle($column, ['is_done' => ! $column->is_done], auth()->user());
    }

    /**
     * Persist a column drag.
     *
     * wire:sort reports only which item moved and where it landed, so the new
     * order is rebuilt here from the tab's current order rather than trusting
     * a list of ids from the browser. ReorderColumns then discards anything
     * that is not actually in this tab.
     */
    public function reorderColumn(int $columnId, int $position, ReorderColumns $reorder): void
    {
        $this->authorize('manageColumns', $this->board);

        $this->column($columnId);

        $tab = $this->selectedTab();

        $ordered = $tab->columns()->pluck('id')->all();
        $ordered = array_values(array_filter($ordered, static fn ($id): bool => (int) $id !== $columnId));

        $index = max(0, min($position, count($ordered)));
        array_splice($ordered, $index, 0, [$columnId]);

        $reorder->handle($tab, $ordered, auth()->user());
    }

    /**
     * Begin deleting a column.
     *
     * A column holding tickets cannot simply be removed: the user is asked
     * where those tickets should go first — another column of the same tab.
     * This only opens the prompt; the decision is made in confirmDeleteColumn().
     */
    public function startDeletingColumn(int $columnId, DeleteColumn $deleteColumn): void
    {
        $this->authorize('manageColumns', $this->board);

        $column = $this->column($columnId);

        $siblings = BoardColumn::query()->where('board_tab_id', $column->board_tab_id);

        if ((clone $siblings)->count() <= 1) {
            session()->flash('error', $this->board->hasMultipleTabs()
                ? 'A tab needs at least one column.'
                : 'A board needs at least one column.');

            return;
        }

        $this->deletingColumnId = $column->getKey();

        $this->destinationColumnId = $deleteColumn->requiresDestination($column)
            ? $siblings->whereKeyNot($column->getKey())->ordered()->value('id')
            : null;
    }

    public function cancelDeletingColumn(): void
    {
        $this->deletingColumnId = null;
        $this->destinationColumnId = null;
    }

    public function confirmDeleteColumn(DeleteColumn $deleteColumn): void
    {
        $this->authorize('manageColumns', $this->board);

        $column = $this->column((int) $this->deletingColumnId);

        // Looked up in the column's own tab, so a forged id cannot send the
        // tickets to another tab or board; DeleteColumn refuses a missing one.
        $destination = $this->destinationColumnId !== null
            ? BoardColumn::query()
                ->where('board_tab_id', $column->board_tab_id)
                ->whereKey($this->destinationColumnId)
                ->first()
            : null;

        try {
            $deleteColumn->handle($column, $destination, auth()->user());
        } catch (RuntimeException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->cancelDeletingColumn();

        session()->flash('status', 'Column deleted.');
    }

    // -----------------------------------------------------------------
    // Labels
    // -----------------------------------------------------------------

    public function addLabel(CreateLabel $createLabel): void
    {
        $this->authorize('manageLabels', $this->board);

        $validated = $this->validate([
            'newLabelName' => [
                'required', 'string', 'max:40',
                Rule::unique('labels', 'name')->where('board_id', $this->board->getKey()),
            ],
            'newLabelColor' => ['required', Rule::enum(LabelColor::class)],
        ], attributes: ['newLabelName' => 'label name']);

        $createLabel->handle($this->board, [
            'name' => $validated['newLabelName'],
            'color' => $validated['newLabelColor'],
        ]);

        $this->newLabelName = '';
        $this->newLabelColor = LabelColor::default()->value;

        session()->flash('status', 'Label created.');
    }

    public function startEditingLabel(int $labelId): void
    {
        $label = $this->label($labelId);

        $this->editingLabelId = $label->getKey();
        $this->editingLabelName = $label->name;
        $this->editingLabelColor = $label->color->value;
        $this->resetValidation();
    }

    public function cancelEditingLabel(): void
    {
        $this->editingLabelId = null;
        $this->editingLabelName = '';
        $this->resetValidation();
    }

    public function saveLabel(UpdateLabel $updateLabel): void
    {
        $this->authorize('manageLabels', $this->board);

        $label = $this->label((int) $this->editingLabelId);

        $validated = $this->validate([
            'editingLabelName' => [
                'required', 'string', 'max:40',
                Rule::unique('labels', 'name')
                    ->where('board_id', $this->board->getKey())
                    ->ignore($label->getKey()),
            ],
            'editingLabelColor' => ['required', Rule::enum(LabelColor::class)],
        ], attributes: ['editingLabelName' => 'label name']);

        $updateLabel->handle($label, [
            'name' => $validated['editingLabelName'],
            'color' => $validated['editingLabelColor'],
        ]);

        $this->cancelEditingLabel();

        session()->flash('status', 'Label updated.');
    }

    public function deleteLabel(int $labelId, DeleteLabel $deleteLabel): void
    {
        $this->authorize('manageLabels', $this->board);

        $deleteLabel->handle($this->label($labelId));

        session()->flash('status', 'Label deleted.');
    }

    // -----------------------------------------------------------------

    /**
     * The tab whose columns this screen is editing.
     */
    private function selectedTab(): BoardTab
    {
        $tab = $this->board->tabBySlug($this->tab) ?? $this->board->defaultTab();

        abort_unless($tab instanceof BoardTab, 404);

        return $tab;
    }

    /**
     * A slug that is not one of this board's tabs falls back to the default
     * tab, and the URL is corrected rather than left naming something else.
     */
    private function normaliseTab(): void
    {
        $tab = $this->board->tabBySlug($this->tab);

        $this->tab = $tab instanceof BoardTab && ! $tab->is($this->board->defaultTab())
            ? (string) $tab->slug
            : '';
    }

    /**
     * Resolve a column id from the browser within the tab being edited.
     *
     * Scoping the lookup to that tab (and so to $this->board) is what stops a
     * swapped id reaching a column of another tab or of somebody else's board;
     * it 404s instead of resolving.
     */
    private function column(int $columnId): BoardColumn
    {
        $column = $this->selectedTab()->columns()->whereKey($columnId)->first();

        abort_unless($column instanceof BoardColumn, 404);

        return $column;
    }

    private function label(int $labelId): Label
    {
        $label = $this->board->labels()->whereKey($labelId)->first();

        abort_unless($label instanceof Label, 404);

        return $label;
    }

    public function render(DeleteColumn $deleteColumn)
    {
        $tab = $this->selectedTab();
        $tabs = $this->board->tabs()->get();

        $columns = $tab->columns()->withCount('tickets')->get();

        $deleting = $this->deletingColumnId !== null
            ? $columns->firstWhere('id', $this->deletingColumnId)
            : null;

        return view('livewire.boards.settings', [
            'columns' => $columns,
            'tabs' => $tabs,
            'selectedTab' => $tab,
            'showTabs' => $tabs->count() > 1,
            'labels' => $this->board->labels()->ordered()->withCount('tickets')->get(),
            'labelColors' => LabelColor::cases(),
            'deletingColumn' => $deleting,
            'deletingColumnHasTickets' => $deleting !== null && $deleting->tickets_count > 0,
            'canManageBoard' => auth()->user()->can('update', $this->board),
        ])->title('Configure '.$this->board->name);
    }
}
