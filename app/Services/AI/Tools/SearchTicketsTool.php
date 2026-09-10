<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Enums\TicketPriority;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\BoardAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Find tickets, across one board or every board the asker can reach.
 *
 * This is the tool that makes the difference between an assistant that
 * summarises and one that answers. "Which tickets are overdue?" cannot be
 * answered from a context block of the sixty most recently updated tickets —
 * it needs a query, and this is that query, expressed as a small set of filters
 * rather than as anything the model composes itself.
 *
 * Why filters and not a query language
 * ------------------------------------
 * A tool that accepted SQL, or a JSON filter tree, or a column name, would be
 * a tool whose safety depended on parsing hostile input correctly. This one
 * accepts a search term and a handful of named, enumerated filters; everything
 * else is fixed. There is no argument that can name a column, a table, an
 * operator or a viewer, so there is nothing to escape.
 *
 * Cross-board reads
 * -----------------
 * With no board, this searches every board the asking person is a member of —
 * which is the whole point of the workspace scope, and is why it is bounded
 * hard: BoardAccess::constrain() puts the membership rule in SQL, and the row
 * limit is capped whatever the model asks for. A board the person cannot reach
 * contributes nothing, so an administrator gets the workspace and a team member
 * gets their boards.
 */
class SearchTicketsTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    /** Rows returned at most, whatever the model asks for. */
    private const MAX_RESULTS = 50;

    private const DEFAULT_RESULTS = 20;

    public function name(): string
    {
        return 'search_tickets';
    }

    public function description(): string
    {
        return 'Find tickets by text, status column, label, priority, assignee, due date or age. '
            .'Use it for any question about more than one ticket: what is overdue, what is unassigned, '
            .'what is in review, what mentions a word. Omit "board" to search every board the person '
            .'can see; pass a board slug to narrow to one. Results are one line per ticket — follow up '
            .'with get_ticket for detail on a specific one. Counts reported are the number of matches, '
            .'which may be more than the lines returned.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Words to match in the title or description. Omit to list without searching.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug to restrict to. Omit to search every board the person can see.',
                ],
                'column' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Status column name, e.g. "In Progress". Matched loosely against column names.',
                ],
                'label' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Label name the ticket must carry.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => TicketPriority::values(),
                    'description' => 'Only tickets at this priority.',
                ],
                'assignee' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Assignee name or email. Pass "me" for the person asking, or "nobody" for unassigned tickets.',
                ],
                'overdue' => [
                    'type' => 'boolean',
                    'description' => 'True to return only tickets whose due date has passed and which are not in a done column.',
                ],
                'due_within_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'description' => 'Only tickets due within this many days from today.',
                ],
                'updated_within_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'description' => 'Only tickets touched in this many days. Use 1 for "today".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many lines to return. Defaults to '.self::DEFAULT_RESULTS.'.',
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
        $board = null;

        if (isset($input['board'])) {
            $board = $this->boardFrom($input, $context);

            if (! $board instanceof Board) {
                return AiToolOutcome::notFound(
                    'There is no board with that slug that this person can see.',
                    (string) $input['board'],
                );
            }
        }

        $query = Ticket::query()->visibleTo($context->user);

        if ($board instanceof Board) {
            $query->forBoard($board);
        }

        $this->applyFilters($query, $input, $context);

        // Counted before the limit, so the answer can distinguish "there are
        // four" from "here are four of ninety".
        $total = (clone $query)->count();

        $limit = (int) ($input['limit'] ?? self::DEFAULT_RESULTS);

        $tickets = $query
            ->with(['board', 'column', 'assignee'])
            ->orderByDesc('tickets.updated_at')
            ->limit(min(self::MAX_RESULTS, max(1, $limit)))
            ->get();

        $scope = $board instanceof Board
            ? 'board "'.$board->name.'"'
            : 'every board this person can see';

        if ($tickets->isEmpty()) {
            return AiToolOutcome::ok(
                'No tickets match those filters in '.$scope.'. '
                .'Say so plainly rather than widening the search without being asked.',
                $board?->slug,
            );
        }

        $lines = [
            'TICKETS matching those filters in '.$scope.': '
            .$total.' '.($total === 1 ? 'match' : 'matches')
            .($total > $tickets->count() ? ', showing the '.$tickets->count().' most recently updated' : ''),
        ];

        foreach ($tickets as $ticket) {
            /** @var Ticket $ticket */
            $lines[] = '- '.$this->ticketLine($ticket);
        }

        return AiToolOutcome::ok(
            implode("\n", $lines),
            $board?->slug,
            $total.' matches',
        );
    }

    // -----------------------------------------------------------------

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $input
     */
    private function applyFilters(Builder $query, array $input, AiToolContext $context): void
    {
        if (isset($input['query'])) {
            $query->search((string) $input['query']);
        }

        if (isset($input['priority'])) {
            $query->where('tickets.priority', (string) $input['priority']);
        }

        if (isset($input['column'])) {
            // Matched by name rather than by id, because a name is what the
            // person said. Loose, because "review" should find "In Review".
            $name = (string) $input['column'];

            $query->whereHas(
                'column',
                fn (Builder $columns) => $columns->where('board_columns.name', 'like', '%'.$name.'%')
            );
        }

        if (isset($input['label'])) {
            $name = (string) $input['label'];

            $query->whereHas(
                'labels',
                fn (Builder $labels) => $labels->where('labels.name', 'like', '%'.$name.'%')
            );
        }

        if (isset($input['assignee'])) {
            $this->applyAssignee($query, (string) $input['assignee'], $context);
        }

        if (($input['overdue'] ?? false) === true) {
            /*
             * Overdue means past its due date and not finished.
             *
             * "Not finished" is read from the column's own done flag rather
             * than from a column name, so a board that calls its last column
             * "Shipped" is handled the same as one that calls it "Done".
             */
            $query->whereNotNull('tickets.due_date')
                ->whereDate('tickets.due_date', '<', now()->toDateString())
                ->whereHas('column', fn (Builder $columns) => $columns->where('board_columns.is_done', false));
        }

        if (isset($input['due_within_days'])) {
            $days = (int) $input['due_within_days'];

            $query->whereNotNull('tickets.due_date')
                ->whereDate('tickets.due_date', '<=', now()->addDays($days)->toDateString());
        }

        if (isset($input['updated_within_days'])) {
            $days = (int) $input['updated_within_days'];

            // startOfDay for 1 day, so "today" means today rather than the
            // last twenty-four hours — which is what somebody asking "what
            // changed today" means.
            $query->where(
                'tickets.updated_at',
                '>=',
                $days === 1 ? now()->startOfDay() : now()->subDays($days),
            );
        }
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyAssignee(Builder $query, string $assignee, AiToolContext $context): void
    {
        $assignee = trim($assignee);
        $lowered = strtolower($assignee);

        if (in_array($lowered, ['nobody', 'none', 'unassigned', 'no one'], true)) {
            $query->whereNull('tickets.assignee_id');

            return;
        }

        if (in_array($lowered, ['me', 'myself', 'i'], true)) {
            $query->where('tickets.assignee_id', $context->user->getKey());

            return;
        }

        /*
         * A name or an email, matched against people the asker shares a board
         * with — never against the whole users table.
         *
         * That restriction is the point: without it, this filter would be a
         * way for a customer to test whether a person exists in the workspace
         * by watching which names return zero rows and which return none at
         * all. Constrained this way, an unknown name and an unreachable
         * colleague are the same empty result.
         */
        $boardIds = app(BoardAccess::class)->boardIdsFor($context->user);

        $query->whereHas('assignee', function (Builder $users) use ($assignee, $boardIds): void {
            $users->where(function (Builder $match) use ($assignee): void {
                $match->where('users.name', 'like', '%'.$assignee.'%')
                    ->orWhere('users.email', 'like', '%'.$assignee.'%');
            });

            if ($boardIds === []) {
                $users->whereRaw('1 = 0');

                return;
            }

            $users->whereExists(function ($sub) use ($boardIds): void {
                $sub->selectRaw('1')
                    ->from('board_members')
                    ->whereColumn('board_members.user_id', 'users.id')
                    ->whereIn('board_members.board_id', $boardIds);
            });
        });
    }
}
