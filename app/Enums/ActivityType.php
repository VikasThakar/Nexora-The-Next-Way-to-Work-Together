<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Everything the workspace Activity screen knows how to report.
 *
 * Stored in `activity_log.event`. Spatie lets a caller pass any string there;
 * closing the set means the type filter is an indexed equality test, the feed
 * can render an icon and a colour per type without a default branch, and a new
 * kind of activity cannot be added without deciding — right here, in four
 * methods — how it reads, where it files, and who is allowed to see it.
 *
 * What is deliberately absent
 * ---------------------------
 * There is no case for a page view, a search, a filter change or a card being
 * opened. The screen is a record of things that changed, not a clickstream: a
 * feed that reports everything reports nothing.
 *
 * Relationship to TicketEventType
 * -------------------------------
 * The ticket cases mirror App\Enums\TicketEventType one-for-one, plus
 * TicketDeleted, which `ticket_events` cannot hold because its rows cascade
 * with the ticket they describe. The mapping lives in
 * App\Services\ActivityLogger::forTicketEvent(), and the mirroring happens at
 * the single point where ticket history is written
 * (App\Services\TicketActivity) — so a movement recorded once cannot become two
 * activities, whichever screen or action triggered it.
 *
 * Values must never collide with an App\Enums\ActivityCategory value; see that
 * enum, and Tests\Unit\ActivityTaxonomyTest.
 */
enum ActivityType: string
{
    // -----------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------

    case TicketCreated = 'ticket_created';
    case TicketUpdated = 'ticket_updated';
    case TicketMoved = 'ticket_moved';
    case TicketDeleted = 'ticket_deleted';
    case TicketAssigneeChanged = 'ticket_assignee_changed';
    case TicketPriorityChanged = 'ticket_priority_changed';
    case TicketVisibilityChanged = 'ticket_visibility_changed';
    case TicketLabelsChanged = 'ticket_labels_changed';
    case TicketAttachmentsChanged = 'ticket_attachments_changed';

    case TicketAiRunQueued = 'ticket_ai_run_queued';
    case TicketAiRunCompleted = 'ticket_ai_run_completed';
    case TicketAiRunFailed = 'ticket_ai_run_failed';
    case TicketAiRunSkipped = 'ticket_ai_run_skipped';

    // -----------------------------------------------------------------
    // Boards
    // -----------------------------------------------------------------

    case BoardCreated = 'board_created';
    case BoardUpdated = 'board_updated';
    case BoardArchived = 'board_archived';
    case BoardRestored = 'board_restored';
    case BoardDeleted = 'board_deleted';

    // -----------------------------------------------------------------
    // Membership
    // -----------------------------------------------------------------

    case MemberAdded = 'member_added';
    case MemberRemoved = 'member_removed';

    // -----------------------------------------------------------------
    // Conversation
    // -----------------------------------------------------------------

    case CommentCreated = 'comment_created';
    case CommentEdited = 'comment_edited';
    case CommentDeleted = 'comment_deleted';

    // -----------------------------------------------------------------
    // Documentation
    // -----------------------------------------------------------------

    case PageCreated = 'page_created';
    case PageUpdated = 'page_updated';
    case PageRenamed = 'page_renamed';
    case PageDeleted = 'page_deleted';
    case PageMoved = 'page_moved';
    case PagePublished = 'page_published';
    case PageRetracted = 'page_retracted';

    // -----------------------------------------------------------------
    // Development
    // -----------------------------------------------------------------

    /*
     * Mirrored one-for-one from the GitHub cases in
     * App\Enums\TicketEventType, written at the single funnel in
     * App\Services\TicketActivity. Same reasoning as the ticket cases: one
     * webhook delivery produces one ticket event and one activity, and there
     * is no observer left to write a second.
     */
    case GithubBranchCreated = 'github_branch_created';
    case GithubCommitPushed = 'github_commit_pushed';
    case GithubPullRequestOpened = 'github_pull_request_opened';
    case GithubPullRequestMerged = 'github_pull_request_merged';
    case GithubPullRequestClosed = 'github_pull_request_closed';
    case GithubCheckCompleted = 'github_check_completed';

    // -----------------------------------------------------------------
    // Board configuration
    // -----------------------------------------------------------------

    case BoardSettingsChanged = 'board_settings_changed';
    case ColumnCreated = 'column_created';
    case ColumnUpdated = 'column_updated';
    case ColumnDeleted = 'column_deleted';
    case ColumnsReordered = 'columns_reordered';

