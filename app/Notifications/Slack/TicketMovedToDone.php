<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

/**
 * A ticket reached a column the board marks as done.
 *
 * Driven by the column's `is_done` flag rather than by a column *named* "Done",
 * so a board that calls its final column "Shipped" or "Released" still
 * announces correctly.
 */
class TicketMovedToDone extends BoardSlackNotification
{
    public function __construct(
        private readonly string $ticketKey,
        private readonly string $title,
        private readonly string $url,
        private readonly string $boardName,
        private readonly string $columnName,
        private readonly ?string $actor,
    ) {
        parent::__construct();
    }

    public function toSlackWebhook(object $notifiable): SlackMessage
    {
        return SlackMessage::make(
            text: $this->ticketKey.' is done — '.$this->title,
            headline: '✅ '.$this->ticketKey.' moved to '.$this->columnName,
            fields: array_values(array_filter([
                ['label' => 'Title', 'value' => $this->title],
                ['label' => 'Board', 'value' => $this->boardName],
                $this->actor === null ? null : ['label' => 'Moved by', 'value' => $this->actor],
            ])),
            url: $this->url,
            linkLabel: 'Open '.$this->ticketKey,
        );
    }
}
