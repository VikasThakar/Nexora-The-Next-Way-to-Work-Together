<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Mcp\Tools\Write\Concerns\DescribesTickets;

/**
 * Raise one ticket, with everything asked for set on it.
 *
 * Staff may set every field. A customer may raise a ticket on a board they
 * are on, as on the New ticket form — title, description, type, priority —
 * and CreateTicket applies the customer rules it always has: the first
 * column, nobody assigned, visible to the customer.
 */
final class CreateTicketTool extends WriteTool
{
    use DescribesTickets;

    protected function toolName(): string
    {
        return 'create_ticket';
    }

    protected function describe(): string
    {
        return $this->context->staff
            ? 'Create a ticket on a board, with any of its fields set in one go: description, type, priority, status (the board column), assignee, labels, due date and estimate. New tickets follow the board\'s visibility default; pass visible_to_customer false for internal work. To create several at once, use create_tickets.'
            : 'Raise a ticket on one of your boards: a title, and optionally a description, type and priority. The team sees it straight away.';
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
        $board = ['type' => 'string', 'maxLength' => 120, 'description' => 'The board: its name, slug or ticket prefix (e.g. "Aqueduct Platform", "aqueduct-platform" or "AQD").'];

        if (! $this->context->staff) {
            return [
                'type' => 'object',
                'properties' => [
                    'board' => $board,
                    'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'A short summary, at most 200 characters.'],
                    'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'The details, as Markdown.'],
                    'type' => ['type' => 'string', 'enum' => TicketType::values(), 'description' => 'bug, task or feature.'],
                    'priority' => ['type' => 'string', 'enum' => TicketPriority::values(), 'description' => 'critical, high, medium, low or nice_to_have.'],
                ],
                'required' => ['board', 'title'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'board' => $board,
                ...self::staffTicketFields(),
                'visible_to_customer' => ['type' => 'boolean', 'description' => 'Pass false for internal work (security, credentials, anything about another customer). Omit to follow the board\'s default; true cannot make it more visible than the board allows.'],
            ],
            'required' => ['board', 'title'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $board = $this->board($input);

        $this->guard($board);

        $result = $this->perform(
            AiActionType::CreateTicket,
            $board,
            self::asActionInput(array_diff_key($input, ['board' => true])),
        );

        $ticket = $this->ticketByKey($board, (string) $result['target']);

        return WriteOutcome::ok($this->ticketCard($ticket, 'Ticket created'), $ticket->key(), $board, $result['label']);
    }
}