    /**
     * Where this type files in the category filter, and therefore what goes
     * into `activity_log.log_name`.
     *
     * Exhaustive on purpose — no default arm — so adding a case above fails
     * loudly here rather than producing a row that files under nothing and
     * disappears from every filtered view.
     */
    public function category(): ActivityCategory
    {
        return match ($this) {
            self::TicketCreated,
            self::TicketUpdated,
            self::TicketMoved,
            self::TicketDeleted,
            self::TicketAssigneeChanged,
            self::TicketPriorityChanged,
            self::TicketVisibilityChanged,
            self::TicketLabelsChanged,
            self::TicketAttachmentsChanged,
            self::TicketAiRunQueued,
            self::TicketAiRunCompleted,
            self::TicketAiRunFailed,
            self::TicketAiRunSkipped => ActivityCategory::Tickets,

            self::BoardCreated,
            self::BoardUpdated,
            self::BoardArchived,
            self::BoardRestored,
            self::BoardDeleted => ActivityCategory::Boards,

            self::MemberAdded,
            self::MemberRemoved => ActivityCategory::Members,

            self::CommentCreated,
            self::CommentEdited,
            self::CommentDeleted => ActivityCategory::Comments,

            self::PageCreated,
            self::PageUpdated,
            self::PageRenamed,
            self::PageDeleted,
            self::PageMoved,
            self::PagePublished,
            self::PageRetracted => ActivityCategory::Documentation,

            self::GithubBranchCreated,
            self::GithubCommitPushed,
            self::GithubPullRequestOpened,
            self::GithubPullRequestMerged,
            self::GithubPullRequestClosed,
            self::GithubCheckCompleted => ActivityCategory::Development,

            self::BoardSettingsChanged,
            self::ColumnCreated,
            self::ColumnUpdated,
            self::ColumnDeleted,
            self::ColumnsReordered => ActivityCategory::Settings,
        };
    }

