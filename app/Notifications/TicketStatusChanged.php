<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;

/**
 * "AQD-42 moved to In Progress."
 *
 * The column is stored as an id, not a name, and that is the rule this base
 * class exists for rather than an exception to it: an id is an identifier, and
 * App\Services\NotificationReader re-reads the column to render the line. So a
 * column renamed next month reads correctly in a notification written today,
 * while *which* column the ticket moved into stays frozen — which is what a
 * reader of an old notification actually wants to know.
 */
class TicketStatusChanged extends WorkspaceNotification
{
    public const TYPE = 'ticket_status_changed';

    public function __construct(
        private readonly Ticket $ticket,
        private readonly BoardColumn $column,
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
            'column_id' => (int) $this->column->getKey(),
        ];
    }
}
