<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write\Concerns;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Mcp\McpPresentation;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\TicketFinder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The ticket fields the write tools take, and how a ticket is described back
 * after a change — always from the saved row, so the answer cannot claim more
 * than happened.
 */
trait DescribesTickets
{
    /**
     * The fields a member of staff may set on a ticket, in this server's
     * vocabulary ("status" rather than "column").
     *
     * @return array<string, mixed>
     */
    protected static function staffTicketFields(): array
    {
        return [
            'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'A short summary, at most 200 characters.'],
            'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'The details, as Markdown.'],
            'type' => ['type' => 'string', 'enum' => TicketType::values(), 'description' => 'bug, task or feature.'],
            'priority' => ['type' => 'string', 'enum' => TicketPriority::values(), 'description' => 'critical, high, medium, low or nice_to_have.'],
            'status' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The status: one of the board\'s column names, exactly as get_board lists them (e.g. "In Progress", "Done").'],
            'assignee' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Who should have it: the name or email of an active team member of the board, or "me". Customers cannot be assigned work.'],
            'labels' => [
                'type' => 'array',
                'maxItems' => 10,
                'items' => ['type' => 'string', 'maxLength' => 60],
                'description' => 'Labels by name. They must already exist on the board (get_board lists them).',
            ],
            'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'The due date, written YYYY-MM-DD. Work out a date said in words ("next Friday") from today\'s date in the workspace timezone.'],
            'estimate' => ['type' => 'number', 'minimum' => 0, 'maximum' => 9999, 'description' => 'The estimate, in the points or hours the team uses.'],
        ];
    }

    /**
     * This server's field names, as the shared write path calls them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function asActionInput(array $input): array
    {
        $renamed = ['description' => 'description_md', 'status' => 'column', 'visible_to_customer' => 'customer_visible'];
        $out = [];

        foreach ($input as $key => $value) {
            $out[$renamed[$key] ?? $key] = $value;
        }

        return $out;
    }

    /**
     * A ticket the shared write path just reported, re-read as this person.
     */
    protected function ticketByKey(Board $board, string $key): Ticket
    {
        $number = (int) substr($key, (int) strrpos($key, '-') + 1);

        try {
            return app(TicketFinder::class)
                ->findOrFail($board, $number, $this->context->user)
                ->load(['board', 'column', 'assignee', 'labels']);
        } catch (NotFoundHttpException) {
            // Saved, but no longer visible to this person — a customer's new
            // ticket on a board that hides them, say. Said plainly.
            throw new \RuntimeException('The change was made, but '.$key.' is not visible to you afterwards.');
        }
    }

    /**
     * What a person scanning the answer wants to know about a ticket.
     *
     * @return array<string, string>
     */
    protected function ticketFacts(Ticket $ticket): array
    {
        $timezone = McpPresentation::timezone();

        $facts = [
            'Status' => McpPresentation::status($ticket->column),
            'Assigned to' => McpPresentation::assignee($ticket->assignee),
            'Priority' => McpPresentation::priority($ticket->priority),
            'Type' => McpPresentation::type($ticket->type),
            'Due' => McpPresentation::due($ticket->due_date, $timezone) ?? '—',
        ];

        if ($this->context->staff) {
            $facts['Estimate'] = $ticket->estimate !== null ? rtrim(rtrim(number_format((float) $ticket->estimate, 2, '.', ''), '0'), '.') : '—';
        }

        $facts['Labels'] = $ticket->labels->isEmpty() ? '—' : $ticket->labels->pluck('name')->implode(', ');

        if ($this->context->staff) {
            $facts['Visibility'] = $ticket->customer_visible ? '👁️ visible to customers' : '🔒 internal';
        }

        return $facts;
    }

    /**
     * `✅ **Ticket created — [AQD-42](…) Title**` and its facts.
     */
    protected function ticketCard(Ticket $ticket, string $headline): string
    {
        $lines = ['✅ **'.$headline.' — '.McpPresentation::ticketLink($ticket).' '.McpPresentation::inline((string) $ticket->title, 200).'**'];

        foreach ($this->ticketFacts($ticket) as $label => $value) {
            $lines[] = '- **'.$label.':** '.$value;
        }

        $lines[] = '- **Board:** '.McpPresentation::inline((string) $ticket->board->name);

        return implode("\n", $lines);
    }
}