    /**
     * A short name for the type, used by the filter dropdown and the badge on
     * each row. Not the sentence — that is composed with its context by
     * App\Services\ActivityLogger and stored in `description`.
     */
    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => 'Ticket created',
            self::TicketUpdated => 'Ticket updated',
            self::TicketMoved => 'Ticket moved',
            self::TicketDeleted => 'Ticket deleted',
            self::TicketAssigneeChanged => 'Assignment',
            self::TicketPriorityChanged => 'Priority',
            self::TicketVisibilityChanged => 'Visibility',
            self::TicketLabelsChanged => 'Labels',
            self::TicketAttachmentsChanged => 'Attachments',
            self::TicketAiRunQueued => 'AI run queued',
            self::TicketAiRunCompleted => 'AI run completed',
            self::TicketAiRunFailed => 'AI run failed',
            self::TicketAiRunSkipped => 'AI run skipped',
            self::BoardCreated => 'Board created',
            self::BoardUpdated => 'Board updated',
            self::BoardArchived => 'Board archived',
            self::BoardRestored => 'Board restored',
            self::BoardDeleted => 'Board deleted',
            self::MemberAdded => 'Member added',
            self::MemberRemoved => 'Member removed',
            self::CommentCreated => 'Comment',
            self::CommentEdited => 'Comment edited',
            self::CommentDeleted => 'Comment deleted',
            self::PageCreated => 'Page created',
            self::PageUpdated => 'Page updated',
            self::PageRenamed => 'Page renamed',
            self::PageDeleted => 'Page deleted',
            self::PageMoved => 'Page moved',
            self::PagePublished => 'Page published',
            self::PageRetracted => 'Page retracted',
            self::GithubBranchCreated => 'Branch created',
            self::GithubCommitPushed => 'Commit pushed',
            self::GithubPullRequestOpened => 'Pull request opened',
            self::GithubPullRequestMerged => 'Pull request merged',
            self::GithubPullRequestClosed => 'Pull request closed',
            self::GithubCheckCompleted => 'CI result',
            self::BoardSettingsChanged => 'Board settings',
            self::ColumnCreated => 'Column added',
            self::ColumnUpdated => 'Column updated',
            self::ColumnDeleted => 'Column removed',
            self::ColumnsReordered => 'Columns reordered',
        };
    }

    /**
     * Which glyph the timeline marker draws. Keys are resolved by
     * resources/views/components/activity/icon.blade.php, and share the
     * vocabulary App\Enums\TicketEventType::icon() uses so the two timelines
     * stay recognisably the same product.
     */
    public function icon(): string
    {
        return match ($this) {
            self::TicketCreated, self::BoardCreated, self::PageCreated,
            self::ColumnCreated, self::MemberAdded => 'plus',

            self::TicketMoved, self::PageMoved, self::ColumnsReordered => 'arrow',

            self::TicketAssigneeChanged, self::MemberRemoved => 'user',

            self::TicketVisibilityChanged, self::PagePublished,
            self::PageRetracted => 'eye',

            self::TicketPriorityChanged => 'flag',

            self::TicketAiRunQueued, self::TicketAiRunCompleted,
            self::TicketAiRunFailed, self::TicketAiRunSkipped => 'sparkle',

            self::GithubBranchCreated => 'branch',
            self::GithubCommitPushed => 'commit',

            self::GithubPullRequestOpened, self::GithubPullRequestMerged,
            self::GithubPullRequestClosed => 'pull-request',

            self::GithubCheckCompleted => 'check',

            self::CommentCreated, self::CommentEdited,
            self::CommentDeleted => 'chat',

            self::TicketDeleted, self::BoardDeleted, self::PageDeleted,
            self::ColumnDeleted => 'trash',

            self::BoardSettingsChanged, self::BoardArchived,
            self::BoardRestored => 'cog',

            default => 'pencil',
        };
    }

    /**
     * The badge colour, limited to the variants x-ui.badge already offers so
     * the feed introduces no new colour into the design system.
     */
    public function tone(): string
    {
        return match ($this) {
            self::TicketCreated, self::BoardCreated, self::PageCreated,
            self::ColumnCreated, self::MemberAdded => 'emerald',

            self::TicketDeleted, self::BoardDeleted, self::PageDeleted,
            self::ColumnDeleted, self::CommentDeleted, self::MemberRemoved,
            self::TicketAiRunFailed => 'rose',

            self::TicketMoved, self::TicketAssigneeChanged => 'brand',

            self::TicketPriorityChanged, self::TicketVisibilityChanged,
            self::BoardArchived => 'amber',

            self::GithubPullRequestMerged => 'brand',
            self::GithubPullRequestClosed => 'rose',

            default => 'slate',
        };
    }

    /**
     * Activities a customer must never be shown.
     *
     * Nothing depends on this today: the Activity screen is staff-only three
     * times over — the route's role gate, the component's own check, and
     * App\Services\ActivityReader refusing a non-staff viewer outright. It is
     * here because the moment somebody builds a customer-facing feed, the
     * question "may they see this?" must already have an answer per type rather
     * than being decided by whoever writes that screen.
     *
     * The reasoning follows App\Enums\TicketEventType::isInternalOnly() and
     * extends it:
     *
     *   a visibility change tells a customer the ticket was hidden from them
     *   before;
     *   every AI event tells them their request was handed to a machine, and
     *   that it failed, or was skipped to stay under a cost cap;
     *   board management, membership and configuration are how the delivery
     *   team runs itself, and none of it is the customer's business;
     *   every GitHub event describes engineering work in the engineers' own
     *   words — branch names, commit subjects, failing builds. See
     *   App\Models\GithubLink for the longer argument.
     */
    public function isInternalOnly(): bool
    {
        return match ($this) {
            self::TicketVisibilityChanged,
            self::TicketAiRunQueued,
            self::TicketAiRunCompleted,
            self::TicketAiRunFailed,
            self::TicketAiRunSkipped,
            self::BoardCreated,
            self::BoardUpdated,
            self::BoardArchived,
            self::BoardRestored,
            self::BoardDeleted,
            self::MemberAdded,
            self::MemberRemoved,
            self::BoardSettingsChanged,
            self::ColumnCreated,
            self::ColumnUpdated,
            self::ColumnDeleted,
            self::ColumnsReordered,
            self::GithubBranchCreated,
            self::GithubCommitPushed,
            self::GithubPullRequestOpened,
            self::GithubPullRequestMerged,
            self::GithubPullRequestClosed,
            self::GithubCheckCompleted => true,

            default => false,
        };
    }

    /**
     * The handful of types worth filtering on individually, on top of the six
     * categories. These are the questions people actually ask of a feed —
     * "what moved today?", "who got assigned what?", "what got escalated?",
     * "what disappeared?" — and nothing else earns a row in the dropdown.
     *
     * @return array<int, self>
     */
    public static function filterable(): array
    {
        return [
            self::TicketMoved,
            self::TicketAssigneeChanged,
            self::TicketPriorityChanged,
            self::TicketDeleted,
        ];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every value belonging to a category, for the category filter.
     *
     * @return array<int, string>
     */
    public static function valuesIn(ActivityCategory $category): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->category() === $category)
        ));
    }

    /**
     * Values a viewer without `view-internal-content` must not receive.
     *
     * @return array<int, string>
     */
    public static function internalOnlyValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->isInternalOnly())
        ));
    }
}
