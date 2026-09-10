{{--
    Global AI settings. Administrators only.

    Four cards, ordered by how permanent the decision is: the provider and
    model (changed when the workspace changes vendor), the keys (changed when
    one is rotated), the security mode (changed deliberately, rarely, and read
    often), and the limits.

    The mode card is given the most room on purpose. It is the one setting on
    this screen that changes what the AI is *allowed* to do rather than how well
    it does it, so each level states its own capabilities in full rather than
    hiding them behind a tooltip — somebody choosing between them should not
    have to guess what "Operator" covers.

    No key value is rendered anywhere on this page. What is shown is whether one
    is stored, its last four characters, and which source is in use. See
    App\Livewire\Ai\GlobalSettings for why that is structural rather than a
    convention.
--}}
<div class="mx-auto max-w-4xl">
    <x-ui.page-header
        title="AI settings"
        description="How Nexora AI behaves everywhere: which provider answers, which model, how far it is trusted, and what it may spend. Boards inherit this unless they override it."
        :trail="\App\Support\Breadcrumbs::settings('AI')"
    >
        <x-slot:actions>
            <x-ai.mode-badge :mode="$effective->mode" />
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @unless ($deploymentEnabled)
        {{-- Amber: something needs attention. Nothing on this page can fix it,
             which is exactly why it is called out rather than left to be
             discovered when the first question returns nothing. --}}
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">AI is switched off for this deployment.</p>
            <p class="mt-0.5 text-amber-700">
                <code class="font-mono">AI_ENABLED</code> is false in the environment, so nothing on
                this page will take effect until it is set on the web and worker services. The
                deployment switch and the workspace switch below both have to agree.
            </p>
        </div>
    @endunless

    <div class="space-y-6">
        {{-- ----------------------------------------------------------- --}}
        {{-- Provider and model                                          --}}
        {{-- ----------------------------------------------------------- --}}
        <form wire:submit="save">
            <x-ui.card
                title="Provider and model"
                description="The workspace default. A board can point at a different model, or a different provider, without a second API key."
            >
                <div class="space-y-5">
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            wire:model="enabled"
                            class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <span class="text-sm">
                            <span class="font-medium text-slate-800">Nexora AI is available in this workspace</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                Switching this off stops every assistant answer and every ticket run at
                                once, without touching a board's own settings or losing them.
                            </span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="AI provider" for="ai-provider" :error="$errors->first('provider')">
                            <x-ui.select id="ai-provider" wire:model.live="provider" :invalid="$errors->has('provider')">
                                @foreach ($providers as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field
                            label="Default model"
                            for="ai-model"
                            :error="$errors->first('model')"
                            :hint="$effective->isInherited('model') ? 'Currently inherited: '.$effective->modelLabel() : null"
                        >
                            <x-ui.select id="ai-model" wire:model="model" :invalid="$errors->has('model')">
                                <option value="">
                                    Deployment default
                                    @if (\App\Support\AiModelCatalogue::find($configuredDefaultModel))
                                        ({{ \App\Support\AiModelCatalogue::find($configuredDefaultModel)->label }})
                                    @endif
                                </option>

                                @foreach ($modelsForProvider as $option)
                                    <option value="{{ $option->id }}">
                                        {{ $option->label }} · {{ $option->descriptor() }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    @if (empty($modelsForProvider))
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            No models are listed for
                            {{ (\App\Enums\AiProvider::fromValue($provider) ?? \App\Enums\AiProvider::default())->label() }}
                            in this deployment's catalogue, so it cannot answer anything yet. Add the model
                            ids your account can reach — <code class="font-mono">AI_OPENAI_MODELS</code> for
                            OpenAI — and they will appear here.
                        </p>
                    @endif

                    {{-- The effective configuration, spelled out. The chain has
                         three levels and a screen that showed only what was
                         chosen here would not answer "so what actually
                         happens?" --}}
                    <dl class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs sm:grid-cols-3">
                        <div>
                            <dt class="text-slate-500">In effect now</dt>
                            <dd class="mt-0.5 font-medium text-slate-800">{{ $effective->modelLabel() }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Provider</dt>
                            <dd class="mt-0.5 font-medium text-slate-800">{{ $effective->provider->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Usable</dt>
                            <dd class="mt-0.5 font-medium {{ $effective->isUsable() ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $effective->isUsable() ? 'Yes' : 'No — no key or no model' }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save">
                        Save settings
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.card>
        </form>

        {{-- ----------------------------------------------------------- --}}
        {{-- API keys                                                    --}}
        {{-- ----------------------------------------------------------- --}}
        <x-ui.card
            title="API keys"
            description="Encrypted at rest with the application key, and never shown again. Add one, replace it, or remove it."
        >
            <div class="space-y-4">
                <div class="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200">
                    @foreach ($keyState as $state)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="key-{{ $state['provider']->value }}">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800">
                                    {{ $state['provider']->label() }}

                                    @if ($state['source'] === 'global')
                                        <x-ui.badge variant="emerald">Configured</x-ui.badge>
                                    @elseif ($state['source'] === 'config')
                                        <x-ui.badge variant="slate">From environment</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="slate">Not configured</x-ui.badge>
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-slate-500">
                                    @if ($state['stored'])
                                        <span class="font-mono text-slate-700">{{ $state['stored']->maskedSecret() }}</span>
                                        @if ($state['stored']->hint)
                                            · {{ $state['stored']->hint }}
                                        @endif
                                        @if ($state['stored']->rotated_at)
                                            · set {{ $state['stored']->rotated_at->diffForHumans() }}
                                        @endif
                                        @if ($state['stored']->updatedBy)
                                            by {{ $state['stored']->updatedBy->name }}
                                        @endif
                                    @elseif ($state['environment'])
                                        Read from <code class="font-mono">{{ $state['provider']->environmentVariable() }}</code>.
                                        A key stored here would take precedence.
                                    @else
                                        No key stored, and <code class="font-mono">{{ $state['provider']->environmentVariable() }}</code>
                                        is not set.
                                    @endif
                                </p>
                            </div>

                            @if ($state['stored'])
                                <x-ui.button
                                    type="button"
                                    size="sm"
                                    variant="danger"
                                    wire:click="removeKey('{{ $state['provider']->value }}')"
                                    :confirm="[
                                        'title' => 'Remove the '.$state['provider']->label().' API key?',
                                        'body' => $state['environment']
                                            ? 'The key in '.$state['provider']->environmentVariable().' will be used instead.'
                                            : 'AI features using this provider stop working until a new key is added.',
                                        'confirmText' => 'Remove key',
                                        'tone' => 'danger',
                                    ]"
                                >
                                    Remove
                                </x-ui.button>
                            @endif
                        </div>
                    @endforeach
                </div>

                <form wire:submit="storeKey" class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
                    <p class="text-sm font-medium text-slate-800">Add or replace a key</p>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.field label="Provider" for="key-provider" :error="$errors->first('keyProvider')">
                            <x-ui.select id="key-provider" wire:model="keyProvider">
                                @foreach ($providers as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field
                            label="API key"
                            for="new-key"
                            class="sm:col-span-2"
                            :error="$errors->first('newKey')"
                            hint="Stored encrypted. This field is write-only — it is blank on every load and cannot show a stored key."
                        >
                            {{--
                                type=password so it is not shoulder-read and not
                                offered to a browser's autofill store.
                                autocomplete=off for the same reason: a
                                credential belongs in the vault, not in a
                                password manager entry for this page.
                            --}}
                            <x-ui.input
                                id="new-key"
                                type="password"
                                wire:model="newKey"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="sk-…"
                                :invalid="$errors->has('newKey')"
                            />
                        </x-ui.field>
                    </div>

                    <x-ui.field
                        label="Label (optional)"
                        for="key-hint"
                        :error="$errors->first('keyHint')"
                        hint="For your own reference — “Agency account”, “Production”. Never put the key here."
                    >
                        <x-ui.input id="key-hint" wire:model="keyHint" maxlength="60" :invalid="$errors->has('keyHint')" />
                    </x-ui.field>

                    <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="storeKey">
                        Store key
                    </x-ui.button>
                </form>
            </div>
        </x-ui.card>

        {{-- ----------------------------------------------------------- --}}
        {{-- Security mode                                               --}}
        {{-- ----------------------------------------------------------- --}}
        <form wire:submit="save">
            <x-ui.card
                title="AI mode"
                description="How much the AI is trusted. Enforced on the server for every tool and every run — not by hiding buttons."
            >
                <div class="space-y-3">
                    @foreach ($modes as $option)
                        <label
                            wire:key="mode-{{ $option->value }}"
                            @class([
                                'flex cursor-pointer gap-3 rounded-lg border px-4 py-3 transition',
                                'border-brand-300 bg-brand-50/50 ring-1 ring-brand-200' => $capabilityMode === $option->value,
                                'border-slate-200 hover:bg-slate-50' => $capabilityMode !== $option->value,
                            ])
                        >
                            <input
                                type="radio"
                                name="capabilityMode"
                                value="{{ $option->value }}"
                                wire:model="capabilityMode"
                                class="mt-1 size-4 border-slate-300 text-brand-600 focus:ring-brand-500"
                            >

                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-900">{{ $option->label() }}</span>
                                    <x-ui.badge :variant="$option->badgeVariant()">{{ $option->summary() }}</x-ui.badge>
                                </span>

                                <span class="mt-1 block text-xs text-slate-600">{{ $option->description() }}</span>
                            </span>
                        </label>
                    @endforeach

                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        <span class="font-medium text-slate-800">No mode is a bypass.</span>
                        Every action the AI takes still passes authentication, the board's own
                        permissions and the customer visibility rules, and every write is authorized
                        against the person who confirms it. AI Agent widens what the AI may
                        <em>attempt</em>; it never widens what anybody may do.
                    </p>
                </div>

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save">
                        Save mode
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.card>
        </form>

        {{-- ----------------------------------------------------------- --}}
        {{-- Limits                                                      --}}
        {{-- ----------------------------------------------------------- --}}
        <form wire:submit="save">
            <x-ui.card
                title="Session and token limits"
                description="Context management first, cost control second. A conversation that has grown too large gives worse answers as well as dearer ones."
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field
                        label="Tokens per session"
                        for="session-limit"
                        :error="$errors->first('sessionTokenLimit')"
                        hint="Leave blank to inherit the deployment default. 0 means no limit. When a conversation reaches it, the assistant asks for a new session."
                    >
                        <x-ui.input
                            id="session-limit"
                            type="number"
                            min="0"
                            wire:model="sessionTokenLimit"
                            :placeholder="number_format((int) config('ai.limits.session_tokens'))"
                            :invalid="$errors->has('sessionTokenLimit')"
                        />
                    </x-ui.field>

                    <x-ui.field
                        label="Tokens per person per day"
                        for="daily-limit"
                        :error="$errors->first('dailyUserTokenLimit')"
                        hint="Across every session and every ticket run they trigger. Blank inherits the deployment default; 0 means no limit. Not overridable per board."
                    >
                        <x-ui.input
                            id="daily-limit"
                            type="number"
                            min="0"
                            wire:model="dailyUserTokenLimit"
                            :placeholder="number_format((int) config('ai.limits.daily_user_tokens'))"
                            :invalid="$errors->has('dailyUserTokenLimit')"
                        />
                    </x-ui.field>
                </div>

                <p class="mt-4 text-xs text-slate-500">
                    Automatic and manual ticket runs are additionally capped per board, per day, by
                    the board's own AI settings — those caps are about how often runs happen rather
                    than how much a conversation may hold.
                </p>

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save">
                        Save limits
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.card>
        </form>

        {{-- ------------------------------------------------------------- --}}
        {{-- Spoken conversation                                             --}}
        {{-- ------------------------------------------------------------- --}}
        <x-ui.card
            title="Spoken conversation"
            description="Voice uses the OpenAI key above. There is nothing else to configure."
        >
            @if ($voice['available'])
                <p class="text-xs text-slate-600">
                    Voice is available. People can hold a spoken conversation with the assistant
                    from the panel, and answers are read back.
                    @if ($voice['voices'] > 1)
                        {{ $voice['voices'] }} voices are offered.
                    @endif
                </p>
            @else
                {{-- The provider writes this sentence and it names the remedy,
                     so it is shown verbatim rather than summarised. --}}
                <p class="text-xs text-slate-600">{{ $voice['reason'] }}</p>
            @endif

            <p class="mt-2 text-[11px] text-slate-500">
                A spoken question is transcribed, answered by the ordinary assistant and read
                back. It therefore inherits every rule a typed question does — the session, the
                capability mode, the customer boundary and this audit trail — and recordings are
                never stored.
            </p>
        </x-ui.card>

        {{-- ------------------------------------------------------------- --}}
        {{-- The audit trail                                                 --}}
        {{-- ------------------------------------------------------------- --}}
        <x-ui.card
            title="What the AI has been doing"
            description="Every lookup and every confirmed change, newest first."
        >
            @if ($audit->isEmpty())
                <p class="text-xs text-slate-500">
                    Nothing recorded yet. Rows appear here as soon as somebody asks the assistant
                    a question it has to look something up to answer.
                </p>
            @else
                <div class="-mx-4 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="border-b border-slate-200 text-[11px] text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-1.5 font-medium">When</th>
                                <th scope="col" class="px-4 py-1.5 font-medium">Person</th>
                                <th scope="col" class="px-4 py-1.5 font-medium">Tool</th>
                                <th scope="col" class="px-4 py-1.5 font-medium">Subject</th>
                                <th scope="col" class="px-4 py-1.5 font-medium">Result</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            @foreach ($audit as $row)
                                <tr wire:key="audit-{{ $row->id }}">
                                    <td class="px-4 py-1.5 whitespace-nowrap text-slate-500">
                                        {{ $row->created_at?->diffForHumans() }}
                                    </td>
                                    <td class="px-4 py-1.5 whitespace-nowrap text-slate-700">
                                        {{ $row->user?->name ?? 'Somebody since removed' }}
                                    </td>
                                    <td class="px-4 py-1.5 whitespace-nowrap font-mono text-slate-700">
                                        {{ $row->tool }}
                                    </td>
                                    <td class="px-4 py-1.5 text-slate-600">
                                        {{ $row->target ?? '—' }}
                                        @if ($row->board)
                                            <span class="text-slate-400">· {{ $row->board->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-1.5 whitespace-nowrap">
                                        <x-ui.badge :variant="$row->badgeVariant()">
                                            {{ $row->categoryLabel() }} · {{ $row->outcomeLabel() }}
                                        </x-ui.badge>
                                    </td>
                                </tr>

                                @if ($row->message)
                                    {{-- Why it was refused, or what failed. Never a
                                         stack trace and never a credential — see
                                         App\Services\AI\Audit\AiAuditLogger. --}}
                                    <tr wire:key="audit-note-{{ $row->id }}">
                                        <td colspan="5" class="px-4 pb-1.5 text-[11px] text-slate-500">
                                            {{ \Illuminate\Support\Str::limit($row->message, 200) }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-2 text-[11px] text-slate-500">
                    What a lookup <em>returned</em> is deliberately not recorded: a result is a copy
                    of workspace content, and a second copy would be a second place for it to leak
                    from. The subject and the size are kept instead.
                </p>
            @endif
        </x-ui.card>

        @if ($settings->updatedBy)
            <p class="text-xs text-slate-400">
                Last changed by {{ $settings->updatedBy->name }}
                {{ $settings->updated_at?->diffForHumans() }}.
            </p>
        @endif
    </div>
</div>
