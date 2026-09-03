<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CommentStream;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Models\Activity;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one place a workspace activity is written.
 *
 * Why this exists at all, given that spatie/laravel-activitylog already offers
 * a perfectly good `activity()` helper: because the value of the Activity
 * screen is entirely in the consistency of its sentences. Twenty call sites
 * each composing their own would give twenty tones of voice, twenty ideas of
 * where the board id goes, and — the one that actually matters — twenty
 * chances to put something in a description that a reader is not allowed to
 * see. Callers name what happened; this class decides how it reads.
 *
 * Why the LogsActivity trait is NOT used
 * --------------------------------------
 * The package's model trait logs an Eloquent diff: "updated Ticket, attributes:
 * {board_column_id: 4}". That is the wrong altitude for every example in this
 * product. An observer sees the diff of a row; the actions know *why* it
 * changed — which column a card came from, which labels were added rather than
 * removed, whether a page was published or merely edited. Attaching the trait
 * to Ticket would also produce a second row for every movement that
 * App\Services\TicketActivity already records, which is precisely the duplicate
 * this design exists to avoid.
 *
 * Two tables, one detection point
 * -------------------------------
 * `ticket_events` stays exactly as it was. It is append-only, indexed for the
 * flow metrics behind the statistics screens, and it renders the per-ticket
 * timeline. `activity_log` is the workspace feed: cross-model, searchable, and
 * carrying a composed sentence per row.
 *
 * They are not two detectors. Everything ticket-shaped is mirrored here from
 * TicketActivity::record(), which is already the single funnel every action
 * writes ticket history through — so a card dragged on the board, moved from
 * the status dropdown, or moved by the column-delete flow produces exactly one
 * activity, and no observer is left to fire a second.
 *
 * The description convention
 * --------------------------
 * A description is a verb phrase with no actor: "moved AQD-142 from To Do to In
 * Progress". The screen renders the causer's *current* name in front of it, so
 * a person who changes their name does not retroactively rewrite history under
 * a name they no longer use — while the phrase itself stays a complete,
 * searchable sentence containing the ticket key, the titles and the column
 * names as they read at the time.
 *
 * Nothing here may throw in a way that loses the caller's work. Writes happen
 * inside the caller's transaction, following the same rule TicketActivity
 * states: either the change and its history both land, or neither does.
 */
class ActivityLogger
{
    /**
     * Names and titles longer than this are cut before going into a
     * description. A description is a sentence in a list, not a document, and
     * an unbounded title would let one row push everything else off the screen.
     */
    private const MAX_TITLE = 120;

    // ---------------------------------------------------------------------
    // Tickets
    // ---------------------------------------------------------------------

