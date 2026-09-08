<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Services\Statistics\Concerns\NamesExportFiles;

/**
 * The tables behind the charts, as rows and headers.
 *
 * One registry rather than a method per download route, and the reason is the
 * security property: every dataset is derived from a StatisticsScope that was
 * resolved for the requesting viewer, so a CSV cannot contain a figure its
 * requester could not see on the screen. There is no path here that takes a
 * board id, a ticket id, or anything else a URL could name.
 *
 * The names are an allow-list. `dataset=…` arrives from a query string, and
 * anything not in KEYS is refused rather than reflected — no dynamic method
 * call, no `$this->{$name}()`.
 *
 * This is the *team* registry, and it is reachable only from behind the
 * `view-internal-content` gate. The customer report has its own —
 * CustomerStatisticsExport — which is a separate class rather than a subset of
 * this one on purpose: see the comment there.
 *
 * What is deliberately absent: any per-ticket export. These are aggregates.
 * "Every ticket on this board as a spreadsheet" is a different feature with a
 * different risk profile — it would carry titles and descriptions out of the
 * application in bulk — and nobody has asked for it.
 */
class StatisticsExport
{
    use NamesExportFiles;

    /**
     * The datasets that may be asked for, and what they are called in a
     * filename.
     *
     * One entry per chart and per table on the team statistics screen. Adding
     * a chart to that page without adding it here leaves a CSV button that
     * cannot be rendered at all — a route() failure rather than a silent gap,
     * which is deliberate: it keeps the page and the registry in step.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        // Flow.
        'created-vs-completed' => 'Tickets created and completed by week',
        'created-by-week' => 'Tickets created by week',
        'throughput-by-week' => 'Tickets completed by week',
        'cycle-time-trend' => 'Median cycle time by week',
        'time-in-column' => 'Average time in column',

        // Distribution.
        'by-column' => 'Tickets by column',
        'by-priority' => 'Tickets by priority',
        'by-assignee' => 'Open tickets by assignee',
        'by-label' => 'Tickets by label',
        'visibility-split' => 'Customer visibility',

        // AI. Offered only while the feature is on — see exists().
        'ai-summary' => 'AI tokens and cost',
        'ai-runs-by-week' => 'AI runs by week',
        'ai-by-board' => 'AI activity by board',
    ];

    /**
     * The datasets that exist only while the AI feature is enabled.
     *
     * The screen hides its entire AI section behind the same switch, and a
     * download route that kept answering after the charts were gone would be a
     * way to read figures the application has been configured not to show.
     *
     * @var array<int, string>
     */
    public const AI_KEYS = [
        'ai-summary',
        'ai-runs-by-week',
        'ai-by-board',
    ];

    public function __construct(
        private readonly TeamStatistics $team,
        private readonly FlowMetrics $flow,
        private readonly AiStatistics $ai,
    ) {}

    public function exists(string $dataset): bool
    {
        if (! array_key_exists($dataset, self::KEYS)) {
            return false;
        }

        if (in_array($dataset, self::AI_KEYS, true)) {
            return (bool) config('ai.enabled');
        }

        return true;
    }

    public function label(string $dataset): string
    {
        return self::KEYS[$dataset] ?? 'Statistics';
    }

