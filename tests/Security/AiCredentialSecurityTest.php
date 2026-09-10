<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\AiProvider;
use App\Livewire\Ai\GlobalSettings;
use App\Livewire\Boards\AiSettings;
use App\Models\AiCredential;
use App\Models\AiGlobalSettings;
use App\Services\AI\AiCredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A stored provider key never comes back out.
 *
 * Tests\Security\SecretExposureTest already covers the credentials this
 * application reads from the environment. This file covers the new ones — keys
 * an administrator stores through a screen — which leak differently and in more
 * places, because unlike an environment variable they have a form, a model, a
 * Livewire component and a JSON column between them and safety.
 *
 * Each test names the specific escape route it closes. Together they are the
 * claim the settings screen makes: you can replace a key, you cannot read one.
 */
class AiCredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive enough that a substring match cannot be a coincidence. */
    private const KEY = 'sk-ant-api03-NEVER-RENDER-THIS-KEY-abcd';

    private function storeKey(): AiCredential
    {
        return app(AiCredentialVault::class)->store(
            AiProvider::Anthropic,
            self::KEY,
            'Production',
            $this->admin(),
        );
    }

    // -----------------------------------------------------------------
    // At rest
    // -----------------------------------------------------------------

    public function test_the_column_holds_ciphertext_and_not_the_key(): void
    {
        $this->storeKey();

        // Read as a raw row, not through the model, so no cast can be doing the
        // work.
        $row = DB::table('ai_credentials')->sole();

        $this->assertStringNotContainsString(self::KEY, $row->secret);
        $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $row->secret);
    }

    /**
     * A whole-table dump — the shape of a database backup, or of somebody with
     * a MySQL client — contains no key.
     */
    public function test_no_key_appears_anywhere_in_the_ai_tables(): void
    {
        $this->storeKey();

        $board = $this->boardWithColumns([$this->teamMember()]);
        app(AiCredentialVault::class)->storeForBoard($board, AiProvider::Anthropic, self::KEY);

        foreach (['ai_credentials', 'ai_settings', 'boards', 'ai_sessions', 'ai_usage_records'] as $table) {
            $dump = json_encode(
                DB::table($table)->get(),
                JSON_THROW_ON_ERROR
            );

            $this->assertStringNotContainsString(
                'NEVER-RENDER-THIS-KEY',
                $dump,
                "A credential leaked into the {$table} table.",
            );
        }
    }

    // -----------------------------------------------------------------
    // In a response
    // -----------------------------------------------------------------

    public function test_the_settings_screen_reports_presence_not_the_value(): void
    {
        $this->storeKey();

        $html = $this->actingAs($this->admin())
            ->withConfirmedPassword()
            ->get(route('admin.ai'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $html);

        // What it does show: that a key is configured, and its last four.
        $this->assertStringContainsString('Configured', $html);
        $this->assertStringContainsString('abcd', $html);
    }

    /**
     * The Livewire snapshot is the subtle one.
     *
     * A public property round-trips to the browser on every request, so a key
     * left in one would be readable in the page source even though no template
     * printed it. The field is blanked before the response is rendered.
     */
    public function test_no_key_reaches_the_livewire_snapshot(): void
    {
        $html = Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('newKey', self::KEY)
            ->call('storeKey')
            ->assertHasNoErrors()
            ->html();

        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $html);
    }

    public function test_the_component_holds_no_key_after_storing_one(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('newKey', self::KEY)
            ->call('storeKey');

        $component->assertSet('newKey', '');

        /*
         * And nowhere else on the component.
         *
         * Every public property is checked by reflection rather than only the
         * one the form binds, because "the key ended up in a different property
         * too" is exactly the mistake this test is for — a hint field, a
         * validation echo, a copy kept for a confirmation message. A public
         * property is what round-trips to the browser, so the reflection is
         * over public properties precisely.
         */
        $instance = $component->instance();

        foreach ((new \ReflectionObject($instance))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($instance);

            if (is_string($value)) {
                $this->assertStringNotContainsString(
                    'NEVER-RENDER-THIS-KEY',
                    $value,
                    'The property ['.$property->getName().'] holds a credential.',
                );
            }
        }
    }

    /**
     * The model must not carry the ciphertext into a serialisation either.
     *
     * Ciphertext is not plaintext, and it is also not something an application
     * has any reason to hand out — an API response, a queued job payload, a log
     * line of a dumped model.
     */
    public function test_the_model_does_not_serialise_the_secret(): void
    {
        $credential = $this->storeKey();

        $this->assertArrayNotHasKey('secret', $credential->toArray());
        $this->assertStringNotContainsString('secret', $credential->toJson());
    }

    public function test_the_global_settings_model_carries_no_credential_at_all(): void
    {
        $this->storeKey();

        $serialised = AiGlobalSettings::current()->toJson();

        $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $serialised);
        // There is no key column on this table by design; keys are their own
        // rows so that reading the configuration loads no ciphertext.
        $this->assertArrayNotHasKey('secret', AiGlobalSettings::current()->toArray());
        $this->assertArrayNotHasKey('api_key', AiGlobalSettings::current()->toArray());
    }

    // -----------------------------------------------------------------
    // In a log line
    // -----------------------------------------------------------------

    public function test_storing_a_key_writes_nothing_to_the_log(): void
    {
        $written = [];

        Log::listen(function ($message) use (&$written): void {
            $written[] = $message->message.' '.json_encode($message->context);
        });

        Livewire::actingAs($this->admin())
            ->test(GlobalSettings::class)
            ->set('newKey', self::KEY)
            ->call('storeKey')
            ->assertHasNoErrors();

        foreach ($written as $line) {
            $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $line);
        }
    }

    /**
     * The masking helpers throw away all but four characters before they
     * return, so there is no path from them to a key either.
     */
    public function test_masking_reveals_only_four_characters(): void
    {
        $masked = AiCredentialVault::mask(self::KEY);

        $this->assertStringNotContainsString('NEVER-RENDER-THIS-KEY', $masked);
        $this->assertSame(str_repeat('•', 12).'abcd', $masked);

        // A key too short to mask safely gets no hint at all, rather than most
        // of itself.
        $this->assertNull(AiCredentialVault::lastFour('sk-abc'));
        $this->assertSame(str_repeat('•', 12), AiCredentialVault::mask('sk-abc'));
    }

    // -----------------------------------------------------------------
    // Who may hold one
    // -----------------------------------------------------------------

    public function test_a_team_member_cannot_store_a_workspace_key(): void
    {
        Livewire::actingAs($this->teamMember())
            ->test(GlobalSettings::class)
            ->assertForbidden();

        $this->assertSame(0, AiCredential::query()->count());
    }

    public function test_a_customer_cannot_store_a_board_key(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        Livewire::actingAs($customer)
            ->test(AiSettings::class, ['board' => $board])
            ->assertNotFound();

        $this->assertFalse($board->refresh()->aiSettings()->hasCredentialFor(AiProvider::Anthropic));
    }

    /**
     * A board key is not a way around the workspace's boundaries: storing one
     * requires the same ability as every other AI setting on that board.
     */
    public function test_a_staff_member_of_another_board_cannot_store_a_board_key(): void
    {
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$this->teamMember()]);

        Livewire::actingAs($outsider)
            ->test(AiSettings::class, ['board' => $board])
            ->assertNotFound();

        $this->assertFalse($board->refresh()->aiSettings()->hasCredentialFor(AiProvider::Anthropic));
    }
}
