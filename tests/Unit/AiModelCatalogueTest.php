<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Support\AiModel;
use App\Support\AiModelCatalogue;
use Tests\TestCase;

/**
 * The catalogue, and the honest blank.
 *
 * The catalogue is the single list of models this deployment will send a
 * request to, and the interesting part of it is what it is allowed *not* to
 * know. A context window nobody looked up renders as nothing rather than as a
 * plausible number, because a figure on a model picker will be believed and
 * quoted.
 */
class AiModelCatalogueTest extends TestCase
{
    public function test_every_configured_model_names_a_provider_this_application_supports(): void
    {
        $models = AiModelCatalogue::all();

        $this->assertNotSame([], $models);

        foreach ($models as $id => $model) {
            $this->assertSame($id, $model->id);
            $this->assertInstanceOf(AiProvider::class, $model->provider);
            $this->assertNotSame('', $model->label);
        }
    }

    public function test_an_entry_naming_an_unknown_provider_is_dropped(): void
    {
        config(['ai.models' => [
            'good-model' => ['label' => 'Good', 'provider' => 'anthropic'],
            'orphan-model' => ['label' => 'Orphan', 'provider' => 'acme-ai'],
        ]]);

        $ids = AiModelCatalogue::ids();

        $this->assertContains('good-model', $ids);
        // Dropped rather than defaulted onto a provider that does exist:
        // sending a request for it somewhere is worse than not offering it.
        $this->assertNotContains('orphan-model', $ids);
    }

    public function test_models_are_filtered_by_provider(): void
    {
        $anthropic = AiModelCatalogue::forProvider(AiProvider::Anthropic);

        $this->assertNotSame([], $anthropic);

        foreach ($anthropic as $model) {
            $this->assertSame(AiProvider::Anthropic, $model->provider);
        }

        $this->assertTrue(AiModelCatalogue::serves(AiProvider::Anthropic, 'claude-opus-5'));
        $this->assertFalse(AiModelCatalogue::serves(AiProvider::OpenAi, 'claude-opus-5'));
    }

    public function test_a_provider_default_prefers_the_configured_default(): void
    {
        config(['ai.model.default' => 'claude-sonnet-5']);

        $this->assertSame('claude-sonnet-5', AiModelCatalogue::defaultFor(AiProvider::Anthropic));
    }

    /**
     * When the configured default belongs to another vendor, the provider's own
     * first model stands in — rather than a Claude id going to OpenAI.
     */
    public function test_a_provider_default_falls_back_to_its_own_first_model(): void
    {
        config(['ai.model.default' => 'claude-opus-5']);

        $openAi = AiModelCatalogue::defaultFor(AiProvider::OpenAi);

        if ($openAi === null) {
            $this->assertFalse(AiModelCatalogue::hasModelsFor(AiProvider::OpenAi));

            return;
        }

        $this->assertSame(AiProvider::OpenAi, AiModelCatalogue::providerFor($openAi));
    }

    /**
     * A provider a deployment has named but not filled in has no default, and
     * that null is what makes the configuration report itself unusable rather
     * than inventing a model id.
     */
    public function test_a_provider_with_no_models_has_no_default(): void
    {
        config(['ai.models' => [
            'claude-opus-5' => ['label' => 'Claude Opus 5', 'provider' => 'anthropic'],
        ]]);

        $this->assertFalse(AiModelCatalogue::hasModelsFor(AiProvider::OpenAi));
        $this->assertNull(AiModelCatalogue::defaultFor(AiProvider::OpenAi));
    }

    public function test_an_unknown_id_resolves_to_nothing(): void
    {
        $this->assertNull(AiModelCatalogue::find('a-model-nobody-configured'));
        $this->assertNull(AiModelCatalogue::find(''));
        $this->assertNull(AiModelCatalogue::find(null));
        $this->assertNull(AiModelCatalogue::providerFor('a-model-nobody-configured'));
    }

    // -----------------------------------------------------------------
    // What the picker shows
    // -----------------------------------------------------------------

    public function test_a_context_window_is_abbreviated_the_way_a_person_reads_it(): void
    {
        $million = new AiModel('m', 'M', AiProvider::Anthropic, 1000000);
        $thousand = new AiModel('k', 'K', AiProvider::Anthropic, 200000);
        $odd = new AiModel('o', 'O', AiProvider::Anthropic, 131_072);

        $this->assertSame('1M context', $million->contextWindowLabel());
        $this->assertSame('200K context', $thousand->contextWindowLabel());
        // Not rounded: rounding a window is rounding a limit.
        $this->assertSame('131,072 context', $odd->contextWindowLabel());
    }

    public function test_an_unknown_context_window_renders_as_nothing(): void
    {
        $model = new AiModel('x', 'X', AiProvider::OpenAi);

        $this->assertNull($model->contextWindowLabel());
        // The descriptor keeps what is known and states nothing else.
        $this->assertSame('OpenAI', $model->descriptor());
    }

    public function test_a_descriptor_states_only_what_is_known(): void
    {
        $full = new AiModel('a', 'A', AiProvider::Anthropic, 1000000, 'Balanced');
        $partial = new AiModel('b', 'B', AiProvider::Anthropic, null, 'Balanced');

        $this->assertSame('Anthropic · Balanced · 1M context', $full->descriptor());
        $this->assertSame('Anthropic · Balanced', $partial->descriptor());
    }

    public function test_a_zero_or_negative_context_window_is_treated_as_unknown(): void
    {
        $zero = AiModel::fromConfig('z', ['provider' => 'anthropic', 'context_window' => 0]);
        $negative = AiModel::fromConfig('n', ['provider' => 'anthropic', 'context_window' => -1]);

        $this->assertNull($zero->contextWindow);
        $this->assertNull($negative->contextWindow);
    }

    public function test_a_missing_label_falls_back_to_the_id(): void
    {
        $model = AiModel::fromConfig('some-model-id', ['provider' => 'anthropic']);

        $this->assertSame('some-model-id', $model->label);
    }

    /**
     * `ai.model.allowed` is derived from the catalogue rather than maintained
     * beside it, so a model cannot be offered by a picker and refused by
     * validation.
     */
    public function test_the_allowed_list_agrees_with_the_catalogue(): void
    {
        $this->assertSame(
            AiModelCatalogue::ids(),
            array_keys((array) config('ai.model.allowed')),
        );
    }
}
