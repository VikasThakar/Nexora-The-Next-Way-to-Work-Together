<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Writes ticket history.
 *
 * Called explicitly from the actions rather than from a model observer. An
 * observer only sees the diff of the row; the actions know *why* something
 * changed — which column a ticket came from, which labels were added versus
 * removed — and that context is what makes the timeline and the later flow
 * metrics useful.
 *
 * Recording must never break the operation that triggered it, so callers wrap
 * their work in a transaction and events are written inside it: either the
 * change and its history both land, or neither does.
 */
class TicketActivity
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Ticket $ticket,
        TicketEventType $type,
        array $payload = [],
        ?User $actor = null,
    ): TicketEvent {
        return TicketEvent::query()->create([
            'ticket_id' => $ticket->getKey(),
            'board_id' => $ticket->board_id,
            'type' => $type,
            // Mirrored out of the payload rather than passed separately, so a
            // caller that already describes a transit gets the indexed columns
            // for free and cannot describe one without them. See
            // columnId() for why the payload keeps its copy.
            'from_column_id' => $this->columnId($payload, 'from_column_id'),
            'to_column_id' => $this->columnId($payload, 'to_column_id'),
            'payload' => $payload === [] ? null : $payload,
            'actor_id' => ($actor ?? $this->currentUser())?->getKey(),
        ]);
    }

    /**
     * Read a column id out of an event payload.
     *
     * The payload keeps both the id and the name. The id is what the flow
     * metrics group by; the name is what the timeline renders, and it is stored
     * rather than joined because a column that is later renamed — or deleted —
     * must still read correctly in a history written before that happened.
     *
     * @param  array<string, mixed>  $payload
     */
    private function columnId(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Turn an Eloquent dirty-attribute diff into one or more events.
     *
     * Fields the product treats as first-class get their own event type so the
     * timeline can render them well and so metrics can find them cheaply.
     * Everything else collapses into a single ticket_updated carrying the
     * before/after map.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [from, to]
     * @return array<int, TicketEvent>
     */
    public function recordChanges(Ticket $ticket, array $changes, ?User $actor = null): array
    {
        $dedicated = [
            'assignee_id' => TicketEventType::AssigneeChanged,
            'priority' => TicketEventType::PriorityChanged,
            'customer_visible' => TicketEventType::VisibilityChanged,
        ];

        $events = [];
        $generic = [];

        foreach ($changes as $field => [$from, $to]) {
            if (isset($dedicated[$field])) {
                $events[] = $this->record($ticket, $dedicated[$field], [
                    'field' => $field,
                    'from' => $from,
                    'to' => $to,
                ], $actor);

                continue;
            }

            $generic[$field] = ['from' => $from, 'to' => $to];
        }

        if ($generic !== []) {
            $events[] = $this->record($ticket, TicketEventType::TicketUpdated, [
                'changes' => $generic,
            ], $actor);
        }

        return $events;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
