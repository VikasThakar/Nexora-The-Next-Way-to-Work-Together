<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use Illuminate\Support\Str;

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
 * What is deliberately absent: any per-ticket export. These are aggregates.
 * "Every ticket on this board as a spreadsheet" is a different feature with a
 * different risk profile — it would carry titles and descriptions out of the
 * application in bulk — and nobody has asked for it.
 */
class StatisticsExport
{
    /**
     * The datasets that may be asked for, and what they are called in a
     * filename.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'created-vs-completed' => 'Tickets created and completed by week',
        'by-column' => 'Tickets by column',
        'by-priority' => 'Tickets by priority',
        'by-assignee' => 'Open tickets by assignee',
        'by-label' => 'Tickets by label',
        'cycle-time-trend' => 'Median cycle time by week',
        'time-in-column' => 'Average time in column',
    ];

    public function __construct(
        private readonly TeamStatistics $team,
        private readonly FlowMetrics $flow,
    ) {}

    public function exists(string $dataset): bool
    {
        return array_key_exists($dataset, self::KEYS);
    }

    public function label(string $dataset): string
    {
        return self::KEYS[$dataset] ?? 'Statistics';
    }

    /**
     * A filename that says what is in the file and over what period.
     *
     * The board is named when one is selected, because a folder of files called
     * `by-column.csv` is a folder of files nobody can tell apart.
     */
    public function filename(string $dataset, StatisticsScope $scope): string
    {
        return implode('-', array_filter([
            'nexora',
            $scope->board === null ? 'all-boards' : Str::slug($scope->board->name),
            $dataset,
            $scope->period->fromDate(),
            $scope->period->toDate(),
        ])).'.csv';
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
            'by-column' => $this->series($this->team->byColumn($scope), 'Column'),
            'by-priority' => $this->series($this->team->byPriority($scope), 'Priority'),
            'by-assignee' => $this->series($this->team->byAssignee($scope), 'Assignee'),
            'by-label' => $this->series($this->team->byLabel($scope), 'Label'),
            'cycle-time-trend' => $this->cycleTimeTrend($scope),
            'time-in-column' => $this->timeInColumn($scope),

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
