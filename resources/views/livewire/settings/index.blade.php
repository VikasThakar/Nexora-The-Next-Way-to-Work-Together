{{--
    The settings directory.

    Every card below links to a screen that already exists. Nothing on this page
    writes, and no setting is defined here that is not defined somewhere else —
    the point is to make the existing ones findable from one place.
--}}
<div>
    <x-ui.page-header
        title="Settings"
        :description="'Workspace, members and integrations for '.$workspaceName.'.'"
        :trail="\App\Support\Breadcrumbs::settings()"
    />

    @if ($boards->isNotEmpty() && $canSeeInternal)
        {{--
            Most of what reads as a workspace setting in this product is a board
            setting: access is granted per board, and the per-board screens are
            where those options live. This selector chooses which board the
            board-scoped cards below point at.
        --}}
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-56">
                <x-ui.field label="Board" for="settings-board" hint="Board-specific settings below apply to this board.">
                    <x-ui.select id="settings-board" wire:model.live="boardSlug">
                        @foreach ($boards as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }} ({{ $option->ticket_prefix }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">

        {{-- Workspace ---------------------------------------------------- --}}
        <x-ui.card title="Workspace" description="How this deployment identifies itself.">
            <dl class="space-y-3 text-sm">
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-slate-500">Name</dt>
                    <dd class="text-right font-medium text-slate-900">{{ $workspaceName }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-slate-500">Short name</dt>
                    <dd class="text-right font-medium text-slate-900">{{ $workspaceShortName }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-slate-500">Self-service registration</dt>
                    <dd class="text-right">
                        <x-ui.badge :variant="$registrationOpen ? 'emerald' : 'slate'">
                            {{ $registrationOpen ? 'Open' : 'Administrators only' }}
                        </x-ui.badge>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-slate-500">Default timezone</dt>
                    <dd class="text-right font-mono text-xs text-slate-700">{{ $defaultTimezone }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">
                These come from deployment configuration rather than from the database, so they are
                changed by an environment variable and a redeploy, not from this screen.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button :href="route('boards.index')" variant="secondary" size="sm">Boards</x-ui.button>
                @if ($canAdminister)
                    <x-ui.button :href="route('boards.create')" variant="secondary" size="sm">New board</x-ui.button>
                @endif
            </div>
        </x-ui.card>

        {{-- Members ------------------------------------------------------ --}}
        <x-ui.card title="Members" description="Who has an account, and who can see which board.">
            <div class="space-y-4 text-sm text-slate-600">
                @if ($canAdminister)
                    <p>
                        Accounts are created and deactivated in workspace administration. A new account
                        sees nothing until it is added to a board.
                    </p>
                @else
                    <p>Accounts are managed by an administrator.</p>
                @endif

                <p class="text-xs text-slate-500">
                    Board membership is granted per board, never workspace-wide, so it is managed on the
                    board itself.
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($canAdminister)
                    <x-ui.button :href="route('users.index')" variant="secondary" size="sm">Manage users</x-ui.button>
                @endif
                @if ($board && $canManageMembers)
                    <x-ui.button :href="route('boards.show', $board)" variant="secondary" size="sm">
                        {{ $board->name }} members
                    </x-ui.button>
                @endif
            </div>
        </x-ui.card>

        {{-- Integrations ------------------------------------------------- --}}
        @if ($canSeeInternal)
            <x-ui.card
                title="Integrations"
                :description="$board ? 'GitHub, Slack and AI for '.$board->name.'.' : 'GitHub, Slack and AI.'"
            >
                @if (! $board)
                    <x-ui.empty-state
                        title="No board yet"
                        description="Integrations are configured per board. Create or join a board first."
                    />
                @else
                    <ul class="divide-y divide-slate-200 text-sm">
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">GitHub</p>
                                <p class="text-xs text-slate-500">
                                    Branches, commits and pull requests that mention
                                    <span class="font-mono">{{ $board->ticket_prefix }}-123</span> link to that ticket.
                                    Repositories are attached on the AI settings screen.
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.badge :variant="$gitHubConfigured ? 'emerald' : 'amber'">
                                    {{ $gitHubConfigured ? 'Webhook verified' : 'No webhook secret' }}
                                </x-ui.badge>
                                @if ($canManageAi)
                                    <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary" size="sm">Open</x-ui.button>
                                @endif
                            </div>
                        </li>

                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">Slack</p>
                                <p class="text-xs text-slate-500">Post updates from this board into a Slack channel.</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if (! $slackAvailable)
                                    <x-ui.badge variant="amber">Off for this deployment</x-ui.badge>
                                @else
                                    <x-ui.badge :variant="$slack->isActive() ? 'emerald' : 'slate'">
                                        {{ $slack->isActive() ? 'Active' : 'Not set up' }}
                                    </x-ui.badge>
                                @endif
                                @if ($canManageAi)
                                    <x-ui.button :href="route('boards.integrations', $board)" variant="secondary" size="sm">Open</x-ui.button>
                                @endif
                            </div>
                        </li>

                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 last:pb-0">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">AI</p>
                                <p class="text-xs text-slate-500">
                                    What the model may do on this board, the daily cap, and what it costs.
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if (! $aiProviderConfigured)
                                    <x-ui.badge variant="amber">No API key</x-ui.badge>
                                @else
                                    <x-ui.badge :variant="$ai->autoRunEnabled ? 'emerald' : 'slate'">
                                        {{ $ai->autoRunEnabled ? 'Automatic runs on' : 'Manual only' }}
                                    </x-ui.badge>
                                @endif
                                @if ($canManageAi)
                                    <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary" size="sm">Open</x-ui.button>
                                @endif
                            </div>
                        </li>
                    </ul>
                @endif
            </x-ui.card>
        @endif


        {{-- Global AI ---------------------------------------------------- --}}
        @if ($canAdministerAi)
            <x-ui.card
                title="Nexora AI"
                description="The workspace-wide configuration every board inherits: provider, model, API keys, how far the AI is trusted, and what it may spend."
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-slate-500">Provider and model</dt>
                        <dd class="mt-0.5 text-slate-800">
                            {{ $globalAi->provider->label() }} · {{ $globalAi->modelLabel() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">AI mode</dt>
                        <dd class="mt-1">
                            <x-ai.mode-badge :mode="$globalAi->mode" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">API key</dt>
                        <dd class="mt-0.5 text-slate-800">
                            @if ($globalAi->isUsable())
                                <x-ui.badge variant="emerald">Configured</x-ui.badge>
                            @else
                                <x-ui.badge variant="amber">Missing</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-slate-500">
                    Keys are encrypted and never shown again — the screen reports that one exists and
                    its last four characters, and nothing more.
                </p>

                <div class="mt-4">
                    <x-ui.button :href="route('admin.ai')" variant="secondary" size="sm">
                        Open AI settings
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endif
        {{-- Notifications ------------------------------------------------ --}}
        <x-ui.card title="Notifications" description="What reaches you, and where.">
            <div class="space-y-4 text-sm text-slate-600">
                <div>
                    <p class="font-medium text-slate-900">In this workspace</p>
                    <p class="mt-1 text-xs text-slate-500">
                        The bell in the top bar alerts you when a ticket is assigned to you, when someone
                        comments on a ticket you can see, and when you are mentioned by name. It never
                        shows you anything you could not already open.
                    </p>
                </div>

                @if ($canSeeInternal)
                    <div>
                        <p class="font-medium text-slate-900">Slack and SMS</p>
                        <p class="mt-1 text-xs text-slate-500">
                            Outbound alerts are configured per board: Slack for board updates, SMS for
                            critical tickets only.
                            @if ($board && $sms)
                                SMS for {{ $board->name }} is
                                {{ ! $smsAvailable ? 'off for this deployment' : ($sms->isActive() ? 'active' : 'not set up') }}.
                            @endif
                        </p>
                    </div>
                @endif
            </div>

            @if ($board && $canManageAi)
                <div class="mt-4">
                    <x-ui.button :href="route('boards.integrations', $board)" variant="secondary" size="sm">
                        Outbound notifications
                    </x-ui.button>
                </div>
            @endif
        </x-ui.card>

        {{-- Security ------------------------------------------------------ --}}
        <x-ui.card title="Security" description="Your sign-in and this workspace's safeguards.">
            <div class="space-y-4 text-sm text-slate-600">
                <p>
                    Your name, email and password are on your profile. Changing your password signs out
                    your other sessions.
                </p>
                @if ($canAdminister)
                    <p class="text-xs text-slate-500">
                        Workspace administration and board creation ask for your password again before
                        they open, even while you are signed in.
                    </p>
                @endif
            </div>

            <div class="mt-4">
                <x-ui.button :href="route('profile.edit')" variant="secondary" size="sm">Profile &amp; password</x-ui.button>
            </div>
        </x-ui.card>

        {{-- Advanced ----------------------------------------------------- --}}
        @if ($canSeeInternal)
            <x-ui.card
                title="Advanced"
                :description="$board ? 'Structure and lifecycle for '.$board->name.'.' : 'Board structure and lifecycle.'"
            >
                @if (! $board)
                    <x-ui.empty-state
                        title="No board yet"
                        description="These settings belong to a board. Create or join a board first."
                    />
                @else
                    <ul class="divide-y divide-slate-200 text-sm">
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">Columns and labels</p>
                                <p class="text-xs text-slate-500">The workflow this board moves tickets through.</p>
                            </div>
                            @if ($canManageColumns)
                                <x-ui.button :href="route('boards.settings', $board)" variant="secondary" size="sm">Open</x-ui.button>
                            @endif
                        </li>

                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 last:pb-0">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">Board details</p>
                                <p class="text-xs text-slate-500">
                                    Name, slug, ticket prefix, and archiving.
                                </p>
                            </div>
                            @if ($canManageBoard)
                                <x-ui.button :href="route('boards.edit', $board)" variant="secondary" size="sm">Open</x-ui.button>
                            @endif
                        </li>
                    </ul>
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
