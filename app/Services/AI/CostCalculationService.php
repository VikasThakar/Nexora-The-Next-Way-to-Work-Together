<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiRun;
use Illuminate\Support\Collection;

/**
 * What a run cost, in one place.
 *
 * Prices are configuration (`config/ai.php`), never literals scattered through
 * the code, because they change and because a rate that appears in three files
 * is a rate that will eventually disagree with itself. Nothing outside this
 * class knows a dollar figure.
 *
 * The important rule here is the honest blank. Three separate things can be
 * unknown — the model, the input tokens, the output tokens — and any one of
 * them makes the cost unknown. In that case this returns null, the column
 * stores null, and the screen says "not reported". It never falls back to a
 * default rate or to zero: somebody adds these figures up, and a plausible
 * invented number is worse than an obvious gap.
 */
class CostCalculationService
{
    /**
     * Estimated cost in the configured currency, or null when unknowable.
     *
     * Returned as a string so it goes into the decimal column without a float
     * round trip — a run costing $0.000075 must not become 7.5E-5.
     */
    public function estimate(?string $model, ?int $inputTokens, ?int $outputTokens): ?string
    {
        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $rates = $this->ratesFor($model);

        if ($rates === null) {
            return null;
        }

        $cost = ((int) $inputTokens / 1_000_000) * $rates['input']
            + ((int) $outputTokens / 1_000_000) * $rates['output'];

        return number_format($cost, 6, '.', '');
    }

    /**
     * The rates for a model, or null when the model is unpriced or unknown.
     *
     * A model id the table does not carry is not an error — a deployment may
     * legitimately point at something newer than this file — it just means the
     * cost cannot be stated.
     *
     * @return array{input: float, output: float}|null
     */
    public function ratesFor(?string $model): ?array
    {
        if ($model === null || trim($model) === '') {
            return null;
        }

        $table = (array) config('ai.pricing.per_million_tokens', []);
        $rates = $table[$model] ?? null;

        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])) {
            return null;
        }

        return ['input' => (float) $rates['input'], 'output' => (float) $rates['output']];
    }

    public function currency(): string
    {
        return (string) config('ai.pricing.currency', 'USD');
    }

    public function isPriced(?string $model): bool
    {
        return $this->ratesFor($model) !== null;
    }

    /**
     * Aggregate a set of runs for a cost summary.
     *
     * `known_cost` sums only the runs whose cost could be established, and
     * `unpriced_runs` counts the rest, so a total is never quietly understated
     * by the runs it had to skip. A summary that says "$4.10 across 12 runs, 3
     * of them unpriced" is useful; one that says "$4.10 across 15 runs" is a
     * lie by omission.
     *
     * @param  Collection<int, AiRun>  $runs
     * @return array{
     *     runs: int,
     *     known_cost: float,
     *     unpriced_runs: int,
     *     input_tokens: int,
     *     output_tokens: int,
     *     currency: string
     * }
     */
    public function summarise(Collection $runs): array
    {
        $knownCost = 0.0;
        $unpriced = 0;
        $input = 0;
        $output = 0;

        foreach ($runs as $run) {
            if ($run->estimated_cost === null) {
                $unpriced++;
            } else {
                $knownCost += (float) $run->estimated_cost;
            }

            $input += (int) $run->tokens_input;
            $output += (int) $run->tokens_output;
        }

        return [
            'runs' => $runs->count(),
            'known_cost' => round($knownCost, 6),
            'unpriced_runs' => $unpriced,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'currency' => $this->currency(),
        ];
    }
}
