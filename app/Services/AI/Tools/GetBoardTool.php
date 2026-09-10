<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\Ticket;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\TicketFinder;
use Illuminate\Support\Str;

/**
 * How a board is set up, and how much work sits where.
 *
 * The tool that answers "what is blocking this project" without reading every
 * ticket: the columns in order, how many visible tickets are in each, the
 * labels in use, the members if the asker is staff, and the ticket prefix so
 * the model can talk about tickets the way the team does.
 *
 * Called with no board it lists the boards the person can reach instead of
 * guessing one, which is the honest answer to "tell me about my project" asked
 * from the dashboard.
 *
 * Counts come from TicketFinder with the asking person as the viewer, so the
 * numbers a customer is told are counts of tickets they could open — not real
 * totals with the internal ones subtracted after the fact, which is the version
 * of this that leaks by arithmetic.
 */
class GetBoardTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    public function name(): string
    {
        return 'get_board';
    }

    public function description(): string
    {
        return 'Read a board: its description, its status columns in order with a count of tickets in '
            .'each, the labels in use, and its ticket prefix. Use it to understand how a project is '
            .'organised or where work is piling up. Called without a board slug it lists the boards this '
            .'person can see, which is the right first call when the question does not name a project.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug. Omit to list the boards this person can see.',
                ],
            ],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        return true;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $named = isset($input['board']);
        $board = $this->boardFrom($input, $context);

        if ($named && ! $board instanceof Board) {
            return AiToolOutcome::notFound(
                'There is no board with that slug that this person can see.',
                (string) $input['board'],
            );
        }

        if (! $board instanceof Board) {
            return $this->listBoards($context);
        }

        $tickets = app(TicketFinder::class);

        $lines = [
            'BOARD "'.$board->name.'" (slug: '.$board->slug.', ticket prefix '.$board->ticket_prefix.')',
        ];

        if (filled($board->description)) {
            $lines[] = 'Description: '.$this->excerpt($board->description, 600);
        }

        if ($board->archived_at !== null) {
            $lines[] = 'This board is archived.';
        }

        $total = $tickets->query($board, $context->user)->count();

        $lines[] = 'Tickets visible to this person: '.$total;

        $columns = BoardColumn::query()
            ->where('board_columns.board_id', $board->getKey())
            ->orderBy('board_columns.position')
            ->get();

        if ($columns->isNotEmpty()) {
            /*
             * One grouped count rather than one query per column.
             *
             * Still through TicketFinder, so the numbers are counts of tickets
             * this person could open — the visibility rule is in the same SQL
             * as the aggregate, which is the only way a count and a list can
             * be guaranteed to agree.
             */
            $perColumn = $tickets->query($board, $context->user)
                ->reorder()
                ->selectRaw('tickets.board_column_id, count(*) as aggregate')
                ->groupBy('tickets.board_column_id')
                ->pluck('aggregate', 'tickets.board_column_id');

            $lines[] = '';
            $lines[] = 'COLUMNS (in board order)';

            foreach ($columns as $column) {
                /** @var BoardColumn $column */
                $count = (int) ($perColumn[$column->getKey()] ?? 0);

                $lines[] = '- '.$column->name.': '.$count.' '.($count === 1 ? 'ticket' : 'tickets')
                    .($column->is_done ? ' (counts as done)' : '');
            }
        }

        $labels = Label::query()
            ->where('labels.board_id', $board->getKey())
            ->orderBy('labels.name')
            ->limit(40)
            ->get();

        if ($labels->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'LABELS: '.$labels->pluck('name')->implode(', ');
        }

        /*
         * Who works on it. Staff only.
         *
         * A customer who is a member of a board has no business being handed
         * the membership list: it names the other customers on the account and
         * which engineers are assigned to their project, neither of which the
         * product shows them anywhere else.
         */
        if ($context->staff) {
            $members = $board->members()->orderBy('users.name')->limit(40)->get();

            if ($members->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'MEMBERS: '.$members
                    ->map(fn ($member): string => $member->name.' ('.$member->role->label().')')
                    ->implode(', ');
            }
        }

        $recent = $tickets->query($board, $context->user)
            ->with(['board', 'column', 'assignee'])
            ->orderByDesc('tickets.updated_at')
            ->limit(8)
            ->get();

        if ($recent->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'MOST RECENTLY UPDATED TICKETS';

            foreach ($recent as $ticket) {
                /** @var Ticket $ticket */
                $lines[] = '- '.$this->ticketLine($ticket);
            }
        }

        return AiToolOutcome::ok(implode("\n", $lines), $board->slug);
    }

    // -----------------------------------------------------------------

    private function listBoards(AiToolContext $context): AiToolOutcome
    {
        $boards = $this->reachableBoards($context);

        if ($boards->isEmpty()) {
            return AiToolOutcome::ok(
                'This person is not a member of any board, so there is nothing to describe.'
            );
        }

        $lines = ['BOARDS this person can see ('.$boards->count().')'];

        foreach ($boards as $board) {
            /** @var Board $board */
            $lines[] = '- '.$board->name.' (slug: '.$board->slug.', prefix '.$board->ticket_prefix.')'
                .(filled($board->description) ? ' — '.Str::limit(trim((string) $board->description), 140, '…') : '');
        }

        $lines[] = '';
        $lines[] = 'Ask again with one of these slugs for detail, or use search_tickets across all of them.';

        return AiToolOutcome::ok(implode("\n", $lines));
    }
}