    /**
     * Header row plus data rows, ready to be written as CSV.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    public function rows(string $dataset, StatisticsScope $scope): array
    {
        return match ($dataset) {
            'created-vs-completed' => $this->createdVsCompleted($scope),
            'created-by-week' => $this->weekly($this->team->createdByWeek($scope), 'Created'),
            'throughput-by-week' => $this->weekly($this->flow->throughputByWeek($scope), 'Completed'),
            'cycle-time-trend' => $this->cycleTimeTrend($scope),
            'time-in-column' => $this->timeInColumn($scope),

            'by-column' => $this->series($this->team->byColumn($scope), 'Column'),
            'by-priority' => $this->series($this->team->byPriority($scope), 'Priority'),
            'by-assignee' => $this->series($this->team->byAssignee($scope), 'Assignee'),
            'by-label' => $this->series($this->team->byLabel($scope), 'Label'),
            'visibility-split' => $this->visibilitySplit($scope),

            'ai-summary' => $this->aiSummary($scope),
            'ai-runs-by-week' => $this->aiRunsByWeek($scope),
            'ai-by-board' => $this->aiByBoard($scope),

            // Unreachable through the controller, which checks exists() first.
            // Present so this method is total rather than relying on that.
            default => ['headers' => [], 'rows' => []],
        };
    }

    /**
     * The headline series, and the only one that pairs two measures.
     *
     * Both halves are keyed by the same week labels, so a week in which
     * something was created but nothing closed still has a row — a table that
     * skipped it would make a gap look like an absence of data rather than an
     * absence of closures.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    public function createdVsCompleted(StatisticsScope $scope): array
    {
        $created = collect($this->team->createdByWeek($scope))->keyBy('label');
        $completed = collect($this->flow->throughputByWeek($scope))->keyBy('label');

        $labels = $created->keys()->merge($completed->keys())->unique()->values();

        return [
            'headers' => ['Week', 'Created', 'Completed'],
            'rows' => $labels->map(fn (string $label): array => [
                $label,
                (int) ($created[$label]['value'] ?? 0),
                (int) ($completed[$label]['value'] ?? 0),
            ])->all(),
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function cycleTimeTrend(StatisticsScope $scope): array
    {
        return [
            'headers' => ['Week', 'Median hours', 'Measured'],
            'rows' => collect($this->flow->cycleTimeTrend($scope))->map(fn (array $row): array => [
                (string) ($row['label'] ?? ''),
                // An unmeasured week is not a zero, and a CSV has to say which
                // it is or every reader will average the blanks in as zeroes.
                ($row['measured'] ?? true) === true ? round((float) ($row['value'] ?? 0), 2) : '',
                ($row['measured'] ?? true) === true ? 'yes' : 'no',
            ])->all(),
        ];
    }

    /**
     * The average, the median and the count, because a mean over four stays
     * says something very different from a mean over four hundred and a table
     * that omitted the count would invite reading them the same way.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function timeInColumn(StatisticsScope $scope): array
    {
        return [
            'headers' => ['Column', 'Average hours', 'Median hours', 'Completed stays'],
            'rows' => collect($this->flow->timeInColumn($scope))->map(fn (array $row): array => [
                (string) ($row['column'] ?? ''),
                $row['summary']->averageHours ?? '',
                $row['summary']->medianHours ?? '',
                (int) $row['summary']->count,
            ])->all(),
        ];
    }

    /**
     * How much of the work in scope the customer can see.
     *
     * Two counts rather than a percentage: the ratio is what the ring on the
     * screen already shows, and the counts are what a spreadsheet needs in
     * order to add this board's figures to another's.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function visibilitySplit(StatisticsScope $scope): array
    {
        $split = $this->team->visibilitySplit($scope);

        return [
            'headers' => ['Visibility', 'Tickets'],
            'rows' => [
                ['Shared with customer', (int) ($split['customer_visible'] ?? 0)],
                ['Internal', (int) ($split['internal'] ?? 0)],
            ],
        ];
    }

    /**
     * The AI panel's headline figures, one measure per row.
     *
     * Transposed rather than one wide row, because this is the shape somebody
     * pastes beside last month's and reads down.
     *
     * The cost is a bare number with the currency named in the header rather
     * than AiStatistics::formatCost() output: a cell reading "USD 0.12" is text
     * to every spreadsheet, and a column of text does not sum.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function aiSummary(StatisticsScope $scope): array
    {
        $ai = $this->ai->summary($scope);

        return [
            'headers' => ['Measure', 'Value'],
            'rows' => [
                ['Runs', (int) $ai['runs']],
                ['Suggest runs', (int) $ai['suggest']],
                ['Apply runs', (int) $ai['apply']],
                ['Completed', (int) $ai['completed']],
                ['Failed', (int) $ai['failed']],
                ['Cancelled', (int) $ai['cancelled']],
                // Blank rather than 0, for the same reason an unmeasured week
                // is blank above: nothing has finished, which is not the same
                // as nothing having succeeded.
                ['Success rate (%)', $ai['success_rate'] ?? ''],
                ['Pull requests', (int) $ai['pull_requests']],
                ['Input tokens', (int) $ai['input_tokens']],
                ['Output tokens', (int) $ai['output_tokens']],
                ['Total tokens', (int) $ai['total_tokens']],
                ['Estimated cost ('.$ai['currency'].')', round((float) $ai['known_cost'], 6)],
                // The figure above is a floor, not a total. This row is what
                // says so in a file with no room for the caveat the screen
                // prints underneath it.
                ['Runs excluded from cost', (int) $ai['unpriced_runs']],
            ],
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function aiRunsByWeek(StatisticsScope $scope): array
    {
        return [
            'headers' => ['Week', 'Completed', 'Failed'],
            'rows' => collect($this->ai->byWeek($scope))->map(fn (array $row): array => [
                (string) ($row['label'] ?? ''),
                (int) ($row['completed'] ?? 0),
                (int) ($row['failed'] ?? 0),
            ])->all(),
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function aiByBoard(StatisticsScope $scope): array
    {
        $currency = (string) $this->ai->summary($scope)['currency'];

        return [
            'headers' => [
                'Board', 'Runs', 'Completed', 'Failed',
                'Estimated cost ('.$currency.')', 'Runs excluded from cost',
            ],
            'rows' => collect($this->ai->byBoard($scope))->map(fn (array $row): array => [
                (string) ($row['label'] ?? ''),
                (int) ($row['runs'] ?? 0),
                (int) ($row['completed'] ?? 0),
                (int) ($row['failed'] ?? 0),
                round((float) ($row['known_cost'] ?? 0), 6),
                (int) ($row['unpriced_runs'] ?? 0),
            ])->all(),
        ];
    }

    /**
     * A single measure over the period's weeks.
     *
     * Every week in the period gets a row, including the empty ones, because
     * the series the chart draws is dense — a table that dropped its zeroes
     * would not line up beside the picture it belongs to.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function weekly(array $series, string $measure): array
    {
        return [
            'headers' => ['Week', $measure],
            'rows' => collect($series)->map(fn (array $row): array => [
                (string) ($row['label'] ?? ''),
                (int) ($row['value'] ?? 0),
            ])->all(),
        ];
    }

    /**
     * The shape every breakdown on the page already returns.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function series(array $series, string $dimension): array
    {
        return [
            'headers' => [$dimension, 'Tickets'],
            'rows' => collect($series)->map(fn (array $row): array => [
                (string) ($row['label'] ?? ''),
                (int) ($row['value'] ?? 0),
            ])->all(),
        ];
    }
}
