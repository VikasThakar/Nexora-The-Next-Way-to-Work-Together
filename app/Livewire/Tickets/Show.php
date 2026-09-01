<?php

declare(strict_types=1);

namespace App\Livewire\Tickets;

use App\Actions\Tickets\DeleteTicket;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketPriority;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Services\BoardAccess;
use App\Services\ContentRenderer;
use App\Services\TicketFinder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

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
    use ListensForBoardUpdates;

    public Board $board;

    public Ticket $ticket;

    // Main panel
    public bool $editing = false;

    public bool $previewing = false;

    public string $title = '';

    public string $descriptionMd = '';

    // Sidebar fields, saved as they change
    public string $priority = '';

    public string $assigneeId = '';

    public string $estimate = '';

    public string $dueDate = '';

    public string $columnId = '';

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
        return $this->boardUpdateListeners(isset($this->board) ? (int) $this->board->getKey() : null);
    }

    private function syncFromTicket(): void
    {
        $this->title = $this->ticket->title;
        $this->descriptionMd = (string) $this->ticket->description_md;
        $this->priority = $this->ticket->priority->value;
        $this->assigneeId = (string) ($this->ticket->assignee_id ?? '');
        $this->estimate = $this->ticket->estimate === null ? '' : (string) $this->ticket->estimate;
        $this->dueDate = $this->ticket->due_date?->format('Y-m-d') ?? '';
        $this->columnId = (string) $this->ticket->board_column_id;
        $this->selectedLabelIds = $this->ticket->labels()->pluck('labels.id')->all();
    }

    // -----------------------------------------------------------------
    // Main panel
    // -----------------------------------------------------------------

    public function startEditing(): void
    {
        $this->authorize('update', $this->ticket);

        $this->editing = true;
        $this->previewing = false;
        $this->syncFromTicket();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->previewing = false;
        $this->resetValidation();
        $this->syncFromTicket();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    public function save(UpdateTicket $updateTicket): void
    {
        $this->authorize('update', $this->ticket);

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

        session()->flash('status', 'Ticket updated.');
    }

    // -----------------------------------------------------------------
    // Sidebar
    // -----------------------------------------------------------------

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

        $column = $this->board->columns()->whereKey((int) $this->columnId)->first();

        abort_unless($column instanceof BoardColumn, 404);

        // Appended to the end of the destination column, which is where a
        // status change belongs when it was not a deliberate drag.
        $mover->handle($this->ticket, $column, PHP_INT_MAX, auth()->user());

        $this->ticket->refresh();
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

        $deleteTicket->handle($this->ticket);

        session()->flash('status', $key.' was deleted.');

        $this->redirect(route('boards.show', $this->board), navigate: true);
    }

    // -----------------------------------------------------------------

    public function render(ContentRenderer $renderer, BoardAccess $access)
    {
        // Defence in depth: Livewire rehydrates $ticket by primary key, which
        // does not re-apply the visibility scope, so the policy is consulted
        // again on every render rather than only at mount.
        $this->authorize('view', $this->ticket);

        $user = auth()->user();

        $this->ticket->loadMissing(['board', 'assignee', 'creator', 'labels', 'column']);

        return view('livewire.tickets.show', [
            // Rendered per viewer, not per string: a ticket reference such as
            // AQD-9 in this description becomes a link only for somebody who
            // may open AQD-9, and stays plain text for everybody else.
            'descriptionHtml' => $renderer->render(
                $this->editing ? $this->descriptionMd : $this->ticket->description_md,
                $user,
                $this->ticket->board,
            ),
            'columns' => $this->board->columns()->ordered()->get(),
            'boardLabels' => $this->board->labels()->ordered()->get(),
            'assignableMembers' => $this->board->assignableMembers()->orderBy('name')->get(),
            'priorityOptions' => TicketPriority::ordered(),
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
