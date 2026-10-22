<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a ticket, permanently — and only after the person has confirmed.
 *
 * The one thing these tools do that cannot be undone, so it never happens on
 * the first call. The first call checks the person may delete the ticket at
 * all (no code is offered for something they could not do anyway), then
 * answers with what would be lost and a one-time code; only a second call
 * with that code deletes it. Annotated destructive, so the AI app itself asks
 * the person too.
 */
final class DeleteTicketTool extends WriteTool
{
    protected function toolName(): string
    {
        return 'delete_ticket';
    }

    protected function describe(): string
    {
        return 'Permanently delete a ticket, with its comments, checklist, history and files. It cannot be undone. The first call deletes nothing: it answers "confirmation needed" with what will be lost. Show that to the person, and only if they say yes, call again with the confirmation code.';
    }

    protected function destructive(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticket' => ['type' => 'string', 'maxLength' => 24, 'description' => 'The ticket key, e.g. AQD-42.'],
                'confirmation' => ['type' => 'string', 'maxLength' => 12, 'description' => 'The code from the "confirmation needed" answer, once the person has said yes.'],
            ],
            'required' => ['ticket'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $ticket = $this->ticket($input);
        $board = $ticket->board;

        $this->guard($board);

        Gate::forUser($this->context->user)->authorize('delete', $ticket);

        $comments = $ticket->comments()->count();
        $subtasks = $ticket->subtasks()->count();
        $key = $ticket->key();
        $title = McpPresentation::inline((string) $ticket->title, 200);

        $pending = $this->confirmation($input, $key, $board,
            '🗑️ This **permanently deletes** '.McpPresentation::ticketLink($ticket).' '.$title
            .' ('.McpPresentation::place($ticket).', '.McpPresentation::assignee($ticket->assignee).') — with its '
            .$comments.' '.($comments === 1 ? 'comment' : 'comments').', '
            .$subtasks.' checklist '.($subtasks === 1 ? 'item' : 'items')
            .', history and files. It cannot be undone.'
        );

        if ($pending !== null) {
            return $pending;
        }

        $result = $this->perform(AiActionType::DeleteTicket, $board, ['number' => $ticket->number]);

        return WriteOutcome::ok(
            '🗑️ **Deleted '.$key.'** “'.$title.'” from '.McpPresentation::inline((string) $board->name).', permanently.',
            $key,
            $board,
            $result['label'],
        );
    }
}
