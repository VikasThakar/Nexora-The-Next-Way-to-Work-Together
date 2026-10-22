<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use App\Mcp\Tools\Write\Concerns\DescribesTickets;
use App\Models\Ticket;

/**
 * Change one ticket: its fields, its status, who has it, its labels.
 *
 * The answer lists exactly what changed, old → new, compared from the saved
 * row before and after — not from what was asked for. A field that already
 * had the value asked for is not reported as changed, and a field the person
 * may not set is refused before anything is written.
 *
 * A customer may change the title and description of a ticket they raised,
 * as on the ticket screen; nothing else.
 */
final class UpdateTicketTool extends WriteTool
{
    use DescribesTickets;

    protected function toolName(): string
    {
        return 'update_ticket';
    }

    protected function describe(): string
    {
        return $this->context->staff
            ? 'Change a ticket: title, description, type, priority, status (move it to another column of its tab), tab (move it to another tab of its board), assignee ("nobody" unassigns), labels (replaces the whole set — include the ones to keep; [] clears them), due date ("none" clears it) and estimate. Only the fields given change. Read the ticket first and pass its "Last updated" as if_unchanged_since.'
            : 'Change the title or description of a ticket you raised. Read it first and pass its "Last updated" as if_unchanged_since.';
    }

    protected function forCustomers(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        $ticket = ['type' => 'string', 'maxLength' => 24, 'description' => 'The ticket key, e.g. AQD-42.'];
        $since = ['type' => 'string', 'maxLength' => 40, 'description' => 'The ticket\'s "Last updated" time exactly as get_ticket showed it. The change is refused if somebody changed the ticket after that.'];

        if (! $this->context->staff) {
            return [
                'type' => 'object',
                'properties' => [
                    'ticket' => $ticket,
                    'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The new title.'],
                    'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'The new description, as Markdown. It replaces the old one.'],
                    'if_unchanged_since' => $since,
                ],
                'required' => ['ticket'],
            ];
        }

        $fields = self::staffTicketFields();
        $fields['assignee']['description'] = 'Who should have it: the name or email of an active team member of the board, "me", or "nobody" to unassign.';
        $fields['labels']['description'] = 'The complete set of labels it should end up with — this REPLACES its labels, so include the ones to keep. [] clears them.';
        $fields['due_date']['description'] = 'The new due date, written YYYY-MM-DD, or "none" to clear it.';
        $fields['description']['description'] = 'The new description, as Markdown. It replaces the old one, so include anything that should stay.';
        $fields['tab']['description'] = 'Move the ticket to another tab of its board, by name. Without status it lands in the column with the same name there, or that tab\'s done column if it was done, or else its first column; with status, status names a column of the new tab. Omit to keep it in its tab.';
        $fields['status']['description'] = 'The status: a column of the ticket\'s current tab — or of tab when you also move it — exactly as get_board lists them (e.g. "In Progress", "Done").';

        return [
            'type' => 'object',
            'properties' => ['ticket' => $ticket, ...$fields, 'if_unchanged_since' => $since],
            'required' => ['ticket'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $ticket = $this->ticket($input);
        $board = $ticket->board;

        $this->guard($board);
        $this->assertUnchangedSince($input, $ticket->updated_at, $ticket->key());

        $before = $this->comparable($ticket);

        $changes = array_diff_key($input, ['ticket' => true, 'if_unchanged_since' => true]);

        if ($changes === []) {
            throw new \RuntimeException('no change was given. Pass the fields to change, such as status, assignee or priority.');
        }

        $result = $this->perform(AiActionType::UpdateTicket, $board, [
            'number' => $ticket->number,
            ...self::asActionInput($changes),
        ]);

        $after = $this->ticketByKey($board, $ticket->key());
        $now = $this->comparable($after);

        $lines = [];

        foreach ($now as $label => $value) {
            if (($before[$label] ?? null) !== $value) {
                $lines[] = $label === 'Description'
                    ? '- **Description:** rewritten'
                    : '- **'.$label.':** '.($before[$label] ?? '—').' → '.$value;
            }
        }

        $title = McpPresentation::ticketLink($after).' '.McpPresentation::inline((string) $after->title, 200);

        if ($lines === []) {
            return WriteOutcome::ok(
                '✅ **Nothing needed changing — '.$title."**\nIt already had those values.",
                $after->key(),
                $board,
                'No change: it already had those values.',
            );
        }

        $lines[] = '- **Updated:** '.McpPresentation::time($after->updated_at, McpPresentation::timezone());

        return WriteOutcome::ok('✅ **Ticket updated — '.$title."**\n".implode("\n", $lines), $after->key(), $board, $result['label']);
    }

    /**
     * Every reportable field, as text, for an old → new comparison.
     *
     * @return array<string, string>
     */
    private function comparable(Ticket $ticket): array
    {
        // The tab always takes part, even where ticketFacts() leaves it out: a
        // move from one tab's "To Do" to another's must not read as "nothing
        // needed changing".
        $facts = $this->ticketFacts($ticket);
        $facts += ['Tab' => McpPresentation::inline((string) $ticket->column?->tab?->name)];

        return [
            'Title' => McpPresentation::inline((string) $ticket->title, 200),
            ...$facts,
            'Description' => md5((string) $ticket->description_md),
        ];
    }

    /**
     * `labels: []` means "clear them", and the schema check reads an empty
     * list as not supplied — so it is put back when the client sent it.
     */
    protected function validateInput(array $raw): array
    {
        $input = parent::validateInput($raw);

        if ($this->context->staff && ($raw['labels'] ?? null) === []) {
            $input['labels'] = [];
        }

        return $input;
    }
}
