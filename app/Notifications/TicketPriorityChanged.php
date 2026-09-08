<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;

/**
 * "AQD-42 was raised to Critical."
 *
 * The priority is stored as its enum value, which does not break the
 * identifiers-only rule the base class states. That rule exists to stop a
 * payload holding text somebody wrote — a ticket title, a comment body — which
 * would keep being displayed after the thing stopped being readable. A closed
 * enum is not that: it is one of four fixed words, it is already an attribute
 * of a ticket the recipient has to be able to read for the notification to
 * survive at all, and freezing it is the only way an old notification can say
 * what the priority became rather than what it is now.
 */
class TicketPriorityChanged extends WorkspaceNotification
{
    public const TYPE = 'ticket_priority_changed';

    public function __construct(
        private readonly Ticket $ticket,
        private readonly TicketPriority $priority,
        private readonly ?User $actor = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'board_id' => (int) $this->ticket->board_id,
            'ticket_id' => (int) $this->ticket->getKey(),
            'comment_id' => null,
            'actor_id' => $this->actor?->getKey(),
            'priority' => $this->priority->value,
        ];
    }
}
