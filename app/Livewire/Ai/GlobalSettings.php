<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Actions\AI\UpdateGlobalAiSettings;
use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Models\AiGlobalSettings;
use App\Models\AiToolInvocation;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiCredentialVault;
use App\Services\AI\Voice\VoiceProviderInterface;
use App\Support\AiModelCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The workspace's AI control area.
 *
 * The screen the client asked for: one place to say which vendor answers, which
 * model by default, how far the AI is trusted and what it may spend — without
 * picking a board first. Every board then inherits it, and departs from it only
 * where somebody deliberately says so on that board's own AI screen.
 *
 * Authorized three times over, matching the other administration screens in
 * this application: the route group carries `role:admin` plus password
 * confirmation, and this component authorizes `administer-ai` on mount, on
 * every action and on every render. Each Livewire call is its own request and
 * must not trust what the last render decided.
 *
 * Keys are write-only, on every load
 * ----------------------------------
 * The key fields start blank and are blanked again after every save, exactly as
 * the board Slack webhook field does — see App\Livewire\Boards\Integrations for
 * the reasoning, which applies with more force here. There is no code path from
 * a stored key back to this screen: the vault has no method that would return
 * one to a component, the model hides the ciphertext column, and what is
 * rendered is presence plus the last four characters. Somebody who gains access
 * to this screen can replace a credential — which is visible in the row's
 * `rotated_at` and `updated_by_id` — but cannot read one out.
 *
 * The plaintext exists in one public property for the length of one request,
 * because a person typed it into a form and a form is how it arrives. It is
 * cleared before the response is rendered, so it is never sent back to the
 * browser in a component snapshot, and it is never passed to anything but the
 * vault.
 *
 * What it does not do
 * -------------------
 * It does not test a key by calling the provider. That would be a nice
 * affordance and a bad idea on this screen: a "test" button that spends money
 * and can be pressed repeatedly by anybody who reaches the page is a cost
 * amplifier, and the first real question already tells you whether the
 * credential works — the provider's own refusal is reported verbatim.
 */
#[Layout('layouts.app')]
#[Title('AI settings')]
class GlobalSettings extends Component
{
    // --- Configuration ---
    public bool $enabled = true;

    public string $provider = AiProvider::ANTHROPIC;

    /** Empty means "use the deployment default". */
    public string $model = '';

    public string $capabilityMode = AiCapabilityMode::OBSERVER;

    /**
     * Ceilings, held as strings.
     *
     * Because empty and zero are different answers — inherit the config
     * default, and no limit at all — and an int property cannot express the
     * first. The action turns them back into a nullable int.
     */
    public string $sessionTokenLimit = '';

    public string $dailyUserTokenLimit = '';

    // --- Credentials ---

    /**
     * Which provider the key field is for.
     */
    public string $keyProvider = AiProvider::ANTHROPIC;

    /**
     * Blank on purpose, on every load. See the class comment.
     */
    public string $newKey = '';

    public string $keyHint = '';

    public function mount(): void
    {
        $this->authorize('administer-ai');

        $this->fillFromSettings(AiGlobalSettings::current());
    }

    private function fillFromSettings(AiGlobalSettings $settings): void
    {
        $this->enabled = $settings->enabled;
        $this->provider = $settings->provider->value;
        $this->model = (string) $settings->model;
        $this->capabilityMode = $settings->capability_mode->value;
        $this->sessionTokenLimit = $settings->session_token_limit === null
            ? ''
            : (string) $settings->session_token_limit;
        $this->dailyUserTokenLimit = $settings->daily_user_token_limit === null
            ? ''
            : (string) $settings->daily_user_token_limit;

        // The key field follows the configured provider, because that is the
        // key somebody arriving on this screen almost always means to set.
        $this->keyProvider = $this->provider;
        $this->newKey = '';
        $this->keyHint = '';
    }

