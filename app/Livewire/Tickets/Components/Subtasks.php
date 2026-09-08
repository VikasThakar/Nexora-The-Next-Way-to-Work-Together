<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Actions\Tickets\ConvertSubtasksToChecklist;
use App\Actions\Tickets\ManageSubtasks;
use App\Models\Ticket;
use App\Models\TicketSubtask;
use Livewire\Component;

/**
 * The checklist on a ticket.
 *
 * Every action re-authorizes against the parent ticket, and every subtask id
 * coming from the browser is resolved through $this->ticket->subtasks(), so an
 * id belonging to a different ticket resolves to nothing rather than to
 * somebody else's checklist.
 */
class Subtasks extends Component
{
    public Ticket $ticket;

    public string $newTitle = '';

    public ?int $editingId = null;

    public string $editingTitle = '';

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticket = $ticket;
    }

    public function add(ManageSubtasks $subtasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $validated = $this->validate([
            'newTitle' => ['required', 'string', 'max:200'],
        ], attributes: ['newTitle' => 'subtask']);

        $subtasks->add($this->ticket, $validated['newTitle'], auth()->user());

        $this->newTitle = '';
    }

    public function toggle(int $subtaskId, ManageSubtasks $subtasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $subtask = $this->subtask($subtaskId);

        $subtasks->setCompleted($this->ticket, $subtask, ! $subtask->completed, auth()->user());
    }

    public function startEditing(int $subtaskId): void
    {
        $subtask = $this->subtask($subtaskId);

        $this->editingId = $subtask->getKey();
        $this->editingTitle = $subtask->title;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->editingTitle = '';
        $this->resetValidation();
    }

    public function saveEditing(ManageSubtasks $subtasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $validated = $this->validate([
            'editingTitle' => ['required', 'string', 'max:200'],
        ], attributes: ['editingTitle' => 'subtask']);

        $subtasks->rename($this->ticket, $this->subtask((int) $this->editingId), $validated['editingTitle']);

        $this->cancelEditing();
    }

    public function remove(int $subtaskId, ManageSubtasks $subtasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $subtasks->delete($this->ticket, $this->subtask($subtaskId));
    }

    /**
     * Persist a subtask drag.
     *
     * wire:sort reports the moved item and its new index, so the order is
     * rebuilt server-side from the current order rather than from a list of
     * ids supplied by the browser.
     */
    public function reorder(int $subtaskId, int $position, ManageSubtasks $subtasks): void
    {
        $this->authorize('manageSubtasks', $this->ticket);

        $this->subtask($subtaskId);

        $ordered = $this->ticket->subtasks()->pluck('id')->all();
        $ordered = array_values(array_filter($ordered, static fn ($id): bool => (int) $id !== $subtaskId));

        $index = max(0, min($position, count($ordered)));
        array_splice($ordered, $index, 0, [$subtaskId]);

        $subtasks->reorder($this->ticket, $ordered);
    }

    /**
     * Move this checklist into the ticket description.
     *
     * The transition path for the client's "checklists inside the editor"
     * request. Authorized with manageSubtasks *and* update, because it does two
     * things: it removes the checklist rows and it rewrites the description.
     * Somebody allowed to tick items is not automatically allowed to edit the
     * description, and this must not be a way around that.
     *
     * The parent page owns the description, so it is asked to re-render rather
     * than this component guessing at what changed.
     */
    public function convertToChecklist(ConvertSubtasksToChecklist $convert): void
    {
        $this->authorize('manageSubtasks', $this->ticket);
        $this->authorize('update', $this->ticket);

        if ($this->ticket->subtasks()->doesntExist()) {
            return;
        }

        $convert->handle($this->ticket, auth()->user());

        $this->ticket->refresh();

        session()->flash('status', 'The checklist is now part of the description.');

        $this->dispatch('checklist-converted');
    }

    private function subtask(int $subtaskId): TicketSubtask
    {
        $subtask = $this->ticket->subtasks()->whereKey($subtaskId)->first();

        abort_unless($subtask instanceof TicketSubtask, 404);

        return $subtask;
    }

    public function render()
    {
        $subtasks = $this->ticket->subtasks()->get();

        $user = auth()->user();

        return view('livewire.tickets.components.subtasks', [
            'subtasks' => $subtasks,
            'completedCount' => $subtasks->where('completed', true)->count(),
            'canManage' => $user->can('manageSubtasks', $this->ticket),
            // Both abilities, matching convertToChecklist().
            'canConvert' => $subtasks->isNotEmpty()
                && $user->can('manageSubtasks', $this->ticket)
                && $user->can('update', $this->ticket),
        ]);
    }
}