    /**
     * Mirror a freshly recorded ticket event into the workspace feed.
     *
     * Called from App\Services\TicketActivity, once per event it writes. The
     * event is passed rather than re-derived so the two rows cannot disagree
     * about what happened, and so the payload the timeline renders and the
     * properties the feed renders come from the same array.
     */
    public function ticketEvent(TicketEvent $event, Ticket $ticket): ?Activity
    {
        $type = self::forTicketEvent($event->type);

        if ($type === null) {
            return null;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $key = $this->ticketKey($ticket);

        [$description, $properties] = $this->describeTicketEvent($type, $key, $ticket, $payload);

        return $this->write(
            $type,
            $description,
            $ticket,
            (int) $ticket->board_id,
            $properties + $this->ticketContext($ticket, $key),
            $event->actor_id === null ? null : User::query()->find($event->actor_id),
        );
    }

    /**
     * Which activity a ticket event becomes, or null when it is not mirrored.
     *
     * One type is deliberately absent. A link is recorded twice in
     * `ticket_events` — once on each ticket, so both timelines read correctly
     * from their own side — and mirroring both would put "linked AQD-1 to
     * AQD-2" and "linked AQD-2 to AQD-1" next to each other in the feed. There
     * is no side to prefer, and one action must not read as two, so links stay
     * on the per-ticket timeline where the two-sidedness is the point.
     */
    public static function forTicketEvent(TicketEventType $type): ?ActivityType
    {
        return match ($type) {
            TicketEventType::TicketCreated => ActivityType::TicketCreated,
            TicketEventType::TicketUpdated => ActivityType::TicketUpdated,
            TicketEventType::TicketMoved => ActivityType::TicketMoved,
            TicketEventType::AssigneeChanged => ActivityType::TicketAssigneeChanged,
            TicketEventType::PriorityChanged => ActivityType::TicketPriorityChanged,
            TicketEventType::VisibilityChanged => ActivityType::TicketVisibilityChanged,
            TicketEventType::LabelChanged => ActivityType::TicketLabelsChanged,
            TicketEventType::AttachmentChanged => ActivityType::TicketAttachmentsChanged,
            TicketEventType::AiRunQueued => ActivityType::TicketAiRunQueued,
            TicketEventType::AiRunCompleted => ActivityType::TicketAiRunCompleted,
            TicketEventType::AiRunFailed => ActivityType::TicketAiRunFailed,
            TicketEventType::AiRunSkipped => ActivityType::TicketAiRunSkipped,
            TicketEventType::LinkChanged => null,
        };
    }

    /**
     * A ticket about to be deleted.
     *
     * Recorded here rather than mirrored, because `ticket_events` cannot hold
     * it: those rows cascade with the ticket, so the record of a deletion would
     * be removed by the deletion it describes. The subject id is stored and
     * will dangle, which is expected — the feed renders from the properties.
     */
    public function ticketDeleted(Ticket $ticket, ?User $actor = null): ?Activity
    {
        $key = $this->ticketKey($ticket);

        return $this->write(
            ActivityType::TicketDeleted,
            'deleted ticket '.$key.' '.$this->quoted($ticket->title),
            $ticket,
            (int) $ticket->board_id,
            $this->ticketContext($ticket, $key),
            $actor,
        );
    }

    /**
     * Compose one ticket event.
     *
     * Deliberately defensive about the payload, for the same reason the
     * per-ticket timeline renderer is: these arrays are JSON written by
     * whichever version of the application was deployed at the time, and a key
     * that has moved or gone must degrade to a plainer sentence rather than
     * throw years later.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describeTicketEvent(
        ActivityType $type,
        string $key,
        Ticket $ticket,
        array $payload,
    ): array {
        return match ($type) {
            ActivityType::TicketCreated => [
                'created ticket '.$key.' '.$this->quoted($ticket->title),
                [
                    'to' => $this->text($payload['column'] ?? null),
                    'raised_by_customer' => (bool) ($payload['raised_by_customer'] ?? false),
                ],
            ],

            ActivityType::TicketMoved => $this->describeMove($key, $payload),

            ActivityType::TicketAssigneeChanged => $this->describeAssignee($key, $payload),

            ActivityType::TicketPriorityChanged => $this->describePriority($key, $payload),

            ActivityType::TicketVisibilityChanged => [
                ($payload['to'] ?? false)
                    ? 'made '.$key.' visible to the customer'
                    : 'made '.$key.' internal only',
                [
                    'from' => ($payload['from'] ?? false) ? 'Customer-visible' : 'Internal',
                    'to' => ($payload['to'] ?? false) ? 'Customer-visible' : 'Internal',
                    'old' => ['customer_visible' => (bool) ($payload['from'] ?? false)],
                    'attributes' => ['customer_visible' => (bool) ($payload['to'] ?? false)],
                ],
            ],

            ActivityType::TicketLabelsChanged => $this->describeLabels($key, $payload),

            ActivityType::TicketAttachmentsChanged => [
                trim(($payload['action'] ?? 'changed').' an attachment on '.$key
                    .($this->text($payload['filename'] ?? null) === null
                        ? ''
                        : ' ('.$this->trim((string) $payload['filename']).')')),
                ['filename' => $this->text($payload['filename'] ?? null)],
            ],

            ActivityType::TicketAiRunQueued => [
                'queued an AI run on '.$key.$this->aiSuffix($payload),
                $this->aiProperties($payload),
            ],

            ActivityType::TicketAiRunCompleted => [
                'completed an AI run on '.$key.$this->aiSuffix($payload),
                $this->aiProperties($payload),
            ],

            ActivityType::TicketAiRunFailed => [
                'had an AI run fail on '.$key,
                $this->aiProperties($payload),
            ],

            ActivityType::TicketAiRunSkipped => [
                'skipped an automatic AI run on '.$key,
                $this->aiProperties($payload),
            ],

            // TicketUpdated, and anything a future event type maps to before
            // this method is taught about it.
            default => $this->describeUpdate($key, $payload),
        };
    }

    /**
     * "moved AQD-142 from To Do to In Progress".
     *
     * The column *names* come from the payload rather than from a lookup, and
     * that is on purpose: a column can be renamed or removed later, and this
     * sentence has to keep meaning what it meant. The ids are kept alongside
     * for anything that needs to group by column.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describeMove(string $key, array $payload): array
    {
        $from = $this->text($payload['from_column'] ?? null);
        $to = $this->text($payload['to_column'] ?? null);

        $description = match (true) {
            $from !== null && $to !== null => 'moved '.$key.' from '.$from.' to '.$to,
            $to !== null => 'moved '.$key.' to '.$to,
            default => 'moved '.$key,
        };

        // The column-delete flow moves every card out of a column before
        // removing it. Saying so stops a reader wondering who dragged fifteen
        // tickets at four in the morning.
        if (($payload['reason'] ?? null) === 'column_deleted') {
            $description .= ' (its column was removed)';
        }

        return [$description, [
            'from' => $from,
            'to' => $to,
            'from_column_id' => $this->intOrNull($payload['from_column_id'] ?? null),
            'to_column_id' => $this->intOrNull($payload['to_column_id'] ?? null),
            'reason' => $this->text($payload['reason'] ?? null),
        ]];
    }

    /**
     * "assigned AQD-142 to Alex Smith", or "unassigned AQD-142".
     *
     * The name is resolved now and stored, so the sentence survives the person
     * leaving the workspace, and so a search for "Alex" finds the assignment
     * and not only the things Alex did.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describeAssignee(string $key, array $payload): array
    {
        $fromName = $this->userName($payload['from'] ?? null);
        $toName = $this->userName($payload['to'] ?? null);

        $description = match (true) {
            $toName !== null && $fromName !== null => 'reassigned '.$key.' from '.$fromName.' to '.$toName,
            $toName !== null => 'assigned '.$key.' to '.$toName,
            default => 'unassigned '.$key,
        };

        return [$description, [
            'from' => $fromName,
            'to' => $toName,
            'old' => ['assignee_id' => $this->intOrNull($payload['from'] ?? null)],
            'attributes' => ['assignee_id' => $this->intOrNull($payload['to'] ?? null)],
        ]];
    }

    /**
     * "changed the priority of AQD-142 from Medium to Critical".
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describePriority(string $key, array $payload): array
    {
        $from = TicketPriority::tryFrom((string) ($payload['from'] ?? ''))?->label();
        $to = TicketPriority::tryFrom((string) ($payload['to'] ?? ''))?->label();

        $description = $from !== null && $to !== null
            ? 'changed the priority of '.$key.' from '.$from.' to '.$to
            : 'changed the priority of '.$key;

        return [$description, [
            'from' => $from,
            'to' => $to,
            'old' => ['priority' => $this->text($payload['from'] ?? null)],
            'attributes' => ['priority' => $this->text($payload['to'] ?? null)],
        ]];
    }

    /**
     * "changed the labels on AQD-142 — added Bug, removed Chore".
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describeLabels(string $key, array $payload): array
    {
        $added = $this->names($payload['added'] ?? []);
        $removed = $this->names($payload['removed'] ?? []);

        $parts = array_filter([
            $added === [] ? null : 'added '.implode(', ', $added),
            $removed === [] ? null : 'removed '.implode(', ', $removed),
        ]);

        $description = 'changed the labels on '.$key
            .($parts === [] ? '' : ' — '.implode(', ', $parts));

        return [$description, ['added' => $added, 'removed' => $removed]];
    }

    /**
     * The catch-all edit: "changed the due date and title of AQD-142".
     *
     * App\Actions\Tickets\UpdateTicket collapses everything without its own
     * event type into one `ticket_updated` carrying a before/after map, so one
     * save that touched three fields is one row here — which is what makes the
     * feed readable. The fields are named in the sentence because "updated
     * AQD-142" tells a reader nothing they could act on.
     *
     * From and to are only offered when exactly one field changed; two arrows
     * in one row is worse than none.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describeUpdate(string $key, array $payload): array
    {
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

        $fields = array_keys($changes);
        $labels = array_map(fn (string $field): string => $this->fieldLabel($field), $fields);

        $description = $labels === []
            ? 'updated '.$key
            : 'changed the '.$this->sentenceList($labels).' of '.$key;

        $properties = [
            'fields' => $labels,
            'old' => [],
            'attributes' => [],
        ];

        foreach ($changes as $field => $change) {
            $properties['old'][$field] = is_array($change) ? ($change['from'] ?? null) : null;
            $properties['attributes'][$field] = is_array($change) ? ($change['to'] ?? null) : null;
        }

        if (count($fields) === 1) {
            $only = $changes[$fields[0]] ?? [];

            $properties['from'] = $this->text(is_array($only) ? ($only['from'] ?? null) : null);
            $properties['to'] = $this->text(is_array($only) ? ($only['to'] ?? null) : null);
        }

        return [$description, $properties];
    }

    // ---------------------------------------------------------------------
    // Boards
    // ---------------------------------------------------------------------

    public function boardCreated(Board $board, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::BoardCreated,
            'created the board '.$this->trim($board->name),
            $board,
            (int) $board->getKey(),
            ['name' => $this->trim($board->name), 'slug' => $board->slug],
            $actor,
        );
    }

    /**
     * Whatever a board edit actually did.
     *
     * One save can rename a board, archive it and change its Slack webhook, and
     * those are three different things to a reader — so this returns a list
     * rather than a single row. It is still not a duplicate: each row describes
     * a distinct change, and a save that changed nothing produces none.
     *
     * `settings` is handled apart from everything else and its *values are
     * never stored*. A board's settings include an encrypted Slack webhook URL
     * and an SMS recipient list; the fact that somebody changed the Slack
     * configuration is worth recording, and what they changed it to is not
     * something an activity feed should be holding in a JSON column.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [from, to]
     * @return array<int, Activity>
     */
    public function boardChanged(Board $board, array $changes, ?User $actor = null): array
    {
        $written = [];
        $boardId = (int) $board->getKey();
        $plain = [];

        foreach ($changes as $field => [$from, $to]) {
            if ($field === 'settings') {
                $groups = $this->settingGroups($from, $to);

                $written[] = $this->write(
                    ActivityType::BoardSettingsChanged,
                    $groups === []
                        ? 'changed the settings of '.$this->trim($board->name)
                        : 'changed the '.$this->sentenceList($groups).' settings of '.$this->trim($board->name),
                    $board,
                    $boardId,
                    // Group names only. See the method comment.
                    ['groups' => $groups],
                    $actor,
                );

                continue;
            }

            if ($field === 'archived_at') {
                $archived = $to !== null;

                $written[] = $this->write(
                    $archived ? ActivityType::BoardArchived : ActivityType::BoardRestored,
                    ($archived ? 'archived the board ' : 'restored the board ').$this->trim($board->name),
                    $board,
                    $boardId,
                    ['name' => $this->trim($board->name)],
                    $actor,
                );

                continue;
            }

            $plain[$field] = [$from, $to];
        }

        if ($plain !== []) {
            $written[] = $this->write(
                ActivityType::BoardUpdated,
                $this->describeBoardUpdate($board, $plain),
                $board,
                $boardId,
                [
                    'fields' => array_map(fn (string $f): string => $this->fieldLabel($f), array_keys($plain)),
                    'from' => isset($plain['name']) ? $this->text($plain['name'][0]) : null,
                    'to' => isset($plain['name']) ? $this->text($plain['name'][1]) : null,
                    'old' => array_map(fn (array $c) => $c[0], $plain),
                    'attributes' => array_map(fn (array $c) => $c[1], $plain),
                ],
                $actor,
            );
        }

        return array_values(array_filter($written));
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function describeBoardUpdate(Board $board, array $changes): string
    {
        if (isset($changes['name'])) {
            $from = $this->text($changes['name'][0]);
            $to = $this->text($changes['name'][1]);

            if ($from !== null && $to !== null) {
                return 'renamed the board '.$from.' to '.$to;
            }
        }

        $labels = array_map(fn (string $f): string => $this->fieldLabel($f), array_keys($changes));

        return 'changed the '.$this->sentenceList($labels).' of the board '.$this->trim($board->name);
    }

    /**
     * A board being deleted.
     *
     * Filed with no board id, which is what makes it survive: `board_id`
     * cascades, so a row pointing at this board would be removed by the same
     * statement that removes the board. A NULL board id means workspace-level,
     * and App\Models\Activity::readableBy() shows those to administrators only —
     * which is the right audience for "somebody deleted a board" anyway.
     *
     * Called before the deletion, so the name is still readable.
     */
    public function boardDeleted(Board $board, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::BoardDeleted,
            'deleted the board '.$this->trim($board->name).' and everything on it',
            null,
            null,
            [
                'name' => $this->trim($board->name),
                'slug' => $board->slug,
                'ticket_prefix' => $board->ticket_prefix,
                'board_id' => (int) $board->getKey(),
            ],
            $actor,
        );
    }

    // ---------------------------------------------------------------------
    // Membership
    // ---------------------------------------------------------------------

    public function memberAdded(Board $board, User $member, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::MemberAdded,
            'added '.$this->trim($member->name).' to the board',
            $member,
            (int) $board->getKey(),
            $this->memberProperties($member),
            $actor,
        );
    }

