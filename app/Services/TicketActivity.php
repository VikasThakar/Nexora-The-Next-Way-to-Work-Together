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
 *
 * This is also the single point at which ticket activity reaches the
 * workspace-wide feed. Every event written here is mirrored into
 * `activity_log` by App\Services\ActivityLogger — see its class comment for
 * why there are two tables. The mirroring lives here, and only here, precisely
 * so that it cannot double up: a card dragged on the board, moved from the
 * status dropdown on the ticket screen, or moved by the column-delete flow all
 * arrive at this one method, and each call produces exactly one activity.
 * Nothing observes the Ticket model for the same purpose, and the package's
 * LogsActivity trait is deliberately not attached to it.
 */
class TicketActivity
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Ticket $ticket,
        TicketEventType $type,
        array $payload = [],
        ?User $actor = null,
    ): TicketEvent {
        return $this->write($ticket, $type, $payload, $actor ?? $this->currentUser());
    }

    /**
     * Record an event that no user performed.
     *
     * GitHub events are the case this exists for. A delivery names a GitHub
     * login, which is not a user of this workspace, and record()'s fallback to
     * the authenticated user would attribute a webhook to whoever happened to
     * be signed in when it arrived — a wrong name in an append-only audit
     * trail, which is worse than no name at all.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordWithoutActor(
        Ticket $ticket,
        TicketEventType $type,
        array $payload = [],
    ): TicketEvent {
        return $this->write($ticket, $type, $payload, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(
        Ticket $ticket,
        TicketEventType $type,
        array $payload,
        ?User $actor,
    ): TicketEvent {
        $event = TicketEvent::query()->create([
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
            'actor_id' => $actor?->getKey(),
        ]);

        // The workspace feed. Passed the event rather than the arguments so the
        // two rows cannot describe different things, and written inside the
        // caller's transaction like the event itself.
        $this->activityLogger->ticketEvent($event, $ticket);

        return $event;
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
