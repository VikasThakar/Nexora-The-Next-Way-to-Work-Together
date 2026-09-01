<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Everything worth remembering about a ticket.
 *
 * `ticket_events` is append-only and is written from the very first ticket, so
 * that the activity timeline and the flow metrics in later phases have real
 * history to work with rather than starting from the day they ship.
 *
 * Each case owns its own rendering, so a new event type cannot be added
 * without deciding how it reads in the timeline.
 */
enum TicketEventType: string
{
    case TicketCreated = 'ticket_created';
    case TicketUpdated = 'ticket_updated';
    case TicketMoved = 'ticket_moved';
    case AssigneeChanged = 'assignee_changed';
    case PriorityChanged = 'priority_changed';
    case VisibilityChanged = 'visibility_changed';
    case LabelChanged = 'label_changed';
    case LinkChanged = 'link_changed';
    case AttachmentChanged = 'attachment_changed';

    /*
     * AI automation. All four are internal-only (see isInternalOnly below): a
     * customer must not learn that their request was machine-triaged, nor that
     * the attempt failed or was skipped to save money.
     */
    case AiRunQueued = 'ai_run_queued';
    case AiRunCompleted = 'ai_run_completed';
    case AiRunFailed = 'ai_run_failed';
    case AiRunSkipped = 'ai_run_skipped';

    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => 'created this ticket',
            self::TicketUpdated => 'updated this ticket',
            self::TicketMoved => 'moved this ticket',
            self::AssigneeChanged => 'changed the assignee',
            self::PriorityChanged => 'changed the priority',
            self::VisibilityChanged => 'changed the visibility',
            self::LabelChanged => 'changed labels',
            self::LinkChanged => 'changed linked tickets',
            self::AttachmentChanged => 'changed attachments',
            self::AiRunQueued => 'queued an AI run',
            self::AiRunCompleted => 'completed an AI run',
            self::AiRunFailed => 'had an AI run fail',
            self::AiRunSkipped => 'skipped an automatic AI run',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TicketCreated => 'plus',
            self::TicketMoved => 'arrow',
            self::AssigneeChanged => 'user',
            self::VisibilityChanged => 'eye',
            self::AiRunQueued, self::AiRunCompleted, self::AiRunFailed, self::AiRunSkipped => 'sparkle',
            default => 'pencil',
        };
    }

    /**
     * Events a customer must never be shown, even on a ticket they can read.
     *
     * A visibility change records who flipped a ticket between internal and
     * customer-visible; showing that to a customer would leak the fact that
     * the ticket was previously hidden from them.
     *
     * Every AI event is internal for a related reason: all AI output belongs to
     * the internal notes, and so does the fact that a run happened at all. A
     * timeline entry saying "queued an AI run" would tell a customer their
     * request had been handed to a machine — and one saying it failed, or was
     * skipped to stay under a cost cap, would tell them rather more than that.
     */
    public function isInternalOnly(): bool
    {
        return match ($this) {
            self::VisibilityChanged,
            self::AiRunQueued,
            self::AiRunCompleted,
            self::AiRunFailed,
            self::AiRunSkipped => true,
            default => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