    public function memberRemoved(Board $board, User $member, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::MemberRemoved,
            'removed '.$this->trim($member->name).' from the board',
            $member,
            (int) $board->getKey(),
            $this->memberProperties($member),
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function memberProperties(User $member): array
    {
        return [
            'member' => [
                'id' => (int) $member->getKey(),
                'name' => $this->trim($member->name),
                'role' => $member->role->value,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Conversation
    // ---------------------------------------------------------------------

    /**
     * A comment.
     *
     * The body is never stored. A description saying which ticket was commented
     * on is what a feed needs; a copy of an internal note in a second table is
     * a second place it can leak from, and it would go stale the moment the
     * comment was edited.
     *
     * The stream is named in the sentence because "commented" and "added an
     * internal note" are different events to anyone reading a delivery feed.
     */
    public function commentPosted(Comment $comment, ?Ticket $ticket = null, ?User $actor = null): ?Activity
    {
        $isCustomerFacing = $comment->stream instanceof CommentStream
            && $comment->stream->isCustomerFacing();

        return $this->comment(
            ActivityType::CommentCreated,
            $comment,
            $ticket,
            // One type, two sentences. The stream is a property of the comment,
            // not a different kind of event, and giving each its own type would
            // put two near-identical rows in the filter dropdown for a
            // distinction the sentence already makes.
            $isCustomerFacing ? 'replied to the customer on ' : 'added an internal note on ',
            $actor,
        );
    }

    public function commentEdited(Comment $comment, ?Ticket $ticket = null, ?User $actor = null): ?Activity
    {
        return $this->comment(ActivityType::CommentEdited, $comment, $ticket, 'edited a comment on ', $actor);
    }

    public function commentDeleted(Comment $comment, ?Ticket $ticket = null, ?User $actor = null): ?Activity
    {
        return $this->comment(ActivityType::CommentDeleted, $comment, $ticket, 'deleted a comment on ', $actor);
    }

    /**
     * The ticket is a parameter rather than always loaded, because the one
     * caller that has it (App\Actions\Comments\PostComment) should not pay for
     * a query to fetch what it is holding. The others pass null and it is
     * resolved here, once.
     */
    private function comment(
        ActivityType $type,
        Comment $comment,
        ?Ticket $ticket,
        string $prefix,
        ?User $actor,
    ): ?Activity {
        if ($ticket === null) {
            $comment->loadMissing('ticket');
            $ticket = $comment->ticket;
        }

        if (! $ticket instanceof Ticket) {
            return null;
        }

        $key = $this->ticketKey($ticket);

        return $this->write(
            $type,
            $prefix.$key,
            $comment,
            (int) $comment->board_id,
            $this->commentProperties($comment) + $this->ticketContext($ticket, $key),
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commentProperties(Comment $comment): array
    {
        $stream = $comment->stream instanceof CommentStream
            ? $comment->stream->value
            : null;

        return ['comment_id' => (int) $comment->getKey(), 'stream' => $stream];
    }

    // ---------------------------------------------------------------------
    // Documentation
    // ---------------------------------------------------------------------

    public function pageCreated(DocPage $page, ?User $actor = null): ?Activity
    {
        return $this->page(ActivityType::PageCreated, $page, 'created the documentation page ', $actor);
    }

    public function pageUpdated(DocPage $page, ?User $actor = null): ?Activity
    {
        return $this->page(ActivityType::PageUpdated, $page, 'updated the documentation page ', $actor);
    }

    public function pageMoved(DocPage $page, ?User $actor = null): ?Activity
    {
        return $this->page(ActivityType::PageMoved, $page, 'moved the documentation page ', $actor);
    }

    /**
     * A page and its descendants being removed.
     *
     * The count is in the sentence when it is more than one, because deleting a
     * parent takes its children with it and "deleted 1 page" reads as a much
     * smaller act than it was.
     */
    public function pageDeleted(DocPage $page, int $removed, ?User $actor = null): ?Activity
    {
        $suffix = $removed > 1
            ? ' and '.($removed - 1).' '.Str::plural('page', $removed - 1).' below it'
            : '';

        return $this->write(
            ActivityType::PageDeleted,
            'deleted the documentation page '.$this->quoted($page->title).$suffix,
            $page,
            (int) $page->board_id,
            ['title' => $this->trim($page->title), 'slug' => $page->slug, 'pages_removed' => $removed],
            $actor,
        );
    }

    /**
     * Publishing or retracting a page.
     *
     * A separate type from an ordinary edit because it is the documentation
     * equivalent of a ticket's visibility change: it decides whether a customer
     * can read the page at all, and it is the change somebody will want to find
     * later.
     */
    public function pageVisibilityChanged(
        DocPage $page,
        bool $customerVisible,
        int $affected = 1,
        ?User $actor = null,
    ): ?Activity {
        $suffix = $affected > 1
            ? ' and '.($affected - 1).' '.Str::plural('page', $affected - 1).' below it'
            : '';

        return $this->write(
            $customerVisible ? ActivityType::PagePublished : ActivityType::PageRetracted,
            ($customerVisible
                ? 'published the documentation page '
                : 'made the documentation page ')
                .$this->quoted($page->title)
                .($customerVisible ? ' to the customer' : ' internal only')
                .$suffix,
            $page,
            (int) $page->board_id,
            [
                'title' => $this->trim($page->title),
                'slug' => $page->slug,
                'from' => $customerVisible ? 'Internal' : 'Customer-visible',
                'to' => $customerVisible ? 'Customer-visible' : 'Internal',
                'pages_affected' => $affected,
            ],
            $actor,
        );
    }

    private function page(ActivityType $type, DocPage $page, string $prefix, ?User $actor): ?Activity
    {
        return $this->write(
            $type,
            $prefix.$this->quoted($page->title),
            $page,
            (int) $page->board_id,
            ['title' => $this->trim($page->title), 'slug' => $page->slug],
            $actor,
        );
    }

    // ---------------------------------------------------------------------
    // Board configuration
    // ---------------------------------------------------------------------

    public function columnCreated(BoardColumn $column, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::ColumnCreated,
            'added the column '.$this->trim($column->name),
            $column,
            (int) $column->board_id,
            ['name' => $this->trim($column->name), 'is_done' => (bool) $column->is_done],
            $actor,
        );
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [from, to]
     */
    public function columnUpdated(BoardColumn $column, array $changes, ?User $actor = null): ?Activity
    {
        if ($changes === []) {
            return null;
        }

        $from = isset($changes['name']) ? $this->text($changes['name'][0]) : null;
        $to = isset($changes['name']) ? $this->text($changes['name'][1]) : null;

        $description = $from !== null && $to !== null
            ? 'renamed the column '.$from.' to '.$to
            : 'changed the '.$this->sentenceList(array_map(
                fn (string $f): string => $this->fieldLabel($f),
                array_keys($changes)
            )).' of the column '.$this->trim($column->name);

        return $this->write(
            ActivityType::ColumnUpdated,
            $description,
            $column,
            (int) $column->board_id,
            [
                'from' => $from,
                'to' => $to,
                'old' => array_map(fn (array $c) => $c[0], $changes),
                'attributes' => array_map(fn (array $c) => $c[1], $changes),
            ],
            $actor,
        );
    }

    /**
     * A column being removed.
     *
     * The subject is the board rather than the column: the column row is about
     * to disappear, and a subject id that dangles the instant it is written is
     * less use than one pointing at the thing that still exists. The tickets
     * that had to move out first are recorded separately, as movements, by
     * App\Actions\Columns\DeleteColumn going through TicketActivity — so the
     * feed shows both the removal and where the work went.
     */
    public function columnDeleted(
        Board $board,
        string $name,
        ?BoardColumn $destination = null,
        ?User $actor = null,
    ): ?Activity {
        $description = 'removed the column '.$this->trim($name)
            .($destination === null ? '' : ', moving its tickets to '.$this->trim($destination->name));

        return $this->write(
            ActivityType::ColumnDeleted,
            $description,
            $board,
            (int) $board->getKey(),
            ['name' => $this->trim($name), 'moved_to' => $destination?->name],
            $actor,
        );
    }

    public function columnsReordered(Board $board, ?User $actor = null): ?Activity
    {
        return $this->write(
            ActivityType::ColumnsReordered,
            'reordered the columns on the board '.$this->trim($board->name),
            $board,
            (int) $board->getKey(),
            [],
            $actor,
        );
    }

    // ---------------------------------------------------------------------
    // The write itself
    // ---------------------------------------------------------------------

    /**
     * Write one row.
     *
     * Everything above funnels through here, which is what keeps `log_name`,
     * `event` and `board_id` consistent — they are derived from the type rather
     * than passed in, so no caller can file an activity under the wrong
     * category or forget the board.
     *
     * Uses the package's fluent builder rather than Activity::create(), so the
     * `activitylog.enabled` switch, the causer resolver and future package
     * behaviour all apply. `tap()` is the sanctioned hook for the one column
     * this application added.
     *
     * @param  array<string, mixed>  $properties
     */
    private function write(
        ActivityType $type,
        string $description,
        ?Model $subject,
        ?int $boardId,
        array $properties,
        ?User $causer,
    ): ?Activity {
        $logger = activity($type->category()->value)
            ->event($type->value)
            // Nulls are dropped so a template can ask `property('from')` and
            // get a real answer rather than having to tell "absent" from
            // "explicitly nothing".
            ->withProperties(array_filter(
                $properties,
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ))
            ->tap(static function (Model $activity) use ($boardId): void {
                $activity->board_id = $boardId;
            });

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        // A null causer leaves the package's resolver in place, which picks up
        // the authenticated user. Actions called from a console command or a
        // queued job pass the actor explicitly instead.
        $logger->causedBy($causer);

        $activity = $logger->log($this->safeDescription($description));

        return $activity instanceof Activity ? $activity : null;
    }

    /**
     * Neutralise the package's description placeholders.
     *
     * ActivityLogger::log() runs every description through a substitution pass
     * that replaces `:subject.x`, `:causer.x` and `:properties.x` with values
     * from the row. Descriptions here are composed from ticket and page titles,
     * which are user input — so a page actually titled ":subject.id" would
     * rewrite its own history entry. Breaking the token with a zero-width
     * boundary is not an option in a searchable column; dropping the leading
     * colon is, and it costs one character in a case nobody will ever hit
     * honestly.
     */
    private function safeDescription(string $description): string
    {
        return (string) preg_replace(
            '/:(subject|causer|properties)\./i',
            '$1.',
            $description
        );
    }

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    /**
     * Context every ticket-shaped row carries, so the feed can link to the
     * ticket and still render it after the ticket is gone.
     *
     * @return array<string, mixed>
     */
    private function ticketContext(Ticket $ticket, string $key): array
    {
        return [
            'ticket' => [
                'id' => (int) $ticket->getKey(),
                'key' => $key,
                'number' => (int) $ticket->number,
                'title' => $this->trim($ticket->title),
                'board_slug' => $ticket->board?->slug,
            ],
        ];
    }

    /**
     * The human key, e.g. "AQD-42".
     *
     * loadMissing rather than `$ticket->board`: strict mode forbids implicit
     * lazy loading, and this is reached from actions that had no reason to
     * eager load the board. At worst it is one extra query per ticket per
     * request, and the model caches it for every subsequent call.
     */
    private function ticketKey(Ticket $ticket): string
    {
        $ticket->loadMissing('board');

        return $ticket->board === null
            ? '#'.$ticket->number
            : $ticket->board->ticket_prefix.'-'.$ticket->number;
    }

    /**
     * Which groups of board settings a save touched — never their values.
     *
     * @return array<int, string>
     */
    private function settingGroups(mixed $from, mixed $to): array
    {
        $before = is_array($from) ? $from : [];
        $after = is_array($to) ? $to : [];

        $groups = [];

        foreach (array_keys($after) as $key) {
            if (! array_key_exists($key, $before) || $before[$key] !== $after[$key]) {
                $groups[] = $this->fieldLabel((string) $key);
            }
        }

        foreach (array_keys($before) as $key) {
            if (! array_key_exists($key, $after)) {
                $groups[] = $this->fieldLabel((string) $key);
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * A column or setting name as a person would say it: `description_md`
     * becomes "description", `due_date` becomes "due date", `ai` stays "AI".
     */
    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'description_md' => 'description',
            'body_md' => 'body',
            'ticket_prefix' => 'ticket prefix',
            'due_date' => 'due date',
            'is_done' => 'done marker',
            'ai' => 'AI',
            'sms' => 'SMS',
            'slack' => 'Slack',
            default => str_replace('_', ' ', $field),
        };
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  array<int, string>  $items
     */
    private function sentenceList(array $items): string
    {
        $items = array_values(array_filter($items, static fn ($item): bool => trim((string) $item) !== ''));

        if ($items === []) {
            return 'details';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    private function userName(mixed $id): ?string
    {
        $id = $this->intOrNull($id);

        if ($id === null) {
            return null;
        }

        $name = User::query()->whereKey($id)->value('name');

        return $name === null ? null : $this->trim((string) $name);
    }

    /**
     * @return array<int, string>
     */
    private function names(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => $this->text($value),
            $values
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function aiSuffix(array $payload): string
    {
        $mode = $this->text($payload['mode'] ?? null);

        return $mode === null ? '' : ' ('.$mode.' mode)';
    }

    /**
     * AI context, minus the failure reason, which can carry a provider message
     * and belongs in the ticket timeline rather than in a workspace feed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function aiProperties(array $payload): array
    {
        return [
            'run_id' => $this->intOrNull($payload['run_id'] ?? null),
            'mode' => $this->text($payload['mode'] ?? null),
            'trigger' => $this->text($payload['trigger'] ?? null),
        ];
    }

    private function quoted(?string $value): string
    {
        $value = $this->trim((string) $value);

        return $value === '' ? '' : '"'.$value.'"';
    }

    private function trim(string $value): string
    {
        return Str::limit(trim($value), self::MAX_TITLE);
    }

    /**
     * A payload value as displayable text, or null when there is nothing to
     * show. Booleans are excluded on purpose: "1" is not a sentence, and every
     * caller that stores one describes it in words instead.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $this->trim($text);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
