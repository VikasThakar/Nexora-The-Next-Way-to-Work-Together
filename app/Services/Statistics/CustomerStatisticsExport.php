<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Services\Statistics\Concerns\NamesExportFiles;

/**
 * The tables behind the customer summary's charts.
 *
 * A separate class from StatisticsExport rather than a customer-safe subset of
 * it, for the same reason App\Livewire\Stats\Customer is a separate component
 * from App\Livewire\Stats\Team: this one injects CustomerStatistics and nothing
 * else. TeamStatistics, FlowMetrics and AiStatistics are not reachable from
 * here, so there is no dataset name, no typo and no careless edit in this file
 * that could put an internal figure into a customer's download. The safety is
 * structural, not conditional.
 *
 * The alternative — one registry with the team's datasets filtered out by role
 * — would have put the whole of the team's reporting one inverted `if` away
 * from a customer's spreadsheet. A report is exactly the kind of thing that
 * gets edited by somebody adding "just one more number".
 *
 * Two names here also appear in StatisticsExport::KEYS. That is not a clash:
 * they are different tables behind different gates, and the customer's
 * `by-priority` counts only what CustomerStatistics can see.
 */
class CustomerStatisticsExport
{
    use NamesExportFiles;

    /**
     * The datasets that may be asked for.
     *
     * One per chart on the customer summary, and nothing else. In particular
     * there is no by-column dataset: the screen deliberately reduces the
     * team's workflow to open-and-closed, and a download that broke it back
     * out into "Blocked" and "In review" would undo that decision quietly —
     * see CustomerStatistics::statusSplit().
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'status-split' => 'Tickets by status',
        'by-priority' => 'Open tickets by priority',
        'created-by-week' => 'Tickets raised by week',
    ];

    public function __construct(
        private readonly CustomerStatistics $customer,
    ) {}

    public function exists(string $dataset): bool
    {
        return array_key_exists($dataset, self::KEYS);
    }

    public function label(string $dataset): string
    {
        return self::KEYS[$dataset] ?? 'Your tickets';
    }

    /**
     * Header row plus data rows, ready to be written as CSV.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    public function rows(string $dataset, StatisticsScope $scope): array
    {
        return match ($dataset) {
            'status-split' => $this->series($this->customer->statusSplit($scope), 'Status'),
            'by-priority' => $this->series($this->customer->byPriority($scope), 'Priority'),
            'created-by-week' => $this->weekly($this->customer->createdByWeek($scope), 'Raised'),

            // Unreachable through the controller, which checks exists() first.
            // Present so this method is total rather than relying on that.
            default => ['headers' => [], 'rows' => []],
        };
    }

    /**
     * Every week in the period gets a row, including the empty ones, so the
     * table lines up with the dense series the chart draws.
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
