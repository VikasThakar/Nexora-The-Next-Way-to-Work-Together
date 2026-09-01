<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The things worth telling an outside system about.
 *
 * Shared by the Slack integration and the SMS alerts rather than each having
 * its own list, so "which events exist" has one answer and a new one cannot be
 * added to one channel and silently missing from the other.
 *
 * Every case here describes something that happened *inside* the workspace and
 * is being announced *outside* it. That framing is the reason the notification
 * bodies are as thin as they are: an outbound message goes to a Slack channel
 * or a phone, neither of which enforces this application's visibility rules. A
 * message therefore carries a ticket key, a title, and a link — and the link is
 * what applies the rules, by requiring the reader to log in.
 */
enum NotificationEvent: string
{
    case CustomerTicketRaised = 'customer_ticket_raised';
    case CustomerCommentPosted = 'customer_comment_posted';
    case AiRunFinished = 'ai_run_finished';
    case TicketMovedToDone = 'ticket_moved_to_done';

    /** Critical tickets are the SMS trigger, and only ever that. */
    case CriticalTicketRaised = 'critical_ticket_raised';

    public function label(): string
    {
        return match ($this) {
            self::CustomerTicketRaised => 'A customer raises a ticket',
            self::CustomerCommentPosted => 'A customer comments',
            self::AiRunFinished => 'An AI run finishes',
            self::TicketMovedToDone => 'A ticket is moved to done',
            self::CriticalTicketRaised => 'A critical ticket is raised',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CustomerTicketRaised => 'Posted when somebody outside the delivery team files a new ticket.',
            self::CustomerCommentPosted => 'Posted when a customer replies in the customer conversation. Internal notes are never announced.',
            self::AiRunFinished => 'Posted when an AI run completes or fails. The analysis itself stays in the internal note.',
            self::TicketMovedToDone => 'Posted when a ticket reaches a column marked as done.',
            self::CriticalTicketRaised => 'Sends an SMS to the on-call numbers configured for this board.',
        };
    }

    /**
     * The events a board can subscribe a Slack channel to.
     *
     * Critical tickets are absent on purpose: an SMS is an interruption chosen
     * deliberately for the few things worth waking somebody for, and offering
     * it as "just another Slack event" would blur that. A critical ticket still
     * produces the ordinary ticket-raised message in Slack.
     *
     * @return array<int, self>
     */
    public static function slackEvents(): array
    {
        return [
            self::CustomerTicketRaised,
            self::CustomerCommentPosted,
            self::AiRunFinished,
            self::TicketMovedToDone,
        ];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
