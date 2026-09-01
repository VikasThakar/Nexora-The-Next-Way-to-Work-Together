<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

/**
 * A customer replied in the customer conversation.
 *
 * Only that conversation. Internal notes never reach this class — the dispatcher
 * filters on the stream before constructing it — because an internal note is
 * the delivery team talking privately, and a Slack channel is not obviously
 * more private than the ticket it came from.
 *
 * The comment body is not included, for the same reason a ticket description is
 * not: this is a nudge to go and read it, not a mirror of the thread.
 */
class CustomerCommentPosted extends BoardSlackNotification
{
    public function __construct(
        private readonly string $ticketKey,
        private readonly string $ticketTitle,
        private readonly string $url,
        private readonly string $boardName,
        private readonly string $author,
    ) {
        parent::__construct();
    }

    public function toSlackWebhook(object $notifiable): SlackMessage
    {
        return SlackMessage::make(
            text: 'New customer comment on '.$this->ticketKey,
            headline: 'New customer comment on '.$this->ticketKey,
            fields: [
                ['label' => 'Ticket', 'value' => $this->ticketTitle],
                ['label' => 'Board', 'value' => $this->boardName],
                ['label' => 'From', 'value' => $this->author],
            ],
            url: $this->url,
            linkLabel: 'Reply',
            context: 'The message itself is on the ticket.',
        );
    }
}
