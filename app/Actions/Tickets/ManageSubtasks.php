<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Checklist operations for a ticket.
 *
 * Grouped into one action because they share the ordering rules and are always
 * authorized together (TicketPolicy::manageSubtasks). Every method takes the
 * parent ticket explicitly and verifies the subtask belongs to it, so a
 * tampered subtask id cannot reach a checklist on another ticket.
 *
 * Subtasks are not written to the ticket timeline: a checklist changes often
 * and would drown the events that matter.
 */
class ManageSubtasks
{
    public function add(Ticket $ticket, string $title, ?User $actor = null): TicketSubtask
    {
        $position = $ticket->subtasks()->exists()
            ? (int) $ticket->subtasks()->max('position') + 1
            : 0;

        return $ticket->subtasks()->create([
            'title' => trim($title),
            'completed' => false,
            'position' => $position,
        ]);
    }

    public function rename(Ticket $ticket, TicketSubtask $subtask, string $title): TicketSubtask
    {
        $this->assertBelongsTo($ticket, $subtask);

        $subtask->title = trim($title);
        $subtask->save();

        return $subtask;
    }

    public function setCompleted(Ticket $ticket, TicketSubtask $subtask, bool $completed, ?User $actor = null): TicketSubtask
    {
        $this->assertBelongsTo($ticket, $subtask);

        $subtask->completed = $completed;
        $subtask->completed_at = $completed ? now() : null;
        $subtask->completed_by_id = $completed ? $actor?->getKey() : null;
        $subtask->save();

        return $subtask;
    }

    public function delete(Ticket $ticket, TicketSubtask $subtask): void
    {
        $this->assertBelongsTo($ticket, $subtask);

        $subtask->delete();
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(Ticket $ticket, array $orderedIds): void
    {
        $owned = $ticket->subtasks()->pluck('id')->all();

        $requested = array_values(array_filter(
            array_map('intval', $orderedIds),
            static fn (int $id): bool => in_array($id, $owned, true)
        ));

        $final = array_merge($requested, array_values(array_diff($owned, $requested)));

        DB::transaction(function () use ($final): void {
            foreach ($final as $position => $id) {
                TicketSubtask::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * A subtask id from a form must belong to the ticket being edited.
     */
    private function assertBelongsTo(Ticket $ticket, TicketSubtask $subtask): void
    {
        abort_unless($subtask->ticket_id === $ticket->getKey(), 404);
    }
}
