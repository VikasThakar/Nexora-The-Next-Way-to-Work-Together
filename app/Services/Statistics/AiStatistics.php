<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\AiRunMode;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Services\AI\CostCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * What the automation did, and what it cost.
 *
 * Staff only, and not by a check in this class: every query starts from
 * `StatisticsScope::aiRuns()`, which starts from `AiRun::visibleTo()`, which
 * refuses customers outright rather than filtering them. A customer asking this
 * class for a total therefore gets zero rows — not a filtered subset, and not
 * an exception that would itself confirm the feature exists. The customer
 * statistics screen never instantiates it at all, which is the real guarantee;
 * this is the second one.
 *
 * The honesty rule from the AI phase carries through unchanged: a run whose
 * model or token counts were never reported has no cost, and that is reported
 * as an unpriced run rather than folded in as zero. A cost total that quietly
 * understates itself by the runs it could not price is worse than one that says
 * so.
 */
class AiStatistics
{
    public function __construct(private readonly CostCalculationService $costs) {}

    /**
     * Everything the AI panel's headline figures show, in one aggregate query.
     *
     * @return array{
     *     runs: int,
     *     suggest: int,
     *     apply: int,
     *     completed: int,
     *     failed: int,
     *     cancelled: int,
     *     automatic: int,
     *     manual: int,
     *     pull_requests: int,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     known_cost: float,
     *     unpriced_runs: int,
     *     currency: string,
     *     success_rate: ?float,
     * }
     */
    public function summary(StatisticsScope $scope): array
    {
        $row = (clone $scope->aiRuns())
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('SUM(CASE WHEN mode = ? THEN 1 ELSE 0 END) as suggest_runs', [AiRunMode::Suggest->value])
            ->selectRaw('SUM(CASE WHEN mode = ? THEN 1 ELSE 0 END) as apply_runs', [AiRunMode::Apply->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_runs', [AiRunStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_runs', [AiRunStatus::Failed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_runs', [AiRunStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN trigger_source = ? THEN 1 ELSE 0 END) as automatic_runs', [AiRunTrigger::Automatic->value])
            ->selectRaw('SUM(CASE WHEN trigger_source = ? THEN 1 ELSE 0 END) as manual_runs', [AiRunTrigger::Manual->value])
            ->selectRaw('SUM(CASE WHEN pull_request_url IS NOT NULL THEN 1 ELSE 0 END) as pull_requests')
            ->selectRaw('COALESCE(SUM(tokens_input), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(tokens_output), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as known_cost')
            // Counted, never assumed to be zero. See the class comment.
            ->selectRaw('SUM(CASE WHEN estimated_cost IS NULL THEN 1 ELSE 0 END) as unpriced_runs')
            ->first();

        $runs = (int) ($row->runs ?? 0);
        $completed = (int) ($row->completed_runs ?? 0);
        $failed = (int) ($row->failed_runs ?? 0);
        $finished = $completed + $failed;

        return [
            'runs' => $runs,
            'suggest' => (int) ($row->suggest_runs ?? 0),
            'apply' => (int) ($row->apply_runs ?? 0),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => (int) ($row->cancelled_runs ?? 0),
            'automatic' => (int) ($row->automatic_runs ?? 0),
            'manual' => (int) ($row->manual_runs ?? 0),
            'pull_requests' => (int) ($row->pull_requests ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens' => (int) ($row->input_tokens ?? 0) + (int) ($row->output_tokens ?? 0),
            'known_cost' => round((float) ($row->known_cost ?? 0), 6),
            'unpriced_runs' => (int) ($row->unpriced_runs ?? 0),
            'currency' => $this->costs->currency(),
            // Undefined rather than 100% when nothing has finished yet.
            'success_rate' => $finished === 0 ? null : round($completed / $finished * 100, 1),
        ];
    }

    /**
     * Runs per board, so a workspace-wide view shows where the spend is.
     *
     * @return array<int, array{label: string, runs: int, completed: int, failed: int, known_cost: float, unpriced_runs: int}>
     */
    public function byBoard(StatisticsScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        return (clone $scope->aiRuns())
            ->join('boards', 'boards.id', '=', 'ai_runs.board_id')
            ->groupBy('boards.id', 'boards.name')
            ->orderByDesc(DB::raw('COUNT(ai_runs.id)'))
            ->orderBy('boards.name')
            ->get([
                'boards.name',
                DB::raw('COUNT(ai_runs.id) as runs'),
                DB::raw('SUM(CASE WHEN ai_runs.status = \''.AiRunStatus::Completed->value.'\' THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN ai_runs.status = \''.AiRunStatus::Failed->value.'\' THEN 1 ELSE 0 END) as failed'),
                DB::raw('COALESCE(SUM(ai_runs.estimated_cost), 0) as known_cost'),
                DB::raw('SUM(CASE WHEN ai_runs.estimated_cost IS NULL THEN 1 ELSE 0 END) as unpriced_runs'),
            ])
            ->map(fn ($row): array => [
                'label' => (string) $row->name,
                'runs' => (int) $row->runs,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'known_cost' => round((float) $row->known_cost, 6),
                'unpriced_runs' => (int) $row->unpriced_runs,
            ])
            ->all();
    }

    /**
     * Runs per week, split by outcome, for the activity chart.
     *
     * @return array<int, array{label: string, completed: int, failed: int}>
     */
    public function byWeek(StatisticsScope $scope): array
    {
        $buckets = [];

        foreach ($scope->period->weekKeys() as $key) {
            $buckets[$key] = ['completed' => 0, 'failed' => 0];
        }

        (clone $scope->aiRuns())
            ->orderBy('ai_runs.id')
            ->select(['ai_runs.id', 'ai_runs.status', 'ai_runs.created_at'])
            ->chunk(2000, function ($runs) use (&$buckets, $scope): void {
                foreach ($runs as $run) {
                    $key = $scope->period->weekKeyFor($run->created_at);

                    if (! array_key_exists($key, $buckets)) {
                        continue;
                    }

                    if ($run->status === AiRunStatus::Failed) {
                        $buckets[$key]['failed']++;
                    } elseif ($run->status === AiRunStatus::Completed) {
                        $buckets[$key]['completed']++;
                    }
                }
            });

        $series = [];

        foreach ($scope->period->weeks() as $week) {
            $key = $scope->period->weekKeyFor($week['start']);

            $series[] = [
                'label' => $week['label'],
                'completed' => $buckets[$key]['completed'] ?? 0,
                'failed' => $buckets[$key]['failed'] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * Format a cost for display, in the configured currency.
     *
     * Small totals keep six decimals because a single suggest run genuinely
     * costs fractions of a cent, and rounding it to 0.00 makes the figure
     * look broken.
     */
    public function formatCost(float $amount): string
    {
        $currency = $this->costs->currency();

        return $amount > 0 && $amount < 0.01
            ? $currency.' '.number_format($amount, 6)
            : $currency.' '.number_format($amount, 2);
    }
}
