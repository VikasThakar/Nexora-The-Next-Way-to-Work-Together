<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Models\Board;
use App\Models\BoardRepository;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiCredentialVault;
use App\Services\AI\AiRunCap;
use App\Services\AI\AiRunReader;
use App\Services\AI\CostCalculationService;
use App\Services\AI\RepositorySelector;
use App\Support\AiModelCatalogue;
use App\Support\BoardAiSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Per-board AI configuration: automation, model, context, repositories.
 *
 * Separate from the board's own settings screen because it is a different
 * audience and a different risk. Columns and labels are cosmetic; the switch on
 * this page spends money and, in apply mode, opens pull requests. So the page
 * says what each setting costs, shows today's usage against the cap, and defaults
 * everything to off.
 *
 * Authorization is re-run on mount, on every action and on every render, because
 * each Livewire call is its own HTTP request and must not trust what the previous
 * render decided. A customer is denied as 404: the existence of the screen is
 * itself internal.
 *
 * No credential is ever accepted or displayed here. The provider key and the
 * GitHub token are deployment secrets in the environment; this screen reports
 * whether they are *present*, and nothing more. A settings form that could accept
 * a token would be a settings form that could leak one back out.
 */
#[Layout('layouts.app')]
class AiSettings extends Component
{
    public Board $board;

    // Automation
    public bool $autoRunEnabled = false;

    public string $autoRunMode = '';

    public string $model = '';

    public string $customSystemPrompt = '';

    public string $projectContext = '';

    public string $primaryRepository = '';

    public int $dailyAutoRunCap = 20;

    /*
     * Overrides of the workspace's AI settings.
     *
     * All three are strings whose empty value means "inherit", which is the
     * default and the common case: a board departs from the global
     * configuration deliberately or not at all. An enum-typed property could
     * not express the empty state, and a nullable one would make every Blade
     * comparison a null check.
     */
    public string $providerOverride = '';

    public string $capabilityModeOverride = '';

    public string $sessionTokenLimitOverride = '';

    /**
     * This board's own API key. Blank on every load, write-only.
     *
     * Same treatment as the Slack webhook field on the integrations screen and
     * the key fields on the global screen: there is no path from a stored key
     * back to a browser. Most boards will never use this — a board that only
     * wants a different model inherits the workspace key — so it lives behind
     * its own section rather than in the main form.
     */
    public string $newBoardKey = '';

    // Repository form
    public string $newRepositoryName = '';

    public string $newRepositoryUrl = '';

    public string $newRepositoryBranch = 'main';

    public string $newRepositoryDescription = '';

    public ?int $deletingRepositoryId = null;

    public function mount(Board $board): void
    {
        $this->authorize('manageAiSettings', $board);

        $this->board = $board;

        $this->fillFrom($board->aiSettings());
    }

    private function fillFrom(BoardAiSettings $settings): void
    {
        $this->autoRunEnabled = $settings->autoRunEnabled;
        $this->autoRunMode = $settings->autoRunMode->value;
        $this->model = $settings->model;
        $this->customSystemPrompt = (string) $settings->customSystemPrompt;
        $this->projectContext = (string) $settings->projectContext;
        $this->primaryRepository = (string) $settings->primaryRepository;
        $this->dailyAutoRunCap = $settings->dailyAutoRunCap;
        $this->providerOverride = (string) $settings->providerOverride?->value;
        $this->capabilityModeOverride = (string) $settings->capabilityMode?->value;
        $this->sessionTokenLimitOverride = $settings->sessionTokenLimit === null
            ? ''
            : (string) $settings->sessionTokenLimit;
        $this->newBoardKey = '';
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function save(UpdateBoardAiSettings $updateSettings): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $validated = $this->validate([
            'autoRunEnabled' => ['boolean'],
            // Only a mode this application knows, and only a model this
            // deployment allows: a crafted request must not be able to point a
            // board at an arbitrary model string.
            'autoRunMode' => ['required', Rule::in(AiRunMode::values())],
            /*
             * Empty means "inherit the workspace default", which is the state
             * most boards are in and the reason this is no longer required.
             * The empty string is allowed explicitly rather than through
             * `nullable`, because Livewire submits an unselected <select> as ''
             * and `nullable` only excuses a genuine null.
             */
            'model' => ['nullable', Rule::in([...AiModelCatalogue::ids(), ''])],
            'providerOverride' => ['nullable', Rule::in([...AiProvider::values(), ''])],
            'capabilityModeOverride' => ['nullable', Rule::in([...AiCapabilityMode::values(), ''])],
            'sessionTokenLimitOverride' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'customSystemPrompt' => ['nullable', 'string', 'max:8000'],
            'projectContext' => ['nullable', 'string', 'max:8000'],
            'primaryRepository' => ['nullable', 'string', 'max:255'],
            'dailyAutoRunCap' => [
                'required', 'integer', 'min:0',
                'max:'.(int) config('ai.caps.max_daily_auto_runs', 500),
            ],
        ], attributes: [
            'autoRunEnabled' => 'automatic runs',
            'autoRunMode' => 'automatic run mode',
            'dailyAutoRunCap' => 'daily cap',
            'customSystemPrompt' => 'custom instructions',
            'projectContext' => 'project context',
            'primaryRepository' => 'primary repository',
            'providerOverride' => 'provider',
            'capabilityModeOverride' => 'AI mode',
            'sessionTokenLimitOverride' => 'session token limit',
        ]);

        $settings = $updateSettings->handle($this->board, [
            'auto_run_enabled' => $validated['autoRunEnabled'],
            'auto_run_mode' => $validated['autoRunMode'],
            'model' => $validated['model'],
            'custom_system_prompt' => $validated['customSystemPrompt'],
            'project_context' => $validated['projectContext'],
            'primary_repository' => $validated['primaryRepository'],
            'daily_auto_run_cap' => $validated['dailyAutoRunCap'],
            // Blank strings are stored as null by BoardAiSettings, which is
            // what "inherit" is. Nothing here needs to translate them.
            'provider_override' => $validated['providerOverride'],
            'capability_mode' => $validated['capabilityModeOverride'],
            'session_token_limit' => $validated['sessionTokenLimitOverride'],
        ]);

        $this->board->refresh();

        // Refilled from what was actually stored rather than from what was
        // submitted, so a clamped cap shows its clamped value immediately.
        $this->fillFrom($settings);

        session()->flash('status', 'AI settings saved.');
    }

