<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Models\Board;
use App\Models\BoardRepository;
use App\Services\AI\AiRunCap;
use App\Services\AI\AiRunReader;
use App\Services\AI\CostCalculationService;
use App\Services\AI\RepositorySelector;
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
            'model' => ['required', Rule::in(array_keys((array) config('ai.model.allowed')))],
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
        ]);

        $settings = $updateSettings->handle($this->board, [
            'auto_run_enabled' => $validated['autoRunEnabled'],
            'auto_run_mode' => $validated['autoRunMode'],
            'model' => $validated['model'],
            'custom_system_prompt' => $validated['customSystemPrompt'],
            'project_context' => $validated['projectContext'],
            'primary_repository' => $validated['primaryRepository'],
            'daily_auto_run_cap' => $validated['dailyAutoRunCap'],
        ]);

        $this->board->refresh();

        // Refilled from what was actually stored rather than from what was
        // submitted, so a clamped cap shows its clamped value immediately.
        $this->fillFrom($settings);

        session()->flash('status', 'AI settings saved.');
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
            'providerConfigured' => BoardAiSettings::providerConfigured(),
            'cloneEnabled' => (bool) config('ai.repository.clone_enabled'),
            'gitHubConfigured' => filled(config('github.token')),
            'applyDriver' => (string) config('ai.code_generation.driver'),
        ])->title('AI settings · '.$this->board->name);
    }
}
