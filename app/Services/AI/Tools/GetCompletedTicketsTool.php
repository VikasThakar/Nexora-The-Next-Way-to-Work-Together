<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Mcp\McpPresentation;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\StatisticsScope;
use App\Support\StatsPeriod;
use App\Support\StatsPeriodPhrase;
use App\Support\TicketPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which tickets were finished in a period: "what did we complete yesterday?"
 *
 * The question sounds like a filter on the current column and is not one. A
 * ticket sitting in Done today may have been finished last month, and a ticket
 * finished yesterday may have been reopened this morning. The only record of
 * *when* a ticket was finished is its history, so this reads the same closures
 * the flow metrics use — the first arrival in a done column within the range —
 * and then names the tickets.
 *
 * What "completed" means, exactly
 * -------------------------------
 * Arrival in a column that is flagged done *today*, inside the period, counted
 * once per ticket at its first arrival (FlowMetrics::closures). The answer says
 * so in plain words, because a definition the model has to guess is one it will
 * state wrongly. A ticket that has since left the done column is still listed,
 * marked REOPENED, rather than silently dropped: it *was* completed yesterday,
 * and hiding that would make the count disagree with the statistics page.
 *
 * Days are cut in the reporting timezone (the board's, or the workspace
 * default), and the resolved range is printed with its UTC offset so the
 * answer can never be about a different day than the one it names.
 *
 * Visibility
 * ----------
 * The closures come from TicketEvent::readableBy(), which already applies the
 * ticket rule; the ticket ids are then read back through Ticket::visibleTo()
 * as well, because this tool prints keys and titles and a list of names is the
 * last place to rely on a single filter.
 */
class GetCompletedTicketsTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    public const NAME = 'get_completed_tickets';

    /** Lines returned at most, whatever the model asks for. */
    private const MAX_RESULTS = 100;

    private const DEFAULT_RESULTS = 50;

    public function __construct(private readonly FlowMetrics $flow) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List the tickets that were completed — moved into a done column — during a period. '
            .'Use it for "what did we finish yesterday / this week / last week". Returns the resolved date '
            .'range and timezone, the total, and one line per ticket with its completion time, newest first. '
            .'A ticket completed and later reopened is still listed, marked REOPENED. Omit "board" to cover '
            .'every board the person can see; add "tab" to keep to one tab of a board split into tabs. '
            .'Pass either "period" or explicit "from"/"to" dates, not both. '
            .'For weekly counts use get_statistics; for open work use search_tickets.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => StatsPeriodPhrase::keys(),
                    'description' => StatsPeriodPhrase::schemaDescription(),
                ],
                'from' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'First day of a custom range, YYYY-MM-DD, in the reporting timezone.',
                ],
                'to' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'Last day of a custom range, YYYY-MM-DD, in the reporting timezone.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug to restrict to. Omit to cover every board the person can see.',
                ],
                'tab' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Tab name, e.g. "Backend API", on boards split into tabs. Only tickets now in a tab whose name contains this.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many lines to return. Defaults to '.self::DEFAULT_RESULTS.'. The total is always reported.',
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

        $scope = StatisticsScope::for($context->user, null, $board);

        if ($scope->isEmpty()) {
            return $board instanceof Board
                ? AiToolOutcome::notFound('That board has no completion history to report — it may be archived.', $board->slug)
                : AiToolOutcome::ok('This person cannot see any boards, so there is nothing to report.', 'completed · workspace');
        }

        $key = isset($input['period']) ? (string) $input['period'] : null;

        $period = StatsPeriodPhrase::resolve(
            $key,
            isset($input['from']) ? (string) $input['from'] : null,
            isset($input['to']) ? (string) $input['to'] : null,
            $scope->timezone(),
        );

        $scope = $scope->withPeriod($period);

        $tab = isset($input['tab']) ? (string) $input['tab'] : null;

        $closures = $this->visibleClosures($scope, $context, $tab);
        $total = $closures->count();

        $where = ($board instanceof Board ? 'board "'.$board->name.'"' : 'every board this person can see')
            .($tab !== null ? ', in a tab whose name contains "'.$tab.'"' : '');
        $target = 'completed · '.($board?->slug ?? 'workspace');
        $range = $this->rangeSentence($period, $key, isset($input['from']) || isset($input['to']));

        if ($total === 0) {
            return AiToolOutcome::ok(
                'No tickets were completed in '.$where.' during '.$range.'. '
                .'Say so plainly rather than widening the range without being asked.',
                $target,
                '0 completed',
            );
        }

        $limit = min(self::MAX_RESULTS, max(1, (int) ($input['limit'] ?? self::DEFAULT_RESULTS)));
        $shown = $closures->take($limit);

        $tickets = Ticket::query()
            ->visibleTo($context->user)
            ->whereIn('tickets.id', $shown->keys()->all())
            ->with(['board', 'column.tab', 'assignee'])
            ->get()
            ->keyBy('id');

        $lines = [
            'COMPLETED TICKETS in '.$where.' during '.$range.'.',
            '"Completed" means the ticket moved into a column marked as done during this range; a ticket '
            .'finished more than once is counted once, at its first finish.',
            'Total: '.$total.' completed '.($total === 1 ? 'ticket' : 'tickets')
            .($total > $shown->count() ? ', showing the '.$shown->count().' most recently completed' : '').'.',
        ];

        foreach ($shown as $ticketId => $completedAt) {
            $ticket = $tickets->get($ticketId);

            if (! $ticket instanceof Ticket) {
                continue;
            }

            $line = '- '.$this->ticketLine($ticket, $context)
                .' · completed '.$completedAt->setTimezone($period->timezone)->toIso8601String();

            if ($ticket->column !== null && ! $ticket->column->is_done) {
                $line .= ' · REOPENED (now in '.TicketPlace::of($ticket).')';
            }

            $lines[] = $line;
        }

        if (! $context->channel->rendersInAppBlocks()) {
            $lines[] = '';
            $lines[] = McpPresentation::hint('tickets');
        }

        return AiToolOutcome::ok(implode("\n", $lines), $target, $total.' completed');
    }

    /**
     * Closures in scope that name a ticket this person can read, newest first.
     *
     * A tab narrows to the tickets in it now, because a ticket's tab is its
     * column's tab — the same reading search_tickets uses. Loose like it too,
     * so "backend" finds "Backend API".
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function visibleClosures(StatisticsScope $scope, AiToolContext $context, ?string $tab = null): Collection
    {
        $closures = $this->flow->closures($scope);

        if ($closures->isEmpty()) {
            return $closures;
        }

        $visible = [];

        foreach ($closures->keys()->chunk(500) as $chunk) {
            foreach (Ticket::query()
                ->visibleTo($context->user)
                ->whereIn('tickets.id', $chunk->all())
                ->when($tab !== null, fn (Builder $tickets) => $tickets->whereHas(
                    'column.tab',
                    fn (Builder $tabs) => $tabs->where('board_tabs.name', 'like', '%'.$tab.'%')
                ))
                ->pluck('tickets.id') as $id) {
                $visible[] = (int) $id;
            }
        }

        return $closures
            ->only($visible)
            ->sortByDesc(fn (CarbonImmutable $completedAt): int => $completedAt->getTimestamp());
    }

    /**
     * The period as a sentence: its name, its exact bounds, and the zone.
     */
    private function rangeSentence(StatsPeriod $period, ?string $key, bool $custom): string
    {
        $name = ! $custom && $key !== null && in_array($key, StatsPeriodPhrase::keys(), true)
            ? StatsPeriodPhrase::label($key).' ('
            : '(';

        return $name.$period->from->setTimezone($period->timezone)->toIso8601String()
            .' to '.$period->to->setTimezone($period->timezone)->toIso8601String()
            .', timezone '.$period->timezone.')';
    }
}
