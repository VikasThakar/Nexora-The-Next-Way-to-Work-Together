{{--
    Per-board AI configuration.

    Team-only; BoardPolicy::manageAiSettings authorizes on mount, on every action
    and on every render. No credential is accepted or displayed anywhere on this
    page — the deployment status block below reports only whether the secrets are
    present.
--}}
<div>
    <x-ui.page-header
        title="AI settings"
        description="What the model is allowed to do on this board, and what it costs."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('boards.index') }}" wire:navigate class="hover:text-slate-700">Boards</a>
            <span class="mx-1">/</span>
            <a href="{{ route('boards.show', $board) }}" wire:navigate class="hover:text-slate-700">{{ $board->name }}</a>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-ui.button :href="route('boards.ai-chat', $board)" variant="secondary">Workspace AI chat</x-ui.button>
            <x-ui.button :href="route('boards.integrations', $board)" variant="secondary">Integrations</x-ui.button>
            <x-ui.button :href="route('boards.show', $board)" variant="secondary">Back to board</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- ------------------------------------------------------------- --}}
            {{-- Automation                                                      --}}
            {{-- ------------------------------------------------------------- --}}
            <x-ui.card
                title="Automatic runs"
                description="What happens when a customer raises a ticket on this board."
            >
                <form wire:submit="save" class="space-y-5">
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            wire:model="autoRunEnabled"
                            class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Start a run automatically</span>
                            <span class="block text-xs text-slate-500">
                                Only for tickets raised by a customer. Ticket creation never waits for the model:
                                the run is queued and happens on a worker.
                            </span>
                        </span>
                    </label>

                    <x-ui.field
                        label="Mode"
                        for="ai-auto-mode"
                        :error="$errors->first('autoRunMode')"
                    >
                        <x-ui.select id="ai-auto-mode" wire:model="autoRunMode" :invalid="$errors->has('autoRunMode')">
                            @foreach ($modes as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </x-ui.select>

                        <ul class="mt-2 space-y-1 text-xs text-slate-500">
                            @foreach ($modes as $mode)
                                <li><span class="font-medium text-slate-700">{{ $mode->label() }}</span> — {{ $mode->description() }}</li>
                            @endforeach
                        </ul>
                    </x-ui.field>

                    <x-ui.field
                        label="Daily cap for automatic runs"
                        for="ai-cap"
                        :error="$errors->first('dailyAutoRunCap')"
                        :hint="'Nobody bypasses this cap, including administrators — nobody chose these runs. Maximum '.$maxCap.'. Used today: '.$autoUsedToday.'.'"
                        required
                    >
                        <x-ui.input
                            id="ai-cap"
                            type="number"
                            min="0"
                            max="{{ $maxCap }}"
                            wire:model="dailyAutoRunCap"
                            :invalid="$errors->has('dailyAutoRunCap')"
                        />
                    </x-ui.field>

                    <x-ui.field label="Model" for="ai-model" :error="$errors->first('model')" required>
                        <x-ui.select id="ai-model" wire:model="model" :invalid="$errors->has('model')">
                            @foreach ($models as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field
                        label="Project context"
                        for="ai-project-context"
                        :error="$errors->first('projectContext')"
                        hint="Stack, conventions, deployment, anything the model should assume. Added to every prompt for this board."
                    >
                        <x-ui.textarea
                            id="ai-project-context"
                            rows="5"
                            wire:model="projectContext"
                            :invalid="$errors->has('projectContext')"
                        >{{ $projectContext }}</x-ui.textarea>
                    </x-ui.field>

                    <x-ui.field
                        label="Custom instructions"
                        for="ai-system-prompt"
                        :error="$errors->first('customSystemPrompt')"
                        hint="Appended after the workspace's own instructions, never before them — it can add to them, not replace them."
                    >
                        <x-ui.textarea
                            id="ai-system-prompt"
                            rows="5"
                            wire:model="customSystemPrompt"
                            :invalid="$errors->has('customSystemPrompt')"
                        >{{ $customSystemPrompt }}</x-ui.textarea>
                    </x-ui.field>

                    <x-ui.field
                        label="Preferred repository"
                        for="ai-primary-repository"
                        :error="$errors->first('primaryRepository')"
                        hint="Names one of the repositories below. Leave blank to use whichever is flagged primary."
                    >
                        <x-ui.input
                            id="ai-primary-repository"
                            wire:model="primaryRepository"
                            placeholder="owner/name"
                            :invalid="$errors->has('primaryRepository')"
                        />
                    </x-ui.field>

                    <div class="flex items-center gap-3">
                        <x-ui.button type="submit">Save settings</x-ui.button>
                        <span wire:loading wire:target="save" class="text-xs text-slate-500">Saving…</span>
                    </div>
                </form>
            </x-ui.card>

            {{-- ------------------------------------------------------------- --}}
            {{-- Repositories                                                    --}}
            {{-- ------------------------------------------------------------- --}}
            <x-ui.card
                title="Repositories"
                description="Where the model looks, and where apply mode opens pull requests."
            >
                @if ($repositories->isEmpty())
                    <p class="text-sm text-slate-500">
                        No repository is attached. Suggest mode still works — it reasons from the ticket
                        and says so — but apply mode needs one.
                    </p>
                @else
                    <ul class="mb-5 space-y-2">
                        @foreach ($repositories as $repository)
                            <li
                                wire:key="repo-{{ $repository->id }}"
                                class="rounded-lg border border-slate-200 p-3"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm text-slate-800">{{ $repository->repository_name }}</span>

                                    @if ($repository->is_primary)
                                        <x-ui.badge variant="brand">Primary</x-ui.badge>
                                    @endif

                                    <span class="text-xs text-slate-500">branch {{ $repository->default_branch }}</span>

                                    <div class="ml-auto flex items-center gap-1">
                                        @unless ($repository->is_primary)
                                            <x-ui.button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                wire:click="makePrimary({{ $repository->id }})"
                                            >
                                                Make primary
                                            </x-ui.button>
                                        @endunless

                                        <x-ui.button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="startDeletingRepository({{ $repository->id }})"
                                        >
                                            Detach
                                        </x-ui.button>
                                    </div>
                                </div>

                                @if ($repository->description)
                                    <p class="mt-1 text-xs text-slate-500">{{ $repository->description }}</p>
                                @endif

                                @if ($deletingRepository && $deletingRepository->id === $repository->id)
                                    <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3">
                                        <p class="text-xs text-rose-800">
                                            Detach <span class="font-mono">{{ $repository->repository_name }}</span> from this board?
                                            Existing AI run history keeps its record of having used it.
                                        </p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <x-ui.button type="button" variant="danger" size="sm" wire:click="confirmDeleteRepository">
                                                Detach
                                            </x-ui.button>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelDeletingRepository">
                                                Keep it
                                            </x-ui.button>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addRepository" class="space-y-4 border-t border-slate-200 pt-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field
                            label="Repository"
                            for="new-repo-name"
                            :error="$errors->first('newRepositoryName')"
                            hint="owner/name, as GitHub spells it."
                            required
                        >
                            <x-ui.input
                                id="new-repo-name"
                                wire:model="newRepositoryName"
                                placeholder="acme/platform"
                                :invalid="$errors->has('newRepositoryName')"
                            />
                        </x-ui.field>

                        <x-ui.field
                            label="Default branch"
                            for="new-repo-branch"
                            :error="$errors->first('newRepositoryBranch')"
                            hint="Pull requests are opened against this. It is never pushed to directly."
                        >
                            <x-ui.input
                                id="new-repo-branch"
                                wire:model="newRepositoryBranch"
                                :invalid="$errors->has('newRepositoryBranch')"
                            />
                        </x-ui.field>
                    </div>

                    <x-ui.field
                        label="Clone URL"
                        for="new-repo-url"
                        :error="$errors->first('newRepositoryUrl')"
                        hint="Optional. Derived from owner/name for GitHub. HTTPS only — credentials come from the deployment, never from this form."
                    >
                        <x-ui.input
                            id="new-repo-url"
                            wire:model="newRepositoryUrl"
                            placeholder="https://github.com/acme/platform.git"
                            :invalid="$errors->has('newRepositoryUrl')"
                        />
                    </x-ui.field>

                    <x-ui.field
                        label="Description"
                        for="new-repo-description"
                        :error="$errors->first('newRepositoryDescription')"
                        hint="What this repository is, in a sentence. Goes into the prompt."
                    >
                        <x-ui.input
                            id="new-repo-description"
                            wire:model="newRepositoryDescription"
                            :invalid="$errors->has('newRepositoryDescription')"
                        />
                    </x-ui.field>

                    <x-ui.button type="submit" variant="secondary">Attach repository</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------- --}}
        {{-- Sidebar                                                         --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="space-y-6">
            <x-ui.card title="Deployment status" description="Whether the secrets are present. Never their values.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-600">AI provider</dt>
                        <dd>
                            <x-ui.badge :variant="$providerConfigured ? 'emerald' : 'rose'">
                                {{ $providerConfigured ? 'Configured' : 'Not configured' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-600">Repository cloning</dt>
                        <dd>
                            <x-ui.badge :variant="$cloneEnabled ? 'emerald' : 'amber'">
                                {{ $cloneEnabled ? 'Enabled' : 'Off' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-600">GitHub token</dt>
                        <dd>
                            <x-ui.badge :variant="$gitHubConfigured ? 'emerald' : 'amber'">
                                {{ $gitHubConfigured ? 'Present' : 'Missing' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-600">Apply-mode runtime</dt>
                        <dd>
                            <x-ui.badge :variant="$applyDriver === 'unavailable' ? 'amber' : 'emerald'">
                                {{ $applyDriver === 'unavailable' ? 'Not wired up' : $applyDriver }}
                            </x-ui.badge>
                        </dd>
                    </div>
                </dl>

                @if ($applyDriver === 'unavailable')
                    <p class="mt-3 text-xs text-slate-500">
                        Apply mode is built end to end but has no code-generation runtime on this
                        deployment, so an apply run fails immediately with an internal note naming what
                        to configure. Suggest mode is unaffected.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Repository selection" description="Which repository a run would use right now.">
                @if ($selectedRepository)
                    <p class="font-mono text-sm text-slate-800">{{ $selectedRepository->repository_name }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Chosen by: <span class="font-medium">{{ str_replace('_', ' ', $selectionStrategy) }}</span>
                    </p>
                @else
                    <p class="text-sm text-slate-500">
                        No repository could be chosen without guessing. Suggest mode will run and say so;
                        apply mode is refused.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Spend" description="The 25 most recent runs on this board.">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600">Runs</dt>
                        <dd class="font-medium text-slate-900">{{ $costSummary['runs'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600">Known cost</dt>
                        <dd class="font-medium text-slate-900">
                            ${{ number_format($costSummary['known_cost'], 4) }}
                        </dd>
                    </div>
                    @if ($costSummary['unpriced_runs'] > 0)
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-600">Cost not reported</dt>
                            <dd class="font-medium text-amber-700">{{ $costSummary['unpriced_runs'] }} runs</dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600">Tokens</dt>
                        <dd class="font-medium text-slate-900">
                            {{ number_format($costSummary['input_tokens']) }} in /
                            {{ number_format($costSummary['output_tokens']) }} out
                        </dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <dt class="text-slate-600">Manual runs today</dt>
                        <dd class="font-medium text-slate-900">{{ $manualUsedToday }} / {{ $manualLimit }}</dd>
                    </div>
                </dl>

                @if ($costSummary['unpriced_runs'] > 0)
                    <p class="mt-3 text-xs text-slate-500">
                        A run whose model or token usage was not reported stores no cost rather than a
                        guess, so the figure above is a floor, not a total.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Recent runs">
                @if ($recentRuns->isEmpty())
                    <p class="text-sm text-slate-500">No runs yet.</p>
                @else
                    <ul class="space-y-2 text-xs">
                        @foreach ($recentRuns as $run)
                            <li wire:key="board-run-{{ $run->id }}" class="flex items-center gap-2">
                                <x-ui.badge :variant="$run->status->badge()">{{ $run->status->label() }}</x-ui.badge>
                                <span class="text-slate-600">{{ $run->mode->value }}</span>
                                @if ($run->ticket)
                                    <a
                                        href="{{ route('tickets.show', ['board' => $board, 'number' => $run->ticket->number]) }}"
                                        wire:navigate
                                        class="font-mono text-brand-700 hover:underline"
                                    >{{ $board->ticket_prefix }}-{{ $run->ticket->number }}</a>
                                @endif
                                <span class="ml-auto text-slate-400">{{ $run->created_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
