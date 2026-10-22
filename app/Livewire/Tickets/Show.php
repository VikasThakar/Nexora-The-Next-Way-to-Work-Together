<?php

declare(strict_types=1);

namespace App\Livewire\Tickets;

use App\Actions\Tickets\DeleteTicket;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\MoveTicketToTab;
use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Concerns\EditsRichText;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardTab;
use App\Models\Ticket;
use App\Services\BoardAccess;
use App\Services\ContentRenderer;
use App\Services\TicketActivity;
use App\Services\TicketFinder;
use App\Support\RichText\RichText;
use App\Support\TaskList;
use App\Support\TicketPlace;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Ticket detail.
 *
 * A page rather than a modal: a ticket is the thing people link to each other,
 * so it needs a real URL, a back button and a title.
 *
 * The ticket is resolved through TicketFinder, which applies board membership
 * and the customer rule in SQL and raises 404 when either fails. An internal
 * ticket and a nonexistent number are therefore indistinguishable to a
 * customer who guesses at the URL.
 *
 * Livewire rehydrates the model by primary key on every subsequent request,
 * which bypasses that scope, so the view is re-authorized on every render and
 * each write re-authorizes its own ability.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use EditsRichText;
    use ListensForBoardUpdates;

    public Board $board;

    public Ticket $ticket;

    // Main panel
    public bool $editing = false;

    public bool $previewing = false;

    public string $title = '';

    public string $descriptionMd = '';

    // Sidebar fields, saved as they change
    public string $type = '';

    public string $priority = '';

    public string $assigneeId = '';

    public string $estimate = '';

    public string $dueDate = '';

    public string $columnId = '';

    /**
     * The ticket's tab, on a board split into tabs. Changing it moves the
     * ticket to that tab (MoveTicketToTab); the Status choice then lists that
     * tab's columns.
     */
    public string $tabId = '';

    /** Where a tab move put the ticket, said beside the choice. */
    public ?string $tabNotice = null;

    /** @var array<int, int> */
    public array $selectedLabelIds = [];

    public bool $confirmingDelete = false;

    public function mount(Board $board, int $number, TicketFinder $finder): void
    {
        $this->authorize('view', $board);

        $this->board = $board;
        $this->ticket = $finder->findOrFail($board, $number, auth()->user());

        $this->syncFromTicket();
    }

    /**
     * Re-render when somebody else changes something on this board.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->board) ? (int) $this->board->getKey() : null) + [
            /*
             * The checklist panel moved its items into the description.
             *
             * A plain $refresh, like every other listener on this page: the
             * description is re-read from the ticket rather than passed in the
             * event, so nothing can be shown that a fresh render would not
             * have produced.
             */
            'checklist-converted' => '$refresh',
        ];
    }

    private function syncFromTicket(): void
    {
        $this->title = $this->ticket->title;
        $this->descriptionMd = (string) $this->ticket->description_md;
        $this->type = $this->ticket->type->value;
        $this->priority = $this->ticket->priority->value;
        $this->assigneeId = (string) ($this->ticket->assignee_id ?? '');
        $this->estimate = $this->ticket->estimate === null ? '' : (string) $this->ticket->estimate;
        $this->dueDate = $this->ticket->due_date?->format('Y-m-d') ?? '';
        $this->syncPlace();
        $this->selectedLabelIds = $this->ticket->labels()->pluck('labels.id')->all();
    }

    /**
     * Read the ticket's column and tab back from the ticket itself.
     *
     * On every request as well as at mount: Livewire reloads the ticket fresh
     * by key, and if somebody else moved it meanwhile the Status and Tab
     * choices would otherwise go on showing where it used to be — with tabs,
     * a Status list from one tab beside a selection from another. Runs before
     * the browser's change is applied, so a change being made still wins.
     */
    public function hydrate(): void
    {
        if (isset($this->ticket)) {
            $this->syncPlace();
        }
    }

    private function syncPlace(): void
    {
        $this->columnId = (string) $this->ticket->board_column_id;
        $this->tabId = (string) (BoardColumn::query()->whereKey($this->ticket->board_column_id)->value('board_tab_id') ?? '');
    }

    // -----------------------------------------------------------------
    // Main panel
    // -----------------------------------------------------------------

    public function startEditing(): void
    {
        $this->authorize('update', $this->ticket);

        $this->editing = true;
        $this->previewing = false;

        // A fresh editing session starts from the stored document, on the rich
        // surface, with no leftover HTML from a previous one.
        $this->descriptionHtml = '';
        $this->syncFromTicket();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->previewing = false;
        $this->descriptionHtml = '';
        $this->resetValidation();
        $this->syncFromTicket();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    public function save(UpdateTicket $updateTicket, RichText $rich): void
    {
        $this->authorize('update', $this->ticket);

        /*
         * Converted before validation, not after.
         *
         * The length limit has to apply to what will actually be stored. The
         * editor's HTML is several times longer than the Markdown it becomes,
         * so validating the HTML would reject documents that fit comfortably,
         * and validating nothing would let one past the column's limit.
         */
        $this->descriptionMd = $this->markdownFromEditor($rich, $this->ticket->description_md);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'descriptionMd' => ['nullable', 'string', 'max:20000'],
        ], attributes: ['descriptionMd' => 'description']);

        $updateTicket->handle($this->ticket, [
            'title' => $validated['title'],
            'description_md' => $validated['descriptionMd'],
        ], auth()->user());

        $this->ticket->refresh();
        $this->editing = false;
        $this->previewing = false;
        $this->descriptionHtml = '';

        session()->flash('status', 'Ticket updated.');
    }

    /**
     * Tick or untick a checklist item in the description.
     *
     * The client's request was checklists inside the description. CommonMark
     * renders `- [ ]` as a *disabled* checkbox, so without this the boxes would
     * be a picture of a checklist — you would have to open the editor and
     * retype an x to complete a step.
     *
     * Authorized with manageSubtasks rather than update, so it follows exactly
     * the rule the standalone checklist has always followed: a customer may
     * tick items on a request they raised. The index is resolved against the
     * stored text server-side by App\Support\TaskList, so a tampered one ticks
     * a different box on a ticket this person can already edit, or nothing.
     */
    public function toggleDescriptionTask(int $index, UpdateTicket $updateTicket, TaskList $tasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $updated = $tasks->toggle($this->ticket->description_md, $index);

        if ($updated === (string) $this->ticket->description_md) {
            return;
        }

        $updateTicket->handle($this->ticket, ['description_md' => $updated], auth()->user());

        $this->ticket->refresh();
        $this->descriptionMd = (string) $this->ticket->description_md;
    }

    // -----------------------------------------------------------------
    // Sidebar
    // -----------------------------------------------------------------

    public function updatedType(UpdateTicket $updateTicket): void
    {
        $this->authorize('update', $this->ticket);

        $this->validateOnly('type', ['type' => ['required', Rule::enum(TicketType::class)]]);

        $updateTicket->handle($this->ticket, ['type' => $this->type], auth()->user());

        $this->ticket->refresh();
    }

    public function updatedPriority(UpdateTicket $updateTicket): void
    {
        $this->authorize('update', $this->ticket);

        $this->validateOnly('priority', ['priority' => ['required', Rule::enum(TicketPriority::class)]]);

        $updateTicket->handle($this->ticket, ['priority' => $this->priority], auth()->user());

        $this->ticket->refresh();
    }

    public function updatedAssigneeId(UpdateTicket $updateTicket): void
    {
        $this->authorize('assign', $this->ticket);

        $updateTicket->handle($this->ticket, ['assignee_id' => $this->assigneeId ?: null], auth()->user());

        $this->ticket->refresh();
        $this->assigneeId = (string) ($this->ticket->assignee_id ?? '');
    }

    public function updatedEstimate(UpdateTicket $updateTicket): void
    {
        $this->authorize('update', $this->ticket);

        $this->validateOnly('estimate', ['estimate' => ['nullable', 'numeric', 'min:0', 'max:9999']]);

        $updateTicket->handle($this->ticket, ['estimate' => $this->estimate], auth()->user());

        $this->ticket->refresh();
    }

    public function updatedDueDate(UpdateTicket $updateTicket): void
    {
        $this->authorize('update', $this->ticket);

        $this->validateOnly('dueDate', ['dueDate' => ['nullable', 'date']]);

        $updateTicket->handle($this->ticket, ['due_date' => $this->dueDate], auth()->user());

        $this->ticket->refresh();
    }

    public function updatedColumnId(MoveTicket $mover): void
    {
        $this->authorize('move', $this->ticket);

        // A status is a column of the ticket's own tab. Moving to another tab
        // is the Tab choice's job, so a column from elsewhere is not found.
        $column = BoardColumn::query()
            ->where('board_id', $this->board->getKey())
            ->where('board_tab_id', BoardColumn::query()->whereKey($this->ticket->board_column_id)->value('board_tab_id'))
            ->whereKey((int) $this->columnId)
            ->first();

        abort_unless($column instanceof BoardColumn, 404);

        // Appended to the end of the destination column, which is where a
        // status change belongs when it was not a deliberate drag.
        try {
            $mover->handle($this->ticket, $column, PHP_INT_MAX, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('dialog', type: 'error', title: 'The status was not changed', body: $exception->getMessage());
        }

        $this->ticket->refresh();
        $this->syncPlace();
        $this->tabNotice = null;
    }

    /**
     * Move the ticket to another tab of its board.
     *
     * It lands where App\Support\TabColumnMapping says — the column with the
     * same name, else a done column if it was done, else the tab's first
     * column — and the page says where.
     */
    public function updatedTabId(MoveTicketToTab $mover): void
    {
        $this->authorize('move', $this->ticket);

        $tab = $this->board->tabs()->whereKey((int) $this->tabId)->first();

        abort_unless($tab instanceof BoardTab, 404);

        $before = (int) $this->ticket->board_column_id;

        try {
            $column = $mover->handle($this->ticket, $tab, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('dialog', type: 'error', title: 'The ticket was not moved', body: $exception->getMessage());
            $this->ticket->refresh();
            $this->syncPlace();

            return;
        }

        $this->ticket->refresh();
        $this->syncPlace();

        // Choosing the tab the ticket is already in moves nothing (the select
        // reports every change, the same one included), so there is nothing
        // to announce.
        if ((int) $this->ticket->board_column_id === $before) {
            $this->tabNotice = null;

            return;
        }

        $column->setRelation('tab', $tab);
        $this->tabNotice = 'Moved to '.TicketPlace::column($column, true).'.';
    }

    /**
     * Flip between internal and customer-visible.
     *
     * The most sensitive write on a ticket, so it has its own ability and is
     * always written to the timeline by UpdateTicket.
     */
    public function toggleVisibility(UpdateTicket $updateTicket): void
    {
        $this->authorize('changeVisibility', $this->ticket);

        $updateTicket->handle($this->ticket, [
            'customer_visible' => ! $this->ticket->customer_visible,
        ], auth()->user());

        $this->ticket->refresh();

        session()->flash('status', $this->ticket->customer_visible
            ? 'This ticket is now visible to the customer.'
            : 'This ticket is now internal only.');
    }

    public function toggleLabel(int $labelId, SyncTicketLabels $syncLabels): void
    {
        $this->authorize('manageLabels', $this->ticket);

        $selected = collect($this->selectedLabelIds);

        $this->selectedLabelIds = $selected->contains($labelId)
            ? $selected->reject(fn ($id): bool => (int) $id === $labelId)->values()->all()
            : $selected->push($labelId)->values()->all();

        $syncLabels->handle($this->ticket, $this->selectedLabelIds, auth()->user());

        $this->selectedLabelIds = $this->ticket->labels()->pluck('labels.id')->all();
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function confirmDelete(): void
    {
        $this->authorize('delete', $this->ticket);

        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function destroyTicket(DeleteTicket $deleteTicket): void
    {
        $this->authorize('delete', $this->ticket);

        $key = $this->ticket->key();
        $boardUrl = $this->boardUrl();

        $deleteTicket->handle($this->ticket);

        session()->flash('status', $key.' was deleted.');

        // Back to the tab the ticket was in, not wherever the board opens.
        $this->redirect($boardUrl, navigate: true);
    }

    // -----------------------------------------------------------------
    // Attachments dropped into the editor
    // -----------------------------------------------------------------

    protected function uploadOwner(): Ticket
    {
        return $this->ticket;
    }

    protected function uploadBoard(): Board
    {
        $this->ticket->loadMissing('board');

        return $this->ticket->board;
    }

    /**
     * A file added from inside the editor is history, exactly like one added
     * through the attachments panel — same event type, same payload shape, so
     * the timeline does not care which route it came in by.
     */
    protected function recordUpload(Attachment $attachment): void
    {
        app(TicketActivity::class)->record($this->ticket, TicketEventType::AttachmentChanged, [
            'action' => 'added',
            'filename' => $attachment->filename,
        ], auth()->user());
    }

    // -----------------------------------------------------------------

    /**
     * The board, opened on the ticket's tab — the default tab needs no
     * parameter, so the plain board URL stays plain.
     */
    private function boardUrl(): string
    {
        $tab = BoardTab::query()->whereKey(
            BoardColumn::query()->whereKey($this->ticket->board_column_id)->value('board_tab_id')
        )->first();

        $default = $this->board->defaultTab();

        return route('boards.show', array_filter([
            'board' => $this->board,
            'tab' => $tab instanceof BoardTab && ! $tab->is($default) ? $tab->slug : null,
        ]));
    }

    public function render(ContentRenderer $renderer, BoardAccess $access, RichText $rich, TaskList $tasks)
    {
        // Defence in depth: Livewire rehydrates $ticket by primary key, which
        // does not re-apply the visibility scope, so the policy is consulted
        // again on every render rather than only at mount.
        $this->authorize('view', $this->ticket);

        $user = auth()->user();

        $this->ticket->loadMissing(['board', 'assignee', 'creator', 'labels', 'column.tab']);

        $tabs = $this->board->tabs()->get();
        $ticketTab = $this->ticket->column?->tab;

        return view('livewire.tickets.show', [
            // Rendered per viewer, not per string: a ticket reference such as
            // AQD-9 in this description becomes a link only for somebody who
            // may open AQD-9, and stays plain text for everybody else.
            'renderedDescription' => $renderer->render(
                $this->editing ? $this->descriptionMd : $this->ticket->description_md,
                $user,
                $this->ticket->board,
            ),

            /*
             * The document the editor opens with.
             *
             * Rendered by RichText, NOT by ContentRenderer above: that one adds
             * mention highlighting and ticket-reference links per viewer, and
             * feeding a viewer's decorations into an editor would serialize
             * them into the stored source on the next save.
             *
             * Only built while editing, so a page view does not pay for a
             * conversion nobody asked for.
             */
            'editorHtml' => $this->editing ? $this->editorHtml($rich) : '',

            // Checklist items in the description, for the interactive boxes.
            'descriptionTasks' => $tasks->items($this->ticket->description_md),
            'canTickTasks' => $user->can('manageSubtasks', $this->ticket),
            'canAttachInEditor' => $this->canAttachInEditor(),

            // The Status choice: the columns of the ticket's own tab.
            'columns' => $ticketTab instanceof BoardTab ? $ticketTab->columns()->get() : collect(),
            'tabs' => $tabs,
            'ticketTab' => $ticketTab,
            'showTabs' => $tabs->count() > 1,
            'boardUrl' => $this->boardUrl(),
            'boardLabels' => $this->board->labels()->ordered()->get(),
            'assignableMembers' => $this->board->assignableMembers()->orderBy('name')->get(),
            'priorityOptions' => TicketPriority::ordered(),
            'typeOptions' => TicketType::ordered(),
            'canEdit' => $user->can('update', $this->ticket),
            'canMove' => $user->can('move', $this->ticket),
            'canAssign' => $user->can('assign', $this->ticket),
            'canChangeVisibility' => $user->can('changeVisibility', $this->ticket),
            'canManageLabels' => $user->can('manageLabels', $this->ticket),
            'canManageLinks' => $user->can('manageLinks', $this->ticket),
            'canDelete' => $user->can('delete', $this->ticket),
            'canSeeInternal' => $access->canSeeInternalContent($user),
        ])->title($this->ticket->key().' · '.$this->ticket->title);
    }
}
