<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * How Nexora's answers look in Claude and ChatGPT.
 *
 * The request was plain: when an AI app shows tickets, show them the way a
 * person scans them — a status you can see at a glance, who has it, a table
 * when there are several — in the chat, not in an artifact. Markdown is the
 * one format every client renders, and colour in Markdown means emoji, so
 * that is what this produces.
 *
 * Three things live here so they cannot drift apart:
 *
 *   the server instructions' display rules, sent once when a client connects;
 *   the badges the tools put in their own output — computed from the real
 *   column, priority and type, so a model is never asked to guess which
 *   emoji a status deserves and never shown one the data does not support;
 *   a one-line display hint at the end of a result, for clients that do not
 *   show the model the server instructions.
 *
 * MCP only. The in-app assistant renders its own way and its tool output is
 * deliberately untouched by any of this.
 */
final class McpPresentation
{
    /** @var array<int, int> lowest column position per board id, for this request */
    private static array $firstPositions = [];

    // -----------------------------------------------------------------
    // Badges
    // -----------------------------------------------------------------

    /**
     * A ticket's status: its column's own name with a colour.
     *
     * The colour is derived, never stored: green for a column the board
     * counts as done, red for one that says blocked or on hold, purple for
     * review and testing, white for where work has not started (the board's
     * first column, or a backlog / to-do name), and yellow for everything in
     * between. The name always stays — a custom column called "With legal"
     * is yellow, and still says "With legal".
     */
    public static function status(?BoardColumn $column): string
    {
        if (! $column instanceof BoardColumn) {
            return '⚪ No status';
        }

        $name = (string) $column->name;
        $lowered = mb_strtolower($name);

        $emoji = match (true) {
            (bool) $column->is_done => '🟢',
            preg_match('/\b(block|blocked|stuck|on hold|hold)\b/u', $lowered) === 1 => '🔴',
            preg_match('/\b(review|reviewing|qa|test|testing|verify|verifying|approval)\b/u', $lowered) === 1 => '🟣',
            preg_match('/\b(backlog|to ?do|todo|new|inbox|icebox|triage|planned|not started|ideas?)\b/u', $lowered) === 1 => '⚪',
            self::isFirstColumn($column) => '⚪',
            default => '🟡',
        };

        return $emoji.' '.$name;
    }

    public static function priority(?TicketPriority $priority): string
    {
        if (! $priority instanceof TicketPriority) {
            return '➖ Medium';
        }

        return match ($priority) {
            TicketPriority::Critical => '🔥',
            TicketPriority::High => '⬆️',
            TicketPriority::Medium => '➖',
            TicketPriority::Low => '⬇️',
            TicketPriority::NiceToHave => '💤',
        }.' '.$priority->label();
    }

    public static function type(?TicketType $type): string
    {
        if (! $type instanceof TicketType) {
            return '📋 Task';
        }

        return match ($type) {
            TicketType::Bug => '🐞',
            TicketType::Feature => '✨',
            TicketType::Task => '📋',
        }.' '.$type->label();
    }

    /**
     * Who has it — and, as plainly, when nobody does.
     */
    public static function assignee(?User $user): string
    {
        return '👤 '.($user instanceof User ? (string) $user->name : 'Unassigned');
    }

    public static function due(?CarbonInterface $date, string $timezone): ?string
    {
        if ($date === null) {
            return null;
        }

        $overdue = $date->toDateString() < now($timezone)->toDateString();

        return ($overdue ? '⚠️ overdue ' : '📅 ').$date->toDateString();
    }

    // -----------------------------------------------------------------
    // Links and text
    // -----------------------------------------------------------------

    public static function ticketUrl(Ticket $ticket): string
    {
        $ticket->loadMissing('board');

        return route('tickets.show', ['board' => $ticket->board, 'number' => $ticket->number]);
    }

    public static function pageUrl(Board $board, DocPage $page): string
    {
        return route('docs.show', ['board' => $board, 'slug' => $page->slug]);
    }

