<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use App\Mcp\Tools\Write\Concerns\DescribesTickets;
use App\Services\AI\Tools\AiToolInput;
use App\Services\AI\Tools\AiToolInputException;

/**
 * Several tickets on one board, all or none.
 *
 * The shared write path prepares — authorizes and resolves — every ticket
 * before creating any, so an unknown status on the seventh means no tickets
 * and an answer naming the seventh.
 */
final class CreateTicketsTool extends WriteTool
{
    use DescribesTickets;

    protected function toolName(): string
    {
        return 'create_tickets';
    }

    protected function describe(): string
    {
        return 'Create up to '.AiActionType::maxTicketsPerReply().' tickets on one board in one call — for "turn this list into tickets". Each takes the same fields as create_ticket. Either every ticket is created, numbered in the order given, or none is and the answer says which one was the problem.';
    }

    protected function addsSomething(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The board for all of them: its name, slug or ticket prefix. One board per call.'],
                'tickets' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => AiActionType::maxTicketsPerReply(),
                    'description' => 'The tickets, in the order they should be numbered.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            ...self::staffTicketFields(),
                            'visible_to_customer' => ['type' => 'boolean', 'description' => 'False for internal work. Omit to follow the board\'s default.'],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['board', 'tickets'],
        ];
    }

    /**
     * AiToolInput checks flat arguments, so each ticket is checked on its own
     * against the item schema.
     */
    protected function validateInput(array $raw): array
    {
        $schema = $this->inputSchema();
        $tickets = $raw['tickets'] ?? null;

        $top = AiToolInput::validate(
            ['type' => 'object', 'properties' => ['board' => $schema['properties']['board']], 'required' => ['board']],
            array_diff_key($raw, ['tickets' => true]),
        );

        if (! is_array($tickets) || $tickets === [] || ! array_is_list($tickets)) {
            throw AiToolInputException::missing('tickets');
        }

        $max = AiActionType::maxTicketsPerReply();

        if (count($tickets) > $max) {
            throw AiToolInputException::wrongType('tickets', 'a list of at most '.$max.' tickets');
        }

        $top['tickets'] = array_map(
            static fn (mixed $ticket): array => AiToolInput::validate($schema['properties']['tickets']['items'], $ticket),
            $tickets,
        );

        return $top;
    }

    protected function run(array $input): WriteOutcome
    {
        $board = $this->board($input);

        $this->guard($board);

        $result = $this->perform(AiActionType::CreateTickets, $board, [
            'tickets' => array_map(self::asActionInput(...), $input['tickets']),
        ]);

        $rows = ['| Ticket | Title | Status | Assigned to | Priority |', '|---|---|---|---|---|'];

        foreach ($result['items'] ?? [] as $item) {
            $ticket = $this->ticketByKey($board, (string) $item['key']);

            $rows[] = '| '.implode(' | ', [
                McpPresentation::ticketLink($ticket),
                McpPresentation::inline((string) $ticket->title, 80),
                McpPresentation::place($ticket),
                McpPresentation::assignee($ticket->assignee),
                McpPresentation::priority($ticket->priority),
            ]).' |';
        }

        $count = count($result['items'] ?? []);

        return WriteOutcome::ok(
            '✅ **Created '.$count.' '.($count === 1 ? 'ticket' : 'tickets').' on '.McpPresentation::inline((string) $board->name).'**'
            ."\n\n".implode("\n", $rows),
            (string) ($result['target'] ?? ''),
            $board,
            $result['label'],
        );
    }
}
