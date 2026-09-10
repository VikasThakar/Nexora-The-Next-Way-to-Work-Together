<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSubtask;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\CommentReader;
use App\Services\GitHub\GithubLinkReader;
use Illuminate\Support\Str;

/**
 * One ticket, in as much detail as the asker may see.
 *
 * The most-used tool in the set, and the one that makes "why is this blocked?"
 * answerable: the board context block is a list of titles, and a real answer
 * needs the description, the conversation and the history.
 *
 * Every section is read through the reader that governs it, with the asking
 * person as the viewer:
 *
 *   the ticket        TicketFinder      board membership, customer boundary
 *   the conversation  CommentReader     internal notes hidden from customers
 *   the history       TicketEvent::readableBy   internal event types dropped
 *   the code          GithubLinkReader  staff only, refuses customers in SQL
 *
 * So a customer asking about their own ticket gets the description, the
 * customer-facing conversation and the visible history — and no internal note,
 * no visibility change and no branch name. That is not a filter applied here;
 * it is what those readers return.
 */
class GetTicketTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    public function name(): string
    {
        return 'get_ticket';
    }

    public function description(): string
    {
        return 'Read one ticket in full: description, status, assignee, labels, priority, due date, '
            .'checklist, recent conversation and recent history. Use this whenever a question is about '
            .'a specific ticket, including when the person says "this ticket" and the current page is a '
            .'ticket. Accepts a prefixed key like AQD-42, or a bare number together with a board slug. '
            .'It returns only what the person asking is allowed to see, so a result that omits internal '
            .'notes is not an error.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticket' => [
                    'type' => 'string',
                    'maxLength' => 32,
                    'description' => 'The ticket key, e.g. AQD-42, or the bare number if you also pass a board.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug. Only needed when the ticket is given as a bare number.',
                ],
            ],
            'required' => ['ticket'],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        // Everyone who can use the assistant at all. What differs between a
        // customer and a member of staff is what comes back, not whether the
        // tool exists.
        return true;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $ticket = $this->ticketFrom($input, $context);

        if (! $ticket instanceof Ticket) {
            return AiToolOutcome::notFound(
                'No ticket matches that reference, or it is not one this person can see. '
                .'If they gave a bare number, ask which board it is on.',
                isset($input['ticket']) ? (string) $input['ticket'] : null,
            );
        }

        $ticket->loadMissing(['board', 'column', 'assignee', 'labels', 'subtasks']);

        $lines = [
            'TICKET '.$ticket->key().' on board "'.$ticket->board->name.'" (slug: '.$ticket->board->slug.')',
            'Title: '.$ticket->title,
            'Status column: '.($ticket->column?->name ?? 'not in a column'),
            'Type: '.($ticket->type?->label() ?? 'not set'),
            'Priority: '.($ticket->priority?->label() ?? 'not set'),
            'Assignee: '.($ticket->assignee?->name ?? 'nobody'),
        ];

        if ($ticket->labels->isNotEmpty()) {
            $lines[] = 'Labels: '.$ticket->labels->pluck('name')->implode(', ');
        }

        if ($ticket->due_date !== null) {
            $lines[] = 'Due: '.$ticket->due_date->toDateString()
                .($ticket->due_date->isPast() ? ' (in the past)' : '');
        }

        if ($ticket->estimate !== null) {
            $lines[] = 'Estimate: '.$ticket->estimate;
        }

        $lines[] = 'Created: '.($ticket->created_at?->toDateTimeString() ?? 'unknown');
        $lines[] = 'Last updated: '.($ticket->updated_at?->toDateTimeString() ?? 'unknown');

        // Stated only when it is a fact worth stating. A customer never
        // reaches an internal ticket, so this line is staff-only in practice.
        if (! $ticket->customer_visible) {
            $lines[] = 'Visibility: internal — the customer cannot see this ticket.';
        }

        $description = $this->excerpt($ticket->description_md, 2500);

        $lines[] = '';
        $lines[] = 'DESCRIPTION';
        $lines[] = $description === '' ? '(empty)' : $description;

        $checklist = $this->checklist($ticket);

        if ($checklist !== null) {
            $lines[] = '';
            $lines[] = $checklist;
        }

        $conversation = $this->conversation($ticket, $context);

        if ($conversation !== null) {
            $lines[] = '';
            $lines[] = $conversation;
        }

        $history = $this->history($ticket, $context);

        if ($history !== null) {
            $lines[] = '';
            $lines[] = $history;
        }

        $code = $this->code($ticket, $context);

        if ($code !== null) {
            $lines[] = '';
            $lines[] = $code;
        }

        return AiToolOutcome::ok(implode("\n", $lines), $ticket->key());
    }

    // -----------------------------------------------------------------

    private function checklist(Ticket $ticket): ?string
    {
        if ($ticket->subtasks->isEmpty()) {
            return null;
        }

        $lines = ['CHECKLIST'];

        foreach ($ticket->subtasks->take(40) as $subtask) {
            /** @var TicketSubtask $subtask */
            $lines[] = '- ['.($subtask->completed ? 'x' : ' ').'] '.Str::limit((string) $subtask->title, 160, '…');
        }

        return implode("\n", $lines);
    }

    /**
     * The recent conversation, in whichever streams this viewer may read.
     *
     * CommentReader decides which those are. For staff that includes the
     * internal notes, which is where the actual reason a ticket is stuck is
     * usually written down; for a customer it is the customer conversation
     * only, and the internal notes do not come back at all.
     */
    private function conversation(Ticket $ticket, AiToolContext $context): ?string
    {
        $comments = app(CommentReader::class)->query($ticket, $context->user)
            ->with(['author'])
            ->orderByDesc('comments.created_at')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        if ($comments->isEmpty()) {
            return null;
        }

        $lines = ['CONVERSATION (most recent last, only what this person may read)'];

        foreach ($comments as $comment) {
            /** @var Comment $comment */
            $lines[] = sprintf(
                '- %s · %s · %s: %s',
                $comment->created_at?->toDateTimeString() ?? '',
                $comment->author?->name ?? 'The workspace',
                $comment->stream->label(),
                // The stored Markdown, not the rendered HTML. A model reads
                // Markdown better than tags, and there is no render to pay for.
                Str::limit(trim((string) $comment->body_md), 500, '…'),
            );
        }

        return implode("\n", $lines);
    }

    private function history(Ticket $ticket, AiToolContext $context): ?string
    {
        $events = TicketEvent::query()
            ->readableBy($context->user)
            ->where('ticket_events.ticket_id', $ticket->getKey())
            ->with('actor')
            ->orderByDesc('ticket_events.created_at')
            ->limit(15)
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $lines = ['HISTORY (most recent first)'];

        foreach ($events as $event) {
            /** @var TicketEvent $event */
            $line = sprintf(
                '- %s: %s %s',
                $event->created_at?->toDateTimeString() ?? '',
                $event->actor?->name ?? 'The workspace',
                $event->type->label(),
            );

            /*
             * The from/to of a move is the whole point of the row for a
             * question like "why did this go back to In Progress". The column
             * names are read from the payload rather than joined from
             * `board_columns`: they are what the columns were called at the
             * time, and a column since renamed should read as it did then.
             */
            $payload = (array) $event->payload;
            $from = $payload['from_column'] ?? null;
            $to = $payload['to_column'] ?? null;

            if (is_scalar($from) || is_scalar($to)) {
                $line .= ' (from '.($from === null ? 'nothing' : (string) $from)
                    .' to '.($to === null ? 'nothing' : (string) $to).')';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Branches, commits and pull requests. Staff only.
     *
     * Read through GithubLinkReader, whose scope refuses customers outright
     * rather than filtering a column — so this section is absent for a
     * customer because the query returns nothing, not because of a branch here.
     */
    private function code(Ticket $ticket, AiToolContext $context): ?string
    {
        $links = app(GithubLinkReader::class)->forTicket($ticket, $context->user, 15);

        if ($links->isEmpty()) {
            return null;
        }

        $lines = ['LINKED CODE'];

        foreach ($links as $link) {
            $lines[] = '- '.$link->type->label().' '.$link->shortReference()
                // Nullable in the schema: a commit has no state.
                .' · '.($link->state?->label() ?? 'no state')
                .($link->title !== null ? ' · '.Str::limit((string) $link->title, 140, '…') : '');
        }

        return implode("\n", $lines);
    }
}
