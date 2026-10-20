<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use Illuminate\Support\Str;

/**
 * Add a comment to a ticket's conversation.
 *
 * A ticket has two threads: the internal one only the team reads, and the
 * customer thread the customer reads. Staff post internal notes by default —
 * the fail-closed reading of "add a note to AQD-42" — and a comment the
 * customer will read needs the person's confirmation first, because it is a
 * message to somebody outside the team. A customer can only ever post on the
 * customer thread, and only where the board lets customers comment.
 */
final class AddCommentTool extends WriteTool
{
    protected function toolName(): string
    {
        return 'add_comment';
    }

    protected function describe(): string
    {
        return $this->context->staff
            ? 'Add a comment to a ticket. By default it is an internal note only the team sees. Pass visible_to_customer true only when the person wants the customer to read it — that answers "confirmation needed" first: show them the exact text, and call again with the code once they agree.'
            : 'Reply on a ticket you can see. The team reads it straight away.';
    }

    protected function forCustomers(): bool
    {
        return true;
    }

    protected function addsSomething(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        $properties = [
            'ticket' => ['type' => 'string', 'maxLength' => 24, 'description' => 'The ticket key, e.g. AQD-42.'],
            'body' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'The comment, as Markdown.'],
        ];

        if ($this->context->staff) {
            $properties['visible_to_customer'] = ['type' => 'boolean', 'description' => 'True to post on the customer thread, which the customer reads. Defaults to false: an internal note.'];
            $properties['confirmation'] = ['type' => 'string', 'maxLength' => 12, 'description' => 'The code from a "confirmation needed" answer, once the person has agreed. Only for comments the customer will see.'];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => ['ticket', 'body']];
    }

    protected function run(array $input): WriteOutcome
    {
        $ticket = $this->ticket($input);
        $board = $ticket->board;

        $this->guard($board);

        // A customer's comment is always on the customer thread; PostComment
        // insists on that too.
        $toCustomer = ! $this->context->staff || ($input['visible_to_customer'] ?? false) === true;

        // On an internal ticket the customer thread is not read by anybody
        // yet — only once the ticket is shared. Said so, rather than claimed.
        $readers = $ticket->customer_visible
            ? 'the **customer will read**'
            : 'on the **customer thread** — the ticket is internal now, so customers will read it only if it is shared —';

        if ($this->context->staff && $toCustomer) {
            $pending = $this->confirmation($input, $ticket->key(), $board,
                '💬 This posts a comment '.$readers.' on '.McpPresentation::ticketLink($ticket)
                .' '.McpPresentation::inline((string) $ticket->title).":\n\n> "
                .str_replace("\n", "\n> ", Str::limit(trim((string) $input['body']), 1500))
            );

            if ($pending !== null) {
                return $pending;
            }
        }

        $result = $this->perform(AiActionType::CommentTicket, $board, [
            'number' => $ticket->number,
            'body_md' => $input['body'],
            'customer_visible' => $toCustomer,
        ]);

        $where = match (true) {
            ! $this->context->staff => 'the team will see it',
            ! $toCustomer => '🔒 internal note — only the team sees it',
            (bool) $ticket->customer_visible => '👁️ on the customer thread — the customer can read it',
            default => '👁️ on the customer thread — the ticket is internal, so customers see it only if it is shared',
        };

        return WriteOutcome::ok(
            '✅ **Comment posted on '.McpPresentation::ticketLink($ticket).' '.McpPresentation::inline((string) $ticket->title).'** — '.$where
            ."\n\n> ".str_replace("\n", "\n> ", Str::limit(trim((string) $input['body']), 300)),
            $ticket->key(),
            $board,
            $result['label'],
        );
    }
}
