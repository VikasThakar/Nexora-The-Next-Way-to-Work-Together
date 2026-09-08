<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivity;
use App\Support\TaskList;
use Illuminate\Support\Facades\DB;

/**
 * Move a ticket's standalone checklist into its description.
 *
 * The client asked for checklists inside the description. Nexora already had
 * them somewhere else — `ticket_subtasks`, with drag-to-reorder, a progress
 * bar, and a record of who completed each item and when — so this phase had to
 * answer what happens to the checklists that already exist.
 *
 * The answer is deliberately not a data migration:
 *
 *   Nothing is converted automatically. A migration that rewrote every
 *   description in the database would be irreversible, would touch the one
 *   column nobody can reconstruct, and would do it to boards whose owners never
 *   asked. Both features work; this runs per ticket, when somebody presses the
 *   button.
 *
 *   Nothing is lost when it does run. The `ticket_subtasks` rows go, but the
 *   event written here carries every item exactly as it stood — including the
 *   `completed_at` and `completed_by_id` that a Markdown checkbox cannot
 *   express — and `ticket_events` is append-only. The conversion is therefore
 *   reconstructible from the audit trail even though it is not undoable in the
 *   interface, which is a materially different thing from data loss.
 *
 *   It is additive to the description. The list is appended under a heading
 *   rather than replacing anything, so a description somebody spent an hour on
 *   survives intact.
 *
 * Writes the description itself instead of delegating to UpdateTicket, so the
 * whole conversion is one event on the timeline rather than "updated this
 * ticket" followed by "moved the checklist" — one action a person took should
 * read as one line.
 */
class ConvertSubtasksToChecklist
{
    public function __construct(
        private readonly TicketActivity $activity,
        private readonly TaskList $tasks,
    ) {}

    /**
     * The heading the appended list is filed under.
     *
     * Only added when the description already has content: a ticket whose
     * description is nothing but a checklist does not need a heading announcing
     * it.
     */
    private const HEADING = '## Checklist';

    public function handle(Ticket $ticket, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor): Ticket {
            // Ordered by position, then id — the same order the panel shows and
            // therefore the order the person pressing the button is looking at.
            $subtasks = $ticket->subtasks()->get();

            if ($subtasks->isEmpty()) {
                return $ticket;
            }

            $snapshot = $subtasks->map(fn ($subtask): array => [
                'title' => $subtask->title,
                'completed' => (bool) $subtask->completed,
                'completed_at' => $subtask->completed_at?->toIso8601String(),
                'completed_by_id' => $subtask->completed_by_id,
                'position' => $subtask->position,
            ])->all();

            $checklist = $this->tasks->toMarkdown(
                array_map(
                    static fn (array $item): array => [
                        'title' => $item['title'],
                        'completed' => $item['completed'],
                    ],
                    $snapshot
                )
            );

            $existing = trim((string) $ticket->description_md);

            $ticket->description_md = $existing === ''
                ? $checklist
                : $existing."\n\n".self::HEADING."\n\n".$checklist;

            $ticket->save();

            // Deleted only once the description holding them has been written,
            // inside the same transaction: either both happen or neither does,
            // so there is no window in which the items exist nowhere.
            $ticket->subtasks()->delete();

            $this->activity->record($ticket, TicketEventType::ChecklistConverted, [
                'count' => count($snapshot),
                'completed' => count(array_filter(
                    $snapshot,
                    static fn (array $item): bool => $item['completed']
                )),
                // The whole checklist, so this row is the record of what was
                // converted and not merely that something was.
                'items' => $snapshot,
            ], $actor);

            return $ticket;
        });
    }
}
