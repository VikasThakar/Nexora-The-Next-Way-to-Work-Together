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
     * The standalone checklist moved into the description.
     *
     * Its own case rather than a plain ticket_updated, because the payload is
     * the point: it carries every item as it stood — title, completed, who
     * ticked it and when — and `ticket_events` is append-only, so converting a
     * checklist destroys no information even though the ticket_subtasks rows
     * are gone. See App\Actions\Tickets\ConvertSubtasksToChecklist.
     */
    case ChecklistConverted = 'checklist_converted';

    /*
     * AI automation. All four are internal-only (see isInternalOnly below): a
     * customer must not learn that their request was machine-triaged, nor that
     * the attempt failed or was skipped to save money.
     */
    case AiRunQueued = 'ai_run_queued';
    case AiRunCompleted = 'ai_run_completed';
    case AiRunFailed = 'ai_run_failed';
    case AiRunSkipped = 'ai_run_skipped';

    /*
     * GitHub. Written by App\Services\GitHub\WebhookProcessor from a signed
     * delivery, and internal-only for the same reason App\Models\GithubLink is:
     * a branch name paraphrases the fix, a commit message says what was wrong
     * in the words an engineer used while annoyed about it, and a red CI badge
     * on a customer's ticket invites a question the team has not answered yet.
     *
     * The actor is null on every one of these — GitHub knows a login, not a
     * Nexora user — so the payload carries `author` and the timeline renders
     * that instead of "System".
     */
    case GithubBranchCreated = 'github_branch_created';
    case GithubCommitPushed = 'github_commit_pushed';
    case GithubPullRequestOpened = 'github_pull_request_opened';
    case GithubPullRequestMerged = 'github_pull_request_merged';
    case GithubPullRequestClosed = 'github_pull_request_closed';

    /*
     * One case rather than passed/failed, because `conclusion` also carries
     * cancelled, timed_out, stale and neutral. Splitting it two ways would
     * force four of those into a bucket they do not belong in; the payload
     * keeps the real answer and the timeline prints it.
     */
    case GithubCheckCompleted = 'github_check_completed';

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
            self::ChecklistConverted => 'moved the checklist into the description',
            self::AiRunQueued => 'queued an AI run',
            self::AiRunCompleted => 'completed an AI run',
            self::AiRunFailed => 'had an AI run fail',
            self::AiRunSkipped => 'skipped an automatic AI run',
            self::GithubBranchCreated => 'created a branch',
            self::GithubCommitPushed => 'pushed a commit',
            self::GithubPullRequestOpened => 'opened a pull request',
            self::GithubPullRequestMerged => 'merged a pull request',
            self::GithubPullRequestClosed => 'closed a pull request',
            self::GithubCheckCompleted => 'reported a CI result',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TicketCreated => 'plus',
            self::TicketMoved => 'arrow',
            self::AssigneeChanged => 'user',
            self::VisibilityChanged => 'eye',
            self::ChecklistConverted => 'check',
            self::AiRunQueued, self::AiRunCompleted, self::AiRunFailed, self::AiRunSkipped => 'sparkle',
            self::GithubBranchCreated => 'branch',
            self::GithubCommitPushed => 'commit',
            self::GithubPullRequestOpened,
            self::GithubPullRequestMerged,
            self::GithubPullRequestClosed => 'pull-request',
            self::GithubCheckCompleted => 'check',
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
     *
     * Every GitHub event is internal for the reason App\Models\GithubLink
     * states at length: what a customer is owed is "this is fixed and
     * released", written by a person in the customer conversation — not a live
     * feed of the engineering work, its branch names or its red builds.
     */
    public function isInternalOnly(): bool
    {
        return match ($this) {
            self::VisibilityChanged,
            self::AiRunQueued,
            self::AiRunCompleted,
            self::AiRunFailed,
            self::AiRunSkipped,
            self::GithubBranchCreated,
            self::GithubCommitPushed,
            self::GithubPullRequestOpened,
            self::GithubPullRequestMerged,
            self::GithubPullRequestClosed,
            self::GithubCheckCompleted => true,
            default => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
