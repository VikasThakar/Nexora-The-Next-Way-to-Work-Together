<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

/**
 * A customer filed a ticket.
 *
 * The one Slack message that reliably needs a human response, which is why it
 * carries the priority: a channel that treats every ticket alike stops being
 * read.
 *
 * The description is deliberately absent. A customer writes it, it can be
 * pages long, and it can contain anything they consider private to their
 * relationship with the delivery team — none of which belongs in a room whose
 * membership this application does not control.
 */
class CustomerTicketRaised extends BoardSlackNotification
{
    public function __construct(
        private readonly string $ticketKey,
        private readonly string $title,
        private readonly string $url,
        private readonly string $boardName,
        private readonly string $author,
        private readonly string $priority,
        private readonly bool $isCritical,
    ) {
        parent::__construct();
    }

    public function toSlackWebhook(object $notifiable): SlackMessage
    {
        $prefix = $this->isCritical ? '🚨 Critical ticket' : 'New customer ticket';

        return SlackMessage::make(
            text: $prefix.': '.$this->ticketKey.' — '.$this->title,
            headline: $prefix.': '.$this->ticketKey,
            fields: [
                ['label' => 'Title', 'value' => $this->title],
                ['label' => 'Board', 'value' => $this->boardName],
                ['label' => 'Raised by', 'value' => $this->author],
                ['label' => 'Priority', 'value' => $this->priority],
            ],
            url: $this->url,
            linkLabel: 'Open '.$this->ticketKey,
        );
    }
}
