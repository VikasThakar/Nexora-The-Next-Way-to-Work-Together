<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Board;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\Statistics\CustomerStatistics;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\StatisticsScope;
use App\Services\Statistics\TeamStatistics;
use App\Support\StatsPeriodPhrase;

/**
 * Real numbers, aggregated in SQL, for the assistant to chart.
 *
 * This is the tool that makes "show me tickets by status as a pie chart" answer
 * with the workspace's actual figures rather than a plausible-looking invention.
 * The model asks for a named metric over a named range; it gets back counts. It
 * then writes a `nexora-chart` fence with those counts, which
 * App\Support\RichResponse\ChartSpec validates and
 * App\Support\RichResponse\ChartRenderer draws server-side.
 *
 * Nothing is computed here
 * ------------------------
 * Every metric below is one call to a service that already existed and is
 * already used by the statistics screens: TeamStatistics, CustomerStatistics
 * and FlowMetrics. That is deliberate to the point of being the main design
 * decision. A second implementation of "tickets per column" would be a second
 * chance to forget that a customer must not see internal ones, and the two
 * would drift — an assistant chart and the statistics page disagreeing about a
 * board's totals is worse than either being absent.
 *
 * Authorization happens before aggregation, not after
 * ---------------------------------------------------
 * `StatisticsScope::for($viewer, ...)` is the only way in, and it applies the
 * rules in SQL: tickets come from the ordinary `visibleTo()` scopes, and a
 * requested board is intersected with the boards the viewer may reach. So a
 * customer's "show me ticket statistics" aggregates over their own boards'
 * customer-visible tickets and cannot do otherwise — there is no unscoped count
 * that is later filtered, and therefore no arithmetic from which a hidden total
 * could be recovered. A board named in the arguments that this person cannot
 * reach yields `StatisticsScope::none()`, which is empty rather than
 * workspace-wide.
 *
 * The audience decides which questions exist
 * ------------------------------------------
 * Staff get the team breakdowns. A customer gets the customer ones, and three
 * metrics are not offered to them at all — per-assignee workload, label
 * breakdowns and the internal/shared split. That is not a leak being patched:
 * CustomerStatistics already made this product decision (see its
 * `statusSplit()`, which is deliberately open-versus-closed rather than one
 * slice per internal workflow column), and this tool honours it rather than
 * reaching around it. A customer asking for workload is told the metric is not
 * available to them, which is true and is not a hint about anybody's numbers.
 *
 * Reading, always
 * ---------------
 * A statistic is a read. This tool is offered through the ordinary read-tool
 * path, so it is absent in Writing mode and present in Reading and Everything —
 * and it has no capability-mode condition, because counting things that are
 * already visible is what AI Observer is for. Charting can therefore never be a
 * route to a write: there is no branch in this file that changes anything.
 */
class GetStatisticsTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    /**
     * The metrics, and who may ask for each.
     *
     * `staff` marks the three that have no customer-safe equivalent. Keeping
     * that fact in this table rather than in a condition inside handle() is
     * what lets availableTo(), the schema and the refusal all read from one
     * list.
     */
    private const METRICS = [
        'totals' => ['staff' => false, 'about' => 'Headline counts: total, open, closed, and how many arrived in the period. Best shown as KPI cards.'],
        'by_status' => ['staff' => false, 'about' => 'Tickets per status column, in board order. For customers this is open versus closed.'],
        'by_priority' => ['staff' => false, 'about' => 'Tickets per priority, most urgent first. Every priority appears, including at zero.'],
        'created_by_week' => ['staff' => false, 'about' => 'Tickets raised per week across the period — a trend.'],
        'completed_by_week' => ['staff' => false, 'about' => 'Tickets finished per week across the period — a trend. Pair it with created_by_week to show whether the team is keeping up.'],
        'by_assignee' => ['staff' => true, 'about' => 'Open tickets per assignee, busiest first — a workload view. Staff only.'],
        'by_label' => ['staff' => true, 'about' => 'Tickets per label, most used first. Staff only.'],
        'visibility_split' => ['staff' => true, 'about' => 'How many tickets are shared with the customer versus internal. Staff only.'],
    ];

    /** Rows returned for a breakdown. Beyond this a chart is unreadable. */
    private const MAX_ROWS = 30;

    /**
     * Rows returned for a weekly series.
     *
     * Higher than a breakdown's cap because a trend's row count is a property
     * of the *range*, not of how much data there is: "this year" is fifty-two
     * or fifty-three weeks whatever the tickets say, and a fifty-week line is
     * perfectly readable where a fifty-bar breakdown is not.
     */
    private const MAX_WEEKS = 60;

    public function __construct(
        private readonly TeamStatistics $team,
        private readonly CustomerStatistics $customers,
        private readonly FlowMetrics $flow,
    ) {}

    public function name(): string
    {
        return 'get_statistics';
    }

    public function description(): string
    {
        $metrics = [];

        foreach (self::METRICS as $key => $meta) {
            $metrics[] = $key.' — '.$meta['about'];
        }

        return 'Aggregate counts of real tickets, for answering questions about numbers and for drawing '
            .'charts. ALWAYS call this before emitting a chart about the workspace: it returns the actual '
            ."figures, and you must never estimate or invent them.\n\nMetrics:\n- "
            .implode("\n- ", $metrics)
            ."\n\nIt counts only what this person is allowed to see, so the numbers are already correct to "
            .'report to them. It reads and never changes anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => array_keys(self::METRICS),
                    'description' => 'Which breakdown to return. Required.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug to report on. Omit to use the board in context, or to '
                        .'report across every board this person can see when the context is the whole workspace.',
                ],
                'period' => [
                    'type' => 'string',
                    'enum' => StatsPeriodPhrase::keys(),
                    'description' => StatsPeriodPhrase::schemaDescription(),
                ],
                'from' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'Start of a custom range as YYYY-MM-DD. Use with `to` instead of `period`.',
                ],
                'to' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'End of a custom range as YYYY-MM-DD.',
                ],
                'compare_previous' => [
                    'type' => 'boolean',
                    'description' => 'True to also return the same metric over the preceding range of equal '
                        .'length, for a this-versus-last comparison. Use it for "compare this month with last month".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_ROWS,
                    'description' => 'How many rows for by_assignee and by_label. Defaults to 15.',
                ],
            ],
            'required' => ['metric'],
        ];
    }

    /**
     * Offered to everybody, including customers.
     *
     * A customer's assistant is read-only, not blind: they may already open
     * their board and count the cards, so a chart of the same tickets tells
     * them nothing new about anybody. Which *metrics* they may ask for is the
     * narrower question, and it is answered per call below.
     */
    public function availableTo(AiToolContext $context): bool
    {
        return true;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $metric = (string) ($input['metric'] ?? '');

        if (! array_key_exists($metric, self::METRICS)) {
            return AiToolOutcome::invalid(
                'Unknown metric. Choose one of: '.implode(', ', array_keys(self::METRICS)).'.'
            );
        }

        if (self::METRICS[$metric]['staff'] && ! $context->staff) {
            /*
             * Refused, and the refusal says what the person can have instead.
             *
             * A bare "not allowed" would leave the model with nothing to say
             * but "I cannot do that", where the useful answer is a chart of a
             * metric they *can* see.
             */
            return AiToolOutcome::refused(
                'The '.$metric.' breakdown is not available on this account. '
                .'Available metrics here: '.implode(', ', $this->metricsFor($context)).'.',
            );
        }

        // The board, resolved against this person's own access. A slug they
        // cannot reach yields null, which becomes an empty scope below rather
        // than a silent widening to every board.
        $named = isset($input['board']) && trim((string) $input['board']) !== '';
        $board = $this->boardFrom($input, $context);

        if ($named && ! $board instanceof Board) {
            return AiToolOutcome::notFound(
                'No board with that slug, or it is not one this person can see. No figures were read.',
            );
        }

        $scope = StatisticsScope::for($context->user, null, $board);

        if ($scope->isEmpty()) {
            return AiToolOutcome::notFound(
                'There are no boards in scope for this person, so there is nothing to count.',
            );
        }

        // The period is applied after the scope exists, because the timezone a
        // day is cut in belongs to the board — the same order
        // StatisticsScopeResolver uses for the statistics screens.
        $period = StatsPeriodPhrase::resolve(
            $input['period'] ?? null,
            isset($input['from']) ? (string) $input['from'] : null,
            isset($input['to']) ? (string) $input['to'] : null,
            $scope->timezone(),
        );

        $scope = $scope->withPeriod($period);

        $rows = $this->rows($metric, $scope, $context, (int) ($input['limit'] ?? 15));

        if ($rows === []) {
            return AiToolOutcome::ok(
                $this->provenance($metric, $scope, $board).
                "\n\nNo tickets matched, so there is no data to chart. Tell the person there is not enough "
                .'real data for this and do not draw an empty chart.',
                $this->target($metric, $board),
            );
        }

        $text = $this->provenance($metric, $scope, $board)."\n\n".$this->table($rows);

        if (($input['compare_previous'] ?? false) === true) {
            $text .= "\n\n".$this->comparison($metric, $scope, $context, (int) ($input['limit'] ?? 15));
        }

        $text .= "\n\n".$this->guidance($metric, $rows);

        return AiToolOutcome::ok($text, $this->target($metric, $board));
    }

    // -----------------------------------------------------------------

    /**
     * One metric, as label/value rows.
     *
     * Every branch is a delegation. The staff/customer fork is which *service*
     * answers, not which rules are applied — both services are already scoped
     * by the same StatisticsScope, so neither can return a figure the viewer
     * should not have.
     *
     * @return list<array{label: string, value: int|float}>
     */
    private function rows(string $metric, StatisticsScope $scope, AiToolContext $context, int $limit): array
    {
        $limit = max(1, min(self::MAX_ROWS, $limit));

        if (! $context->staff) {
            return match ($metric) {
                'totals' => $this->pairs($this->customers->counts($scope)),
                'by_status' => $this->plain($this->customers->statusSplit($scope)),
                'by_priority' => $this->plain($this->customers->byPriority($scope)),
                'created_by_week' => $this->series($this->customers->createdByWeek($scope)),
                'completed_by_week' => $this->series($this->flow->throughputByWeek($scope)),
                default => [],
            };
        }

        return match ($metric) {
            'totals' => $this->pairs($this->team->counts($scope)),
            'by_status' => $this->plain($this->team->byColumn($scope)),
            'by_priority' => $this->plain($this->team->byPriority($scope)),
            'by_assignee' => $this->plain($this->team->byAssignee($scope, $limit)),
            'by_label' => $this->plain($this->team->byLabel($scope, $limit)),
            'created_by_week' => $this->series($this->team->createdByWeek($scope)),
            'completed_by_week' => $this->series($this->flow->throughputByWeek($scope)),
            'visibility_split' => $this->pairs($this->team->visibilitySplit($scope)),
            default => [],
        };
    }

    /**
     * A weekly series, trimmed from the FRONT when it is too long.
     *
     * The direction matters and is the whole reason this is not `plain()`. A
     * breakdown has no order worth preserving, so dropping its tail loses the
     * smallest categories. A trend is ordered oldest-to-newest, so dropping its
     * tail would throw away *this week* and leave the chart ending months ago —
     * a "ticket trend" whose most recent point is stale is not a worse chart,
     * it is a wrong one. So the oldest weeks go instead.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{label: string, value: int|float}>
     */
    private function series(array $rows): array
    {
        $rows = $this->clean($rows);

        return count($rows) <= self::MAX_WEEKS
            ? $rows
            : array_slice($rows, -self::MAX_WEEKS);
    }

    /**
     * The same metric over the preceding range, as a second series.
     *
     * `StatsPeriod::previous()` shifts by whole days in the display timezone,
     * so a 30-day comparison is against 30 days and not 31 — see the note on
     * that method, which exists because the naive version is wrong in a way
     * nobody notices.
     */
    private function comparison(string $metric, StatisticsScope $scope, AiToolContext $context, int $limit): string
    {
        $previous = $scope->period->previous();
        $rows = $this->rows($metric, $scope->withPeriod($previous), $context, $limit);

        if ($rows === []) {
            return 'Previous period ('.$previous->fromDate().' to '.$previous->toDate().'): no data.';
        }

        return 'Previous period ('.$previous->fromDate().' to '.$previous->toDate().'), for comparison — '
            .'use this as a SECOND dataset in the same chart, labelled by its range:'
            ."\n".$this->table($rows);
    }

    /**
     * Where the numbers came from, in words the model should repeat.
     *
     * The brief asks that a chart name its source, and this is the sentence it
     * names it with. It also states the range explicitly rather than leaving
     * "this month" to be interpreted twice.
     */
    private function provenance(string $metric, StatisticsScope $scope, ?Board $board): string
    {
        $where = $board instanceof Board
            ? $board->name
            : ($scope->boards->count() === 1
                ? (string) $scope->boards->first()?->name
                : $scope->boards->count().' boards this person can see');

        return $metric.' for '.$where.', '
            .$scope->period->fromDate().' to '.$scope->period->toDate()
            .' ('.$scope->period->days().' days, '.$scope->timezone().').';
    }

    /**
     * The rows, tab-separated.
     *
     * Not JSON. A tab-separated block is about half the tokens, it is
     * unambiguous to copy from, and — the reason that matters — the model has to
     * transcribe these numbers into a chart fence, so the format it reads them
     * in should be the one least likely to be mis-parsed by it. A trailing
     * total is included because it is the figure the answer's prose will quote.
     *
     * @param  list<array{label: string, value: int|float}>  $rows
     */
    private function table(array $rows): string
    {
        $lines = ['label<TAB>value'];
        $total = 0.0;

        foreach ($rows as $row) {
            $lines[] = $row['label']."\t".$this->number($row['value']);
            $total += (float) $row['value'];
        }

        $lines[] = '(total '.$this->number($total).')';

        return implode("\n", $lines);
    }

    /**
     * What to draw, and why.
     *
     * The brief asks the assistant to choose a visualisation intelligently and
     * to say why it is useful. The shape of the data is known here and not in
     * the prompt, so the recommendation is made here — the model is still free
     * to override it when the person asked for something specific, which is
     * what "prefer" rather than "use" is doing in this text.
     *
     * @param  list<array{label: string, value: int|float}>  $rows
     */
    private function guidance(string $metric, array $rows): string
    {
        $count = count($rows);

        $suggestion = match (true) {
            $metric === 'totals' => 'These are simple totals — report them as KPI cards with a `nexora-kpi` '
                .'block, or as a sentence. Do not draw a chart of unrelated totals.',
            str_ends_with($metric, '_by_week') => 'This is a trend over time — prefer `line` (or `area` for a '
                .'single series). Do not use a pie chart for a time series.',
            $metric === 'by_status' || $metric === 'visibility_split' => 'This is a distribution of a whole — '
                .'`donut` or `pie` reads well, and `bar` also works.',
            $metric === 'by_assignee' => 'This is a comparison across people — prefer `hbar`, because names '
                .'are long and read better beside their bars than under them.',
            default => 'This is a categorical comparison — prefer `bar`.',
        };

        $shape = $count > 12
            ? ' There are '.$count.' rows, which is a lot for a chart: consider a `nexora-table` instead, or '
                .'chart the top few and say what was left out.'
            : '';

        return 'Copy these labels and values into the chart EXACTLY as given — do not round them, reorder '
            .'them, total them differently or fill in gaps. '.$suggestion.$shape;
    }

    /**
     * The audit row's subject: what was counted, and where.
     */
    private function target(string $metric, ?Board $board): string
    {
        return $board instanceof Board
            ? $metric.' · '.$board->slug
            : $metric.' · workspace';
    }

    /**
     * A named-count array as rows, with the keys turned into words.
     *
     * `counts()` returns `['total' => 12, 'active' => 5]`, which is a shape the
     * chart format has no use for. "Active" is a better axis label than
     * "active" and the underscore in `customer_visible` is noise.
     *
     * @param  array<string, int>  $counts
     * @return list<array{label: string, value: int|float}>
     */
    private function pairs(array $counts): array
    {
        $rows = [];

        foreach ($counts as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $rows[] = [
                'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                'value' => $value,
            ];
        }

        return $rows;
    }

    /**
     * A breakdown, capped at a readable number of rows.
     *
     * Trimmed from the tail, which is the right end for a breakdown: the
     * services already return these busiest-first, so what is dropped is the
     * smallest categories. See `series()` for why a trend is cut the other way.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{label: string, value: int|float}>
     */
    private function plain(array $rows): array
    {
        return array_slice($this->clean($rows), 0, self::MAX_ROWS);
    }

    /**
     * Drop the presentation keys and anything unusable, keeping the order.
     *
     * The services return `variant` and `is_done` alongside the numbers, for
     * badge colours on the statistics page. A chart must not receive a colour —
     * the renderer chooses those by index, which is the property that keeps
     * anything model-authored out of the drawing — so they are dropped here
     * rather than passed along and ignored.
     *
     * Split out from plain() so `series()` can apply a different trim to the
     * same cleaning — the two differ only in which end they cut.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{label: string, value: int|float}>
     */
    private function clean(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = $row['value'] ?? null;

            if ($label === '' || (! is_int($value) && ! is_float($value))) {
                continue;
            }

            $clean[] = ['label' => mb_substr($label, 0, 60), 'value' => $value];
        }

        return $clean;
    }

    /**
     * The metrics this person may actually ask for, for a refusal's second half.
     *
     * @return list<string>
     */
    private function metricsFor(AiToolContext $context): array
    {
        $available = [];

        foreach (self::METRICS as $key => $meta) {
            if (! $meta['staff'] || $context->staff) {
                $available[] = $key;
            }
        }

        return $available;
    }

    /**
     * A count as the shortest exact string. Never scientific notation.
     */
    private function number(int|float $value): string
    {
        if (is_int($value) || $value === floor($value)) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
