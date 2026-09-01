<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

/**
 * An AI run finished, one way or the other.
 *
 * This is the message that needs the most care, because the thing it is about
 * is the thing the AI phase forbids showing to anyone outside the internal
 * notes.
 *
 * So what travels to Slack is the *fact* of a run and nothing it produced:
 * which ticket, which mode, whether it succeeded, and a link. Specifically
 * absent, and absent on purpose:
 *
 *   the analysis text        it belongs in the internal note, full stop;
 *   any code or diff         same;
 *   the failure reason       an error message is the most likely place for a
 *                            path, a hostname or a credential fragment to
 *                            appear, and a Slack channel is the wrong place to
 *                            discover that. "Failed" plus a link is enough to
 *                            get somebody to the internal note that has the
 *                            detail;
 *   the pull request URL     it is a private repository link, and the ticket
 *                            already carries it for people who can see it.
 *
 * A board has to opt into this event — it is the one Slack event that defaults
 * to off (see config/slack.php) — because a board with automation on generates
 * one of these per customer ticket, and a channel that fills up with them stops
 * being read.
 */
class AiRunFinished extends BoardSlackNotification
{
    public function __construct(
        private readonly string $ticketKey,
        private readonly string $ticketTitle,
        private readonly string $url,
        private readonly string $boardName,
        private readonly string $mode,
        private readonly bool $succeeded,
        private readonly bool $openedPullRequest,
    ) {
        parent::__construct();
    }

    public function toSlackWebhook(object $notifiable): SlackMessage
    {
        $outcome = $this->succeeded ? 'completed' : 'failed';

        return SlackMessage::make(
            text: 'AI run '.$outcome.' on '.$this->ticketKey,
            headline: ($this->succeeded ? '🤖 ' : '⚠️ ').'AI run '.$outcome.' on '.$this->ticketKey,
            fields: array_values(array_filter([
                ['label' => 'Ticket', 'value' => $this->ticketTitle],
                ['label' => 'Board', 'value' => $this->boardName],
                ['label' => 'Mode', 'value' => ucfirst($this->mode)],
                $this->openedPullRequest
                    ? ['label' => 'Result', 'value' => 'A draft pull request is waiting for review']
                    : null,
            ])),
            url: $this->url,
            linkLabel: 'Open '.$this->ticketKey,
            // Says plainly why the message is thin, so nobody adds the detail
            // later thinking it was an oversight.
            context: $this->succeeded
                ? 'The analysis is in the internal notes on the ticket; it is not posted here.'
                : 'The reason is in the internal notes on the ticket; it is not posted here.',
        );
    }
}
