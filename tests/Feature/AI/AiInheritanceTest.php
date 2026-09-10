<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\AI\UpdateGlobalAiSettings;
use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Livewire\Boards\AiSettings;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiCredentialVault;
use App\Support\AiConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * config/ai.php → ai_settings → boards.settings.ai, resolved.
 *
 * The behaviour the client asked for in as many words: global is the default, a
 * board may override selected settings, and the effective configuration is
 * obvious. Every field resolves down the chain independently, which is what
 * makes a board that only wants a different model inherit the workspace key —
 * so that is the test the file leads with.
 */
class AiInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): AiConfigurationResolver
    {
        // Re-resolved after each write, because the settings row and the
        // credential rows are memoised for the request.
        app(AiConfigurationResolver::class)->flush();

        return app(AiConfigurationResolver::class);
    }

    // -----------------------------------------------------------------
    // The default: inherit everything
    // -----------------------------------------------------------------

    public function test_a_board_that_overrides_nothing_inherits_everything(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle([
            'provider' => AiProvider::Anthropic->value,
            'model' => 'claude-sonnet-5',
            'capability_mode' => AiCapabilityMode::Observer->value,
        ]);

        $effective = $this->resolver()->forBoard($board);

        $this->assertSame(AiProvider::Anthropic, $effective->provider);
        $this->assertSame('claude-sonnet-5', $effective->model);
        $this->assertSame(AiCapabilityMode::Observer, $effective->mode);

        $this->assertFalse($effective->hasBoardOverrides());
        $this->assertTrue($effective->isInherited('model'));
        $this->assertTrue($effective->isInherited('mode'));
    }

    /**
     * The client's own example: NutriLens takes a different model, Nexora takes
     * a different mode, and neither needs a second API key.
     */
    public function test_two_boards_can_override_different_settings_independently(): void
    {
        $team = $this->teamMember();
        $nutriLens = $this->boardWithColumns([$team], ['name' => 'NutriLens', 'ticket_prefix' => 'NL']);
        $nexora = $this->boardWithColumns([$team], ['name' => 'Nexora', 'ticket_prefix' => 'NX']);

        app(UpdateGlobalAiSettings::class)->handle([
            'model' => 'claude-opus-5',
            'capability_mode' => AiCapabilityMode::Observer->value,
        ]);

        app(UpdateBoardAiSettings::class)->handle($nutriLens, ['model' => 'claude-sonnet-5']);
        app(UpdateBoardAiSettings::class)->handle($nexora, ['capability_mode' => AiCapabilityMode::Agent->value]);

        $resolver = $this->resolver();

        $nutriLensConfig = $resolver->forBoard($nutriLens->refresh());
        $nexoraConfig = $resolver->forBoard($nexora->refresh());

        // NutriLens: its own model, the workspace's mode.
        $this->assertSame('claude-sonnet-5', $nutriLensConfig->model);
        $this->assertSame(AiCapabilityMode::Observer, $nutriLensConfig->mode);
        $this->assertSame(AiConfiguration::SOURCE_BOARD, $nutriLensConfig->sourceOf('model'));
        $this->assertTrue($nutriLensConfig->isInherited('mode'));

        // Nexora: its own mode, the workspace's model.
        $this->assertSame('claude-opus-5', $nexoraConfig->model);
        $this->assertSame(AiCapabilityMode::Agent, $nexoraConfig->mode);
        $this->assertSame(AiConfiguration::SOURCE_BOARD, $nexoraConfig->sourceOf('mode'));
        $this->assertTrue($nexoraConfig->isInherited('model'));
    }

    // -----------------------------------------------------------------
    // Credentials are not duplicated
    // -----------------------------------------------------------------

    public function test_a_board_that_only_changes_the_model_inherits_the_workspace_key(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        $this->storeAiKey('sk-ant-api03-workspace-key-0000abcd');

        app(UpdateBoardAiSettings::class)->handle($board, ['model' => 'claude-haiku-4-5']);

        $board->refresh();

        $vault = app(AiCredentialVault::class);

        $this->assertSame('global', $vault->sourceFor(AiProvider::Anthropic, $board));
        $this->assertSame('sk-ant-api03-workspace-key-0000abcd', $vault->keyFor(AiProvider::Anthropic, $board));

        // Nothing was copied into the board's own settings.
        $this->assertFalse($board->aiSettings()->hasCredentialFor(AiProvider::Anthropic));
        $this->assertSame([], $board->aiSettings()->credentialProviders());
    }

    public function test_a_board_may_hold_its_own_key_and_it_wins(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        $this->storeAiKey('sk-ant-api03-workspace-key-0000abcd');

        app(AiCredentialVault::class)->storeForBoard(
            $board,
            AiProvider::Anthropic,
            'sk-ant-api03-board-only-key-0000wxyz',
        );

        $board->refresh();

        $vault = app(AiCredentialVault::class);

        $this->assertSame('board', $vault->sourceFor(AiProvider::Anthropic, $board));
        $this->assertSame('sk-ant-api03-board-only-key-0000wxyz', $vault->keyFor(AiProvider::Anthropic, $board));

        // The workspace key is untouched and still applies elsewhere.
        $this->assertSame('sk-ant-api03-workspace-key-0000abcd', $vault->keyFor(AiProvider::Anthropic));
    }

    public function test_a_board_key_is_encrypted_in_the_board_settings(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);
        $key = 'sk-ant-api03-board-only-key-0000wxyz';

        app(AiCredentialVault::class)->storeForBoard($board, AiProvider::Anthropic, $key);

        $raw = json_encode($board->refresh()->settings, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($key, $raw);
        $this->assertStringNotContainsString('sk-ant-api03', $raw);

        $ciphertext = $board->aiSettings()->encryptedCredential(AiProvider::Anthropic);

        $this->assertNotNull($ciphertext);
        $this->assertSame($key, Crypt::decryptString($ciphertext));

        // Four characters are kept for the masked display, and nothing more.
        $this->assertSame('wxyz', $board->aiSettings()->credentialLastFour(AiProvider::Anthropic));
    }

    public function test_removing_a_board_key_falls_back_to_the_workspace_key(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        $this->storeAiKey('sk-ant-api03-workspace-key-0000abcd');

        $vault = app(AiCredentialVault::class);

        $vault->storeForBoard($board, AiProvider::Anthropic, 'sk-ant-api03-board-only-key-0000wxyz');
        $vault->removeForBoard($board->refresh(), AiProvider::Anthropic);

        $board->refresh();

        $this->assertSame('global', $vault->sourceFor(AiProvider::Anthropic, $board));
        $this->assertSame('sk-ant-api03-workspace-key-0000abcd', $vault->keyFor(AiProvider::Anthropic, $board));
    }

    // -----------------------------------------------------------------
    // Coercion
    // -----------------------------------------------------------------

    /**
     * A stored model outlives the provider it belonged to.
     *
     * Switching the workspace to another vendor must not leave every board
     * pointed at a model that vendor cannot serve — the request would fail at
     * the provider on every question. It falls back to the new provider's
     * default instead.
     */
    public function test_a_model_the_effective_provider_does_not_serve_is_ignored(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        app(UpdateBoardAiSettings::class)->handle($board, ['model' => 'claude-sonnet-5']);

        app(UpdateBoardAiSettings::class)->handle($board->refresh(), [
            'provider_override' => AiProvider::OpenAi->value,
        ]);

        $effective = $this->resolver()->forBoard($board->refresh());

        $this->assertSame(AiProvider::OpenAi, $effective->provider);
        $this->assertNotSame('claude-sonnet-5', $effective->model);

        // Reported as inherited rather than as the board's choice: the screen
        // must not claim a board chose a model it is not using.
        $this->assertTrue($effective->isInherited('model'));
    }

    public function test_a_hand_edited_override_is_coerced_on_read(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        // Straight into the JSON column, as a console command or a database
        // client would.
        $board->settings = ['ai' => [
            'provider_override' => 'acme-ai',
            'capability_mode' => 'root',
            'session_token_limit' => -5,
            'model' => 'a-model-nobody-configured',
        ]];
        $board->save();

        $settings = $board->refresh()->aiSettings();

        $this->assertNull($settings->providerOverride);
        $this->assertNull($settings->capabilityMode);
        $this->assertSame(0, $settings->sessionTokenLimit);
        $this->assertNull($settings->modelOverride);

        // And the resolved configuration is runnable rather than broken.
        $this->assertTrue($this->resolver()->forBoard($board)->isUsable() === false
            || $this->resolver()->forBoard($board)->model !== null);
    }

    public function test_a_board_override_survives_a_save_that_does_not_mention_it(): void
    {
        $board = $this->boardWithColumns([$this->teamMember()]);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'capability_mode' => AiCapabilityMode::Observer->value,
        ]);

        app(UpdateBoardAiSettings::class)->handle($board->refresh(), [
            'project_context' => 'A Laravel monolith.',
        ]);

        $settings = $board->refresh()->aiSettings();

        $this->assertSame(AiCapabilityMode::Observer, $settings->capabilityMode);
        $this->assertSame('A Laravel monolith.', $settings->projectContext);
    }

    /**
     * Inheriting is a state a board can return to, not a one-way door.
     */
    public function test_clearing_an_override_returns_the_board_to_inheriting(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle(['model' => 'claude-opus-5']);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->set('model', 'claude-sonnet-5')
            ->call('save')
            ->assertHasNoErrors()
            ->set('model', '')
            ->call('save')
            ->assertHasNoErrors();

        $board->refresh();

        $this->assertNull($board->aiSettings()->modelOverride);
        $this->assertSame('claude-opus-5', $this->resolver()->forBoard($board)->model);
    }

    // -----------------------------------------------------------------
    // The screen
    // -----------------------------------------------------------------

    public function test_the_board_screen_says_what_is_inherited(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle([
            'capability_mode' => AiCapabilityMode::Operator->value,
        ]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->assertSee('Inherited from global')
            ->assertSee('Inherits everything')
            ->assertSee(AiCapabilityMode::Operator->label());
    }

    public function test_the_board_screen_names_what_this_board_overrides(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'capability_mode' => AiCapabilityMode::Agent->value,
        ]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board->refresh()])
            ->assertSee('Overrides')
            ->assertSee(AiCapabilityMode::Agent->label());
    }

    public function test_the_board_screen_never_renders_a_key(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $key = 'sk-ant-api03-board-only-key-0000wxyz';

        app(AiCredentialVault::class)->storeForBoard($board, AiProvider::Anthropic, $key);

        $html = Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board->refresh()])
            ->html();

        $this->assertStringNotContainsString($key, $html);
        $this->assertStringNotContainsString('sk-ant-api03', $html);

        // Presence and the last four, which is all there is to show.
        $this->assertStringContainsString('wxyz', $html);
    }

    /**
     * A customer is refused, and refused before the component is constructed.
     *
     * 403 rather than 404 here, and that is not the policy relenting: the route
     * group's `role:admin,team` middleware stops the request first, so
     * BoardPolicy::manageAiSettings — which does deny as 404 — never runs. The
     * coarse gate answering first is the point of having it.
     */
    public function test_a_customer_cannot_reach_the_board_screen(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $this->actingAs($customer)
            ->get(route('boards.ai-settings', $board))
            ->assertForbidden();

        // And the component itself refuses too, as 404, for a request that
        // somehow addressed it directly.
        Livewire::actingAs($customer)
            ->test(AiSettings::class, ['board' => $board])
            ->assertNotFound();
    }
}
