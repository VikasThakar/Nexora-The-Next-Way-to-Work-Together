<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

/**
 * "AQD-42 was assigned to you."
 */
class TicketAssigned extends WorkspaceNotification
{
    public const TYPE = 'ticket_assigned';

    public function __construct(
        private readonly Ticket $ticket,
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
        ];
    }
}