    /**
     * Keep the model choice honest when the provider changes.
     *
     * A model belongs to exactly one vendor, so switching provider makes the
     * current selection meaningless. Cleared to "default" rather than mapped
     * onto something of the new provider's, because there is no honest mapping
     * between two vendors' models.
     */
    public function updatedProvider(string $value): void
    {
        if (! AiModelCatalogue::serves(AiProvider::fromValue($value) ?? AiProvider::default(), $this->model)) {
            $this->model = '';
        }

        $this->keyProvider = $value;
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public function save(UpdateGlobalAiSettings $update): void
    {
        $this->authorize('administer-ai');

        $validated = $this->validate([
            'enabled' => ['boolean'],
            // Only a provider this application has an adapter for, and only a
            // model this deployment's catalogue lists: a crafted request must
            // not be able to point the workspace at an arbitrary string.
            'provider' => ['required', Rule::in(AiProvider::values())],
            'model' => ['nullable', 'string', Rule::in(AiModelCatalogue::ids())],
            'capabilityMode' => ['required', Rule::in(AiCapabilityMode::values())],
            // A ceiling of zero is meaningful ("no limit"), so the floor is
            // zero rather than one. The upper bound is a sanity check, not a
            // policy: it stops a mistyped figure becoming an effectively
            // unlimited one that reads like a limit.
            'sessionTokenLimit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'dailyUserTokenLimit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], attributes: [
            'capabilityMode' => 'AI mode',
            'sessionTokenLimit' => 'session token limit',
            'dailyUserTokenLimit' => 'daily limit per person',
        ]);

        $settings = $update->handle([
            'enabled' => $validated['enabled'],
            'provider' => $validated['provider'],
            'model' => $validated['model'] ?? '',
            'capability_mode' => $validated['capabilityMode'],
            'session_token_limit' => $validated['sessionTokenLimit'] ?? null,
            'daily_user_token_limit' => $validated['dailyUserTokenLimit'] ?? null,
        ], auth()->user());

        // Refilled from what was actually stored rather than from what was
        // submitted, so a model cleared because the provider changed shows as
        // inherited immediately.
        $this->fillFromSettings($settings);

        session()->flash('status', 'AI settings saved.');
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    /**
     * Store or replace a provider key.
     *
     * Add and Replace are one operation, because there is no way to read a
     * stored key: "change it" can only ever mean "supply a new one".
     */
    public function storeKey(AiCredentialVault $vault): void
    {
        $this->authorize('administer-ai');

        $provider = AiProvider::fromValue($this->keyProvider);

        if (! $provider instanceof AiProvider) {
            $this->addError('keyProvider', 'Choose a provider for this key.');

            return;
        }

        $key = trim($this->newKey);

        if ($key === '') {
            $this->addError('newKey', 'Paste the API key you want to store.');

            return;
        }

        $this->validate([
            'keyHint' => ['nullable', 'string', 'max:60'],
        ], attributes: ['keyHint' => 'label']);

        /*
         * A shape check, not validation.
         *
         * It catches what actually goes wrong — a truncated copy, a pasted URL,
         * a key for the other provider — and says so next to the field instead
         * of storing something that will fail on the first real question. Only
         * the provider can say whether a well-shaped key works, and a revoked
         * key passes this and fails on use, which is correct.
         */
        if (! AiCredentialVault::looksLikeKey($provider, $key)) {
            $this->addError('newKey', 'That does not look like a '.$provider->label()
                .' API key. They begin with '.$provider->keyPrefixHint().'.');

            // Cleared even on the error path: a rejected key must not be sent
            // back to the browser in the next snapshot.
            $this->newKey = '';

            return;
        }

        $vault->store($provider, $key, $this->keyHint, auth()->user());

        // The plaintext leaves this component here and now.
        $this->newKey = '';
        $this->keyHint = '';

        app(AiConfigurationResolver::class)->flush();

        session()->flash('status', $provider->label().' API key stored. It is encrypted and cannot be read back.');
    }

    /**
     * Remove a stored key.
     *
     * Says plainly what happens next, because it is not always "AI stops": a
     * deployment that also sets the environment variable falls back to it, and
     * a screen that implied otherwise would send somebody hunting for a
     * credential that is working exactly as configured.
     */
    public function removeKey(string $providerValue, AiCredentialVault $vault): void
    {
        $this->authorize('administer-ai');

        $provider = AiProvider::fromValue($providerValue);

        if (! $provider instanceof AiProvider) {
            return;
        }

        $vault->remove($provider);

        app(AiConfigurationResolver::class)->flush();

        $fallsBackToEnvironment = filled(config($provider->credentialConfigKey()));

        session()->flash('status', $fallsBackToEnvironment
            ? $provider->label().' API key removed. The key in '.$provider->environmentVariable()
                .' is now in use again.'
            : $provider->label().' API key removed. No key is configured for that provider.');
    }

    // -----------------------------------------------------------------

    /**
     * Whether spoken conversation is configured, and why not.
     *
     * Reported here because this is the screen where the OpenAI key that
     * enables it is entered: somebody who has just added a key should be able
     * to see, without leaving the page, that voice came on. No credential is
     * read — only whether the provider considers itself usable.
     *
     * @return array{available: bool, reason: ?string, voices: int}
     */
    private function voiceState(): array
    {
        $voice = app(VoiceProviderInterface::class);

        return [
            'available' => $voice->isConfigured(),
            'reason' => $voice->unavailableReason(),
            'voices' => count($voice->voices()),
        ];
    }

    /**
     * The most recent things the assistant did, workspace-wide.
     *
     * The audit ledger's reading surface. Scoped through visibleTo() rather
     * than read raw, even though this screen is administrator-only behind a
     * role gate and a password confirmation: an administrator is not entitled
     * to a colleague's board-less conversation, and the scope is where that
     * rule lives.
     *
     * Twenty rows, newest first. It is a window on the ledger, not a report —
     * the table is indexed for a report, and a filtered one can be built when
     * somebody actually needs it.
     *
     * @return Collection<int, AiToolInvocation>
     */
    private function recentAudit()
    {
        return AiToolInvocation::query()
            ->visibleTo(auth()->user())
            ->with(['user:id,name,email,role,deactivated_at', 'board:id,name,slug'])
            ->recentFirst()
            ->limit(20)
            ->get();
    }

    public function render(AiCredentialVault $vault, AiConfigurationResolver $resolver)
    {
        $this->authorize('administer-ai');

        $settings = AiGlobalSettings::current();

        $credentials = $vault->records()->keyBy(fn ($record): string => $record->provider->value);

        // Presence only, per provider: whether a key is stored here, whether
        // the environment has one, and therefore which would be used. Never a
        // value.
        $keyState = [];

        foreach (AiProvider::cases() as $case) {
            $keyState[$case->value] = [
                'provider' => $case,
                'stored' => $credentials->get($case->value),
                'environment' => filled(config($case->credentialConfigKey())),
                'source' => $vault->sourceFor($case),
                'models' => AiModelCatalogue::forProvider($case),
            ];
        }

        return view('livewire.ai.global-settings', [
            'settings' => $settings,
            'effective' => $resolver->global(),
            'providers' => AiProvider::cases(),
            'modes' => AiCapabilityMode::cases(),
            // The whole catalogue, so the picker can be filtered client-side by
            // the chosen provider without a round trip per change.
            'catalogue' => AiModelCatalogue::all(),
            'modelsForProvider' => AiModelCatalogue::forProvider(
                AiProvider::fromValue($this->provider) ?? AiProvider::default()
            ),
            'keyState' => $keyState,
            'deploymentEnabled' => (bool) config('ai.enabled'),
            'configuredDefaultModel' => (string) config('ai.model.default'),
            'voice' => $this->voiceState(),
            'audit' => $this->recentAudit(),
        ]);
    }
}