    /**
     * `[AQD-42](https://…)`: the key, clickable.
     */
    public static function ticketLink(Ticket $ticket): string
    {
        return '['.$ticket->key().']('.self::ticketUrl($ticket).')';
    }

    /**
     * Text that is safe inside a Markdown table cell or link label: one line,
     * no pipe to split a column, no bracket to end a link early.
     */
    public static function inline(string $text, int $limit = 140): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return str_replace(['|', '[', ']'], ['\|', '\[', '\]'], Str::limit($text, $limit, '…'));
    }

    /**
     * One ticket on one line, in the vocabulary of the display rules.
     *
     * `[AQD-42](url) — Title · 🟡 In Progress · 👤 Vikas · ⬆️ High · 📅 2026-10-25 · 🔒 internal`
     */
    public static function ticketLine(Ticket $ticket, bool $staff, string $timezone): string
    {
        $ticket->loadMissing(['board', 'column', 'assignee']);

        $parts = [
            self::ticketLink($ticket).' — '.self::inline((string) $ticket->title),
            self::status($ticket->column),
            self::assignee($ticket->assignee),
            self::priority($ticket->priority),
        ];

        if (($due = self::due($ticket->due_date, $timezone)) !== null) {
            $parts[] = $due;
        }

        // Only ever reached for staff: a customer cannot be shown an internal
        // ticket in the first place.
        if ($staff && ! $ticket->customer_visible) {
            $parts[] = '🔒 internal';
        }

        return implode(' · ', $parts);
    }

    /**
     * A timestamp in the workspace's time, with its zone named.
     */
    public static function time(?CarbonInterface $at, string $timezone): string
    {
        return $at === null ? 'unknown' : $at->copy()->setTimezone($timezone)->format('Y-m-d H:i').' ('.$timezone.')';
    }

    public static function timezone(): string
    {
        return (string) config('workspace.board_defaults.timezone', 'UTC');
    }

    // -----------------------------------------------------------------
    // Display hints, at the end of a result
    // -----------------------------------------------------------------

    /**
     * The one-line reminder a list or detail result ends with.
     *
     * The server instructions say all of this at greater length, but not
     * every client shows its model the instructions; a result it has just
     * read, it always has.
     */
    public static function hint(string $kind): string
    {
        $chat = ' Answer in the chat, not in an artifact or document.';

        return 'DISPLAY: '.match ($kind) {
            'ticket' => 'show "🎫 **[KEY](link) Title**", then bullets for Status, Assigned to, Priority, Type, Due, Board and Last updated, then a short **Summary** of facts. Keep each emoji with its words.',
            'board' => 'show the columns as a short list with their emojis and counts; name who can be assigned work.',
            default => 'for 3 or more tickets use a table — Ticket (the linked key) | Title | Status | Assigned to | Priority | Due — keeping each emoji with its words; for 1–2 use bullets. Status and Assigned to are separate columns; write Unassigned when nobody has it.',
        }.$chat;
    }

    // -----------------------------------------------------------------
    // Server instructions
    // -----------------------------------------------------------------

    /**
     * Everything the client's model is told about Nexora once, on connect.
     */
    public static function instructions(User $user, bool $staff, bool $canWrite, string $timezone): string
    {
        $name = (string) $user->name;

        $scope = $staff
            ? 'You act as '.$name.' and can see'.($canWrite ? ' and change' : '').' only what they can on their Nexora boards.'
            : 'You act as '.$name.', a customer, and see only the tickets and pages that have been shared with them.';

        $sections = [
            'Nexora is a delivery workspace: boards of tickets (keys like AQD-42), documentation pages and statistics. '
            .$scope.($canWrite ? ' Every change is recorded in Nexora\'s history as '.$name.' via this app.' : ''),

            "FINDING THINGS\n"
            ."- Finished or completed in a period → get_completed_tickets. Counts, such as how many tickets are open → get_statistics with metric \"totals\". Finding tickets → search_tickets. One ticket's detail → get_ticket. A board's statuses, labels".($staff ? ' and people' : '')." → get_board.\n"
            ."- \"Today\", \"yesterday\" and weeks are reckoned in {$timezone}. Times without an offset are UTC.\n"
            ."- An empty or \"not found\" result means nothing {$name} can see matches. Say that, rather than that it does not exist.",
        ];

        if ($canWrite) {
            $sections[] = $staff
                ? "MAKING CHANGES\n"
                    ."- Act when the request and its target are clear. If two tickets, pages or people could match, ask which one first.\n"
                    ."- A ticket's status is its board column: set status to one of the column names get_board lists.\n"
                    ."- Before editing a ticket, read it with get_ticket and pass its \"Last updated\" as if_unchanged_since, so a change somebody made in the meantime is not overwritten.\n"
                    ."- Deleting a ticket, posting a comment the customer will see, and editing a published page answer \"confirmation needed\" instead of acting: show the person exactly what will happen, wait for their yes, then call again with the confirmation code. Never confirm on their behalf.\n"
                    ."- Report only what the tool reports. Never say something was done unless the tool said it was; if it failed, say so and why."
                : "MAKING CHANGES\n"
                    ."- You can raise tickets, edit the title and description of tickets {$name} raised, and reply on the customer thread. Nothing else can be changed from here.\n"
                    ."- If two tickets could match, ask which one first.\n"
                    ."- Report only what the tool reports. Never say something was done unless the tool said it was; if it failed, say so and why.";
        }

        $legend = '  Status 🟢 done · 🟡 in progress · 🟣 in review · 🔴 blocked · ⚪ not started'."\n"
            .'  Priority 🔥 Critical · ⬆️ High · ➖ Medium · ⬇️ Low · 💤 Nice to have'."\n"
            .'  🐞 Bug · ✨ Feature · 📋 Task · 👤 assigned to · 📅 due · ⚠️ overdue'.($staff ? ' · 🔒 internal' : '');

        $sections[] = "HOW TO SHOW RESULTS\n"
            ."Answer in this chat. Do not create artifacts, canvases, documents, files, widgets, charts or code blocks for ticket and page results unless the person asks for one. Use Markdown: bold labels, bullet lists and tables.\n"
            ."- Lead with the answer in one line, then the detail. No preamble and no closing offer.\n"
            ."- Colour comes from emojis. Keep the ones the tools return, always with the words beside them:\n"
            .$legend."\n"
            ."- One ticket: \"🎫 **[AQD-42](link) Title**\", then bullets: Status, Assigned to, Priority, Type, Due, Board, Last updated. Then **Summary** (two or three factual bullets) and, only if there are real ones, **Next steps**. Label anything you suggest as a suggestion.\n"
            ."- Three or more tickets: a table — Ticket (the linked key) | Title | Status | Assigned to | Priority | Due — with titles kept short. One or two: bullets. More than 20: show 20, give the total and offer the rest.\n"
            ."- \"Who is working on what\": a heading per person (👤 Name · n tickets) with a bullet list under each, and Unassigned last.\n"
            ."- Status and assignee are separate facts: show both, and \"Unassigned\" when nobody has the ticket. Never infer one from the other.\n"
            ."- Use the values exactly as the tools return them. Never invent statuses, people, dates or numbers; leave out a field a tool did not return.\n"
            .($canWrite ? "- After a change: \"✅\" and what happened, then bullets of what changed (old → new) and the link. After a failure: \"❌ Not changed\" and the reason.\n" : '')
            .'- Every result ends with a DISPLAY line: follow it, and do not repeat it to the person.';

        return implode("\n\n", $sections);
    }

    // -----------------------------------------------------------------

    private static function isFirstColumn(BoardColumn $column): bool
    {
        $boardId = (int) $column->board_id;

        if (! array_key_exists($boardId, self::$firstPositions)) {
            self::$firstPositions[$boardId] = (int) BoardColumn::query()
                ->where('board_id', $boardId)
                ->min('position');
        }

        return (int) $column->position === self::$firstPositions[$boardId];
    }

    /**
     * Forget the per-request memo. For tests, which reuse one process.
     */
    public static function flush(): void
    {
        self::$firstPositions = [];
    }
}
