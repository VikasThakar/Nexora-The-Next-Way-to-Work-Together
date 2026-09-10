<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Services\AI\CostCalculationService;
use App\Support\AiModelCatalogue;
use Tests\TestCase;

/**
 * Cost tracking, and above all the honest blank.
 *
 * Three things can be unknown — the model, the input tokens, the output tokens —
 * and any one of them makes the cost unknown. Null must survive all the way to
 * the column and the screen: somebody adds these figures up, and a plausible
 * invented number is worse than an obvious gap.
 */
class CostCalculationTest extends TestCase
{
    private CostCalculationService $costs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->costs = new CostCalculationService;
    }

    public function test_it_prices_a_known_model(): void
    {
        // 1M input at $5 plus 1M output at $25.
        $this->assertSame(
            '30.000000',
            $this->costs->estimate('claude-opus-5', 1_000_000, 1_000_000)
        );
    }

    public function test_it_keeps_six_decimal_places_for_a_sub_cent_run(): void
    {
        // A small Haiku run costs a fraction of a cent, and rounding it to zero
        // would make a per-board total look free.
        $this->assertSame(
            '0.000075',
            $this->costs->estimate('claude-haiku-4-5', 50, 5)
        );
    }

    public function test_an_unknown_model_produces_no_cost(): void
    {
        // A deployment may legitimately point at something newer than the
        // pricing table. That is not an error; it just means the cost cannot be
        // stated.
        $this->assertNull($this->costs->estimate('claude-from-the-future', 1000, 1000));
        $this->assertFalse($this->costs->isPriced('claude-from-the-future'));
    }

    public function test_a_null_model_produces_no_cost(): void
    {
        $this->assertNull($this->costs->estimate(null, 1000, 1000));
    }

    public function test_unreported_tokens_produce_no_cost(): void
    {
        $this->assertNull($this->costs->estimate('claude-opus-5', null, null));
    }

    public function test_one_reported_half_is_still_priced(): void
    {
        // A provider that reports input but not output is unusual but not
        // meaningless: the known half is real spend.
        $this->assertSame(
            '0.005000',
            $this->costs->estimate('claude-opus-5', 1000, null)
        );
    }

    public function test_pricing_comes_from_configuration_only(): void
    {
        config(['ai.pricing.per_million_tokens.test-model' => ['input' => 100.0, 'output' => 200.0]]);

        // Nothing outside this service knows a rate, and the service knows only
        // what configuration tells it.
        $this->assertSame('0.300000', $this->costs->estimate('test-model', 1000, 1000));
    }

    public function test_a_malformed_pricing_entry_is_treated_as_unpriced(): void
    {
        config(['ai.pricing.per_million_tokens.broken' => ['input' => 1.0]]);

        // Half a rate is not a rate. Better an honest blank than half a cost.
        $this->assertNull($this->costs->estimate('broken', 1000, 1000));
    }

    public function test_a_summary_separates_known_cost_from_unpriced_runs(): void
    {
        $runs = collect([
            (object) ['estimated_cost' => '1.500000', 'tokens_input' => 100, 'tokens_output' => 50],
            (object) ['estimated_cost' => '0.500000', 'tokens_input' => 200, 'tokens_output' => 60],
            (object) ['estimated_cost' => null, 'tokens_input' => 300, 'tokens_output' => 70],
        ]);

        $summary = $this->costs->summarise($runs);

        // "$2.00 across 3 runs, 1 of them unpriced" is useful; "$2.00 across 3
        // runs" would be a lie by omission.
        $this->assertSame(3, $summary['runs']);
        $this->assertSame(2.0, $summary['known_cost']);
        $this->assertSame(1, $summary['unpriced_runs']);
        $this->assertSame(600, $summary['input_tokens']);
        $this->assertSame(180, $summary['output_tokens']);
    }

    /**
     * Every model this deployment has a first-party rate for is priced.
     *
     * This used to assert it of the whole selectable list, on the grounds that
     * a board pointed at an unpriced model would silently stop reporting cost.
     * The catalogue now spans two providers and the second one's model ids are
     * deployment-supplied (AI_OPENAI_MODELS), so that is no longer achievable
     * without inventing rates — which is the one thing this class exists not to
     * do.
     *
     * So the guarantee is narrower and the next test carries the other half:
     * the models whose rates are published are priced, and an unpriced model is
     * *visibly* unpriced rather than quietly free.
     */
    public function test_every_anthropic_model_offered_is_priced(): void
    {
        $models = AiModelCatalogue::forProvider(AiProvider::Anthropic);

        $this->assertNotSame([], $models);

        foreach (array_keys($models) as $model) {
            $this->assertTrue(
                $this->costs->isPriced($model),
                "The selectable model {$model} has no entry in config('ai.pricing')."
            );
        }
    }

    /**
     * An unpriced model reports nothing, not nought.
     *
     * The replacement for the old whole-catalogue guarantee. A model with no
     * configured rate is allowed to be selectable — a deployment may
     * legitimately point at something this file has no rates for — but its cost
     * has to arrive as null, all the way to the screen, so that a total is
     * never quietly understated by the runs it had to skip.
     */
    public function test_an_unpriced_model_reports_no_cost_rather_than_zero(): void
    {
        foreach (AiModelCatalogue::all() as $id => $model) {
            if ($this->costs->isPriced($id)) {
                continue;
            }

            $this->assertNull(
                $this->costs->estimate($id, 1000, 1000),
                "The unpriced model {$id} produced a cost figure."
            );
        }
    }

    public function test_the_default_model_is_priced(): void
    {
        $this->assertTrue($this->costs->isPriced((string) config('ai.model.default')));
    }
}