    // -----------------------------------------------------------------
    // This board's own API key
    // -----------------------------------------------------------------

    /**
     * Give this board its own provider key.
     *
     * The exception rather than the norm, and the screen says so: a board that
     * only wants a different model inherits the workspace key and no secret is
     * duplicated. This exists for the project billed to its own account.
     *
     * Write-only, exactly as the global screen and the Slack webhook field are.
     * The plaintext exists in one public property for the length of this
     * request and is cleared before the response renders, so it is never sent
     * back in a component snapshot.
     */
    public function storeBoardKey(AiCredentialVault $vault, AiConfigurationResolver $resolver): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $provider = AiProvider::fromValue($this->effectiveProviderValue());

        if (! $provider instanceof AiProvider) {
            return;
        }

        $key = trim($this->newBoardKey);

        if ($key === '') {
            $this->addError('newBoardKey', 'Paste the API key you want this board to use.');

            return;
        }

        if (! AiCredentialVault::looksLikeKey($provider, $key)) {
            $this->addError('newBoardKey', 'That does not look like a '.$provider->label()
                .' API key. They begin with '.$provider->keyPrefixHint().'.');

            // Cleared on the error path too: a rejected key must not travel
            // back to the browser in the next snapshot.
            $this->newBoardKey = '';

            return;
        }

        $vault->storeForBoard($this->board, $provider, $key);

        $this->newBoardKey = '';

        $this->board->refresh();
        $resolver->flush();
        $this->fillFrom($this->board->aiSettings());

