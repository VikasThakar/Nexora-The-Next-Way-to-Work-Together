<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Livewire\Ai\GlobalSettings;
use App\Models\AiCredential;
use App\Models\AiGlobalSettings;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiCredentialVault;
use App\Support\AiModelCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The workspace-wide AI configuration: who may change it, what it stores, and
 * what it refuses.
 *
 * The screen exists because AI used to be configurable only per board, which
 * meant the provider credential, the default model and how far the AI was
 * trusted had no home. It is deliberately reachable without choosing a board
 * first, so the first test here is that the route works on its own.
 */
class GlobalAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Reaching the screen
    // -----------------------------------------------------------------

    public function test_the_screen_needs_no_board(): void
    {
        $this->actingAs($this->admin())
            ->withConfirmedPassword()
            ->get(route('admin.ai'))
            ->assertOk()
            ->assertSee('AI settings');
    }

    public function test_a_team_member_cannot_reach_it(): void
    {
        $this->actingAs($this->teamMember())
            ->withConfirmedPassword()
            ->get(route('admin.ai'))
            ->assertForbidden();
    }

    public function test_a_customer_cannot_reach_it(): void
    {
        $this->actingAs($this->customer())
            ->withConfirmedPassword()
            ->get(route('admin.ai'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.ai'))->assertRedirect(route('login'));
    }

    /**
     * The component authorizes on every call, not only on mount.
     *
     * Each Livewire request is its own HTTP request, so a team member who
     * somehow addressed the component directly must be refused by the action
     * rather than by the page that rendered it.
     */
    public function test_the_component_refuses_a_non_administrator_on_every_action(): void
    {
        Livewire::actingAs($this->teamMember())
            ->test(GlobalSettings::class)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Saving the configuration
    // -----------------------------------------------------------------

    public function test_an_administrator_can_set_the_provider_model_and_mode(): void
    {
        Livewire::actingAs($admin = $this->admin())
            ->test(GlobalSettings::class)
            ->set('provider', AiProvider::Anthropic->value)
            ->set('model', 'claude-sonnet-5')
            ->set('capabilityMode', AiCapabilityMode::Operator->value)
            ->set('sessionTokenLimit', '250000')
            ->set('dailyUserTokenLimit', '900000')
            ->call('save')
            ->assertHasNoErrors();

        $settings = AiGlobalSettings::query()->sole();

        $this->assertSame(AiProvider::Anthropic, $settings->provider);
        $this->assertSame('claude-sonnet-5', $settings->model);
        $this->assertSame(AiCapabilityMode::Operator, $settings->capability_mode);
        $this->assertSame(250000, $settings->session_token_limit);
        $this->assertSame(900000, $settings->daily_user_token_limit);
        $this->assertSame($admin->getKey(), $settings->updated_by_id);
    }

    public function test_a_blank_model_means_inherit_the_deployment_default(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('model', 'claude-sonnet-5')
            ->call('save')
            ->set('model', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(AiGlobalSettings::query()->sole()->model);

        // And the resolved configuration falls through to config, rather than
        // to nothing.
        $this->assertSame(
            (string) config('ai.model.default'),
            app(AiConfigurationResolver::class)->global()->model,
        );
    }

    public function test_a_model_the_catalogue_does_not_know_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('model', 'gpt-nonexistent-9')
            ->call('save')
            ->assertHasErrors('model');
    }

    public function test_a_mode_outside_the_enum_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('capabilityMode', 'everything')
            ->call('save')
            ->assertHasErrors('capabilityMode');
    }

    /**
     * Switching vendor cannot leave the workspace pointed at the old vendor's
     * model.
     *
     * Cleared rather than translated: there is no honest mapping between two
     * vendors' models, and null means "that provider's default", which is both
     * runnable and visibly inherited on the screen.
     */
    public function test_changing_provider_clears_a_model_the_new_provider_does_not_serve(): void
    {
        $openAiModel = array_key_first(AiModelCatalogue::forProvider(AiProvider::OpenAi));

        if ($openAiModel === null) {
            $this->markTestSkipped('This deployment lists no OpenAI models.');
        }

        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('model', 'claude-sonnet-5')
            ->call('save')
            ->set('provider', AiProvider::OpenAi->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(AiGlobalSettings::query()->sole()->model);
    }

    public function test_the_workspace_switch_can_turn_ai_off_without_touching_a_board(): void
    {
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $this->fakeAiProvider();

        $this->assertTrue(app(AiConfigurationResolver::class)->isUsable($board));

        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        app(AiConfigurationResolver::class)->flush();

        $this->assertFalse(app(AiConfigurationResolver::class)->isUsable($board));
        // The board's own settings are untouched.
        $this->assertNotNull($board->refresh()->aiSettings());
    }

    // -----------------------------------------------------------------
    // The singleton row
    // -----------------------------------------------------------------

    public function test_the_settings_row_exists_after_migration(): void
    {
        $this->assertSame(1, AiGlobalSettings::query()->count());
    }

    public function test_the_row_is_recreated_from_config_if_it_is_missing(): void
    {
        AiGlobalSettings::query()->delete();
        AiGlobalSettings::forgetCached();

        $settings = AiGlobalSettings::current();

        $this->assertTrue($settings->exists);
        $this->assertSame(AiCapabilityMode::default(), $settings->capability_mode);
    }

    public function test_the_columns_are_not_mass_assignable(): void
    {
        // Every AI model in this application has an empty $fillable: values are
        // assigned by the action that validated them, never taken from a
        // payload.
        $this->assertSame([], (new AiGlobalSettings)->getFillable());
        $this->assertSame([], (new AiCredential)->getFillable());
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    public function test_a_key_is_encrypted_at_rest(): void
    {
        $key = 'sk-ant-api03-not-a-real-key-abcd';

        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('keyProvider', AiProvider::Anthropic->value)
            ->set('newKey', $key)
            ->call('storeKey')
            ->assertHasNoErrors();

        $stored = AiCredential::query()->sole();

        // The column is ciphertext: it neither is nor contains the key.
        $this->assertNotSame($key, $stored->secret);
        $this->assertStringNotContainsString($key, $stored->secret);
        $this->assertStringNotContainsString('sk-ant-api03', $stored->secret);

        // And it really is our ciphertext, not a hash: the vault can read it
        // back, which is what makes the credential usable.
        $this->assertSame($key, Crypt::decryptString($stored->secret));
        $this->assertSame($key, app(AiCredentialVault::class)->keyFor(AiProvider::Anthropic));
    }

    public function test_only_the_last_four_characters_are_stored_for_display(): void
    {
        app(AiCredentialVault::class)->store(
            AiProvider::Anthropic,
            'sk-ant-api03-not-a-real-key-wxyz',
            'Production',
            $this->admin(),
        );

        $stored = AiCredential::query()->sole();

        $this->assertSame('wxyz', $stored->lastFour());
        $this->assertSame(str_repeat('•', 12).'wxyz', $stored->maskedSecret());
    }

    /**
     * The field starts blank and is blanked again, so a key never round-trips
     * to the browser in a component snapshot.
     */
    public function test_the_key_field_is_write_only(): void
    {
        $component = Livewire::actingAs($this->admin())->test(GlobalSettings::class);

        $component->assertSet('newKey', '');

        $component->set('newKey', 'sk-ant-api03-not-a-real-key-abcd')
            ->call('storeKey')
            ->assertHasNoErrors()
            ->assertSet('newKey', '');
    }

    public function test_a_rejected_key_is_still_cleared_from_the_component(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('newKey', 'https://hooks.slack.com/services/T000/B000/xxxx')
            ->call('storeKey')
            ->assertHasErrors('newKey')
            ->assertSet('newKey', '');

        $this->assertSame(0, AiCredential::query()->count());
    }

    public function test_a_key_for_the_wrong_provider_is_refused(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('keyProvider', AiProvider::OpenAi->value)
            // An Anthropic-shaped key. The prefix check is what catches it.
            ->set('newKey', 'sk-ant-api03-not-a-real-key-abcd')
            ->call('storeKey')
            ->assertHasErrors('newKey');

        $this->assertSame(0, AiCredential::query()->count());
    }

    public function test_storing_a_key_twice_replaces_rather_than_accumulates(): void
    {
        $vault = app(AiCredentialVault::class);

        $vault->store(AiProvider::Anthropic, 'sk-ant-api03-first-key-000000aaaa', null);
        $vault->store(AiProvider::Anthropic, 'sk-ant-api03-second-key-00000bbbb', null);

        $this->assertSame(1, AiCredential::query()->count());
        $this->assertSame('bbbb', AiCredential::query()->sole()->lastFour());
    }

    public function test_a_key_can_be_removed(): void
    {
        app(AiCredentialVault::class)->store(AiProvider::Anthropic, 'sk-ant-api03-not-a-real-key-abcd', null);

        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->call('removeKey', AiProvider::Anthropic->value);

        $this->assertSame(0, AiCredential::query()->count());
    }

    /**
     * A key that cannot be decrypted reads as absent rather than throwing.
     *
     * In practice this means APP_KEY was rotated after the key was stored. The
     * honest consequence is that the AI stops working until somebody pastes it
     * again — not that every page asking "is AI configured?" breaks.
     */
    public function test_an_unreadable_key_degrades_to_not_configured(): void
    {
        $credential = new AiCredential;
        $credential->provider = AiProvider::Anthropic;
        $credential->secret = 'not-valid-ciphertext';
        $credential->last_four = 'abcd';
        $credential->save();

        config(['ai.anthropic.api_key' => null]);
        app(AiConfigurationResolver::class)->flush();

        $this->assertNull(app(AiCredentialVault::class)->keyFor(AiProvider::Anthropic));
    }

    /**
     * The environment stays a first-class source, so a deployment that has
     * always set ANTHROPIC_API_KEY keeps working with no database row.
     */
    public function test_a_stored_key_takes_precedence_over_the_environment(): void
    {
        config(['ai.anthropic.api_key' => 'sk-ant-from-the-environment-0000']);

        $vault = app(AiCredentialVault::class);

        $this->assertSame('config', $vault->sourceFor(AiProvider::Anthropic));
        $this->assertSame('sk-ant-from-the-environment-0000', $vault->keyFor(AiProvider::Anthropic));

        $vault->store(AiProvider::Anthropic, 'sk-ant-api03-stored-in-the-db-abcd', null);

        $this->assertSame('global', $vault->sourceFor(AiProvider::Anthropic));
        $this->assertSame('sk-ant-api03-stored-in-the-db-abcd', $vault->keyFor(AiProvider::Anthropic));
    }
}