        session()->flash('status', 'This board now uses its own '.$provider->label()
            .' API key. It is encrypted and cannot be read back.');
    }

    /**
     * Take this board's own key away, so it inherits the workspace's again.
     */
    public function removeBoardKey(string $providerValue, AiCredentialVault $vault, AiConfigurationResolver $resolver): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $provider = AiProvider::fromValue($providerValue);

        if (! $provider instanceof AiProvider) {
            return;
        }

        $vault->removeForBoard($this->board, $provider);

        $this->board->refresh();
        $resolver->flush();
        $this->fillFrom($this->board->aiSettings());

        session()->flash('status', 'Removed. This board now uses the workspace '
            .$provider->label().' key again.');
    }

    /**
     * Which provider this board's key would be for.
     *
     * The board's override when it has one, otherwise the workspace's — so the
     * field is always about the vendor that will actually answer, rather than
     * offering a second dropdown that could disagree with the one above it.
     */
    private function effectiveProviderValue(): string
    {
        return $this->providerOverride !== ''
            ? $this->providerOverride
            : app(AiConfigurationResolver::class)->global()->provider->value;
    }

    // -----------------------------------------------------------------
    // Repositories
    // -----------------------------------------------------------------

    public function addRepository(ManageBoardRepositories $repositories): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $validated = $this->validate([
            'newRepositoryName' => ['required', 'string', 'max:255'],
            'newRepositoryUrl' => ['nullable', 'url:http,https', 'max:500'],
            'newRepositoryBranch' => ['nullable', 'string', 'max:100'],
            'newRepositoryDescription' => ['nullable', 'string', 'max:1000'],
        ], attributes: [
            'newRepositoryName' => 'repository',
            'newRepositoryUrl' => 'repository URL',
            'newRepositoryBranch' => 'default branch',
            'newRepositoryDescription' => 'description',
        ]);

        try {
            $repositories->create($this->board, [
                'repository_name' => $validated['newRepositoryName'],
                'repository_url' => $validated['newRepositoryUrl'],
                'default_branch' => $validated['newRepositoryBranch'],
                'description' => $validated['newRepositoryDescription'],
            ]);
        } catch (RuntimeException $exception) {
            $this->addError('newRepositoryName', $exception->getMessage());

            return;
        }

        $this->reset(['newRepositoryName', 'newRepositoryUrl', 'newRepositoryDescription']);
        $this->newRepositoryBranch = 'main';

        session()->flash('status', 'Repository attached.');
    }

    public function makePrimary(int $repositoryId, ManageBoardRepositories $repositories): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $repositories->makePrimary($this->repository($repositoryId));

        session()->flash('status', 'Primary repository updated.');
    }

    public function startDeletingRepository(int $repositoryId): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $this->deletingRepositoryId = $this->repository($repositoryId)->getKey();
    }

    public function cancelDeletingRepository(): void
    {
        $this->deletingRepositoryId = null;
    }

    public function confirmDeleteRepository(ManageBoardRepositories $repositories): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $repositories->delete($this->repository((int) $this->deletingRepositoryId));

        $this->deletingRepositoryId = null;

        session()->flash('status', 'Repository detached. No AI run history was removed.');
    }

    /**
     * Resolve a repository id from the browser within this board.
     *
     * Scoped to $this->board, which is what stops a swapped id reaching a
     * repository on somebody else's board: it 404s instead of resolving.
     */
    private function repository(int $repositoryId): BoardRepository
    {
        $repository = $this->board->repositories()->whereKey($repositoryId)->first();

        abort_unless($repository instanceof BoardRepository, 404);

        return $repository;
    }

    // -----------------------------------------------------------------

    public function render(
        AiRunCap $cap,
        AiRunReader $runs,
        CostCalculationService $costs,
        RepositorySelector $selector,
    ) {
        $this->authorize('manageAiSettings', $this->board);

        $user = auth()->user();
        $recent = $runs->forBoard($this->board, $user, 25);
        $repositories = $this->board->repositories()->ordered()->get();
        $selection = $selector->select($this->board);

        $effective = app(AiConfigurationResolver::class)->forBoard($this->board);
        $global = app(AiConfigurationResolver::class)->global();
        $vault = app(AiCredentialVault::class);
        $settings = $this->board->aiSettings();

        return view('livewire.boards.ai-settings', [
            'modes' => AiRunMode::cases(),
            'models' => (array) config('ai.model.allowed'),
            'maxCap' => (int) config('ai.caps.max_daily_auto_runs', 500),
            'repositories' => $repositories,
            'deletingRepository' => $this->deletingRepositoryId === null
                ? null
                : $repositories->firstWhere('id', $this->deletingRepositoryId),
            'selectedRepository' => $selection['repository'],
            'selectionStrategy' => $selection['strategy'],
            'autoUsedToday' => $cap->usedToday($this->board, AiRunTrigger::Automatic),
            'manualUsedToday' => $cap->usedToday($this->board, AiRunTrigger::Manual),
            'manualLimit' => $cap->manualLimit(),
            'recentRuns' => $recent,
            'costSummary' => $costs->summarise($recent),
            'providerConfigured' => $effective->isUsable(),
            'cloneEnabled' => (bool) config('ai.repository.clone_enabled'),
            'gitHubConfigured' => filled(config('github.token')),
            'applyDriver' => (string) config('ai.code_generation.driver'),

            /*
             * The inheritance chain, as three things the screen can show side
             * by side: what the workspace decided, what this board departs
             * from, and what therefore applies. A form that showed only the
             * middle one could not answer "so what happens on this board?",
             * which is the question somebody opening it actually has.
             */
            'effective' => $effective,
            'global' => $global,
            'settings' => $settings,
            'providers' => AiProvider::cases(),
            'capabilityModes' => AiCapabilityMode::cases(),
            'catalogue' => AiModelCatalogue::all(),

            // Presence only, never a value: whether this board holds its own
            // key for the provider that will answer, and where the key in use
            // comes from.
            'boardKeyProvider' => $effective->provider,
            'boardHasOwnKey' => $settings->hasCredentialFor($effective->provider),
            'boardKeyLastFour' => $settings->credentialLastFour($effective->provider),
            'credentialSource' => $vault->sourceFor($effective->provider, $this->board),
        ])->title('AI settings · '.$this->board->name);
    }
}
