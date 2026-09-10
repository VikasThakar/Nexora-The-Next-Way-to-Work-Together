{{--
    The global assistant panel.

    A non-modal drawer, deliberately: the whole point is to keep reading the
    board while asking about it, so the page behind stays scrollable and
    clickable on desktop. Below `lg` it becomes a full-height sheet with a
    backdrop, which is the right shape on a phone.

    Open state lives in the Alpine store from resources/js/ai-panel.js rather
    than in this component, because the trigger is in the top bar and shares no
    scope with the panel — the same problem resources/js/dialog.js solves for
    confirmations. Keeping it client-side also means opening the drawer is
    instant rather than a server round trip.

    One band sits between the header and the transcript: the context. It used to
    be three — the session bar, the model picker and the usage panel sat under
    it — and they were removed together when the mode picker replaced them. Two
    of the three were answering the same question in three ways ("what will this
    cost, and on which model?"), and the answer people actually want to give is
    what the assistant is *for*. That choice now lives beside Send, where it is
    made, and the model follows from it. See App\Enums\AiChatMode.

    Nothing below the header is drawn until the panel has actually been opened:
    before that there is no session, because creating one to render a closed
    panel would put a row in the database on every page load in the application.
--}}
<div
    x-data="aiPanel"
    x-cloak
    class="contents"
>
    {{-- Backdrop, mobile only. On desktop the page behind stays live. --}}
    <div
        x-show="$store.aiPanel.open"
        x-transition.opacity
        class="fixed inset-0 z-40 bg-scrim/50 lg:hidden"
        @click="$store.aiPanel.close()"
        aria-hidden="true"
    ></div>

    <aside
        x-show="$store.aiPanel.open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        role="complementary"
        aria-label="Workspace AI"
        class="fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l border-slate-200 bg-surface-raised shadow-xl sm:max-w-md lg:max-w-[28rem]"
    >
        {{-- Header ------------------------------------------------------ --}}
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    <svg class="size-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                    Workspace AI
                    {{-- Only for staff. A customer's assistant is not
                         internal — it is theirs, and labelling it
                         "Internal" would be both untrue and a small lesson
                         in a distinction the product does not otherwise
                         show them. --}}
                    @unless ($customer)
                        <x-ui.internal-badge />
                    @endunless
                </h2>

                @if ($eligible)
                    {{-- What the AI may do here, at a glance. It is the first
                         thing to check when the assistant declines to draft
                         something, so it sits in the header rather than in the
                         settings screen it comes from. --}}
                    <div class="mt-1.5">
                        <x-ai.mode-badge
                            :mode="$configuration->mode"
                            :inherited="$configuration->isInherited('mode')"
                        />
                    </div>
                @endif
            </div>

            <button
                type="button"
                @click="$store.aiPanel.close()"
                class="-m-1 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                aria-label="Close Workspace AI"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        @if (! $eligible)
            <div class="flex-1 px-4 py-6">
                <p class="text-sm text-slate-500">
                    The assistant is available to the delivery team on boards you are a member of.
                </p>
            </div>
        @else
            {{-- Context selector ---------------------------------------- --}}
            <div class="shrink-0 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <label for="ai-scope" class="block text-xs font-medium text-slate-600">Context</label>

                {{--
                    wire:change rather than wire:model: the value is checked by
                    an action before it is assigned, so a tampered <option>
                    cannot select a board this person is not on. See
                    Assistant::selectScope().
                --}}
                <x-ui.select
                    id="ai-scope"
                    class="mt-1"
                    wire:change="selectScope($event.target.value)"
                    :disabled="$sending"
                >
                    <option
                        value="{{ \App\Services\AI\AiContextScope::MODE_WORKSPACE }}"
                        @selected($scope->isWorkspace())
                    >All workspace</option>

                    @foreach ($boards as $option)
                        <option value="{{ $option->slug }}" @selected($scope->board?->slug === $option->slug)>
                            {{ $option->name }} ({{ $option->ticket_prefix }})
                        </option>
                    @endforeach
                </x-ui.select>

                {{--
                    Offered, never performed. Navigating to another board while
                    mid-conversation must not silently change the subject.
                --}}
                @if ($pageBoard && $scope->board?->slug !== $pageBoard)
                    @php $viewing = $boards->firstWhere('slug', $pageBoard); @endphp

                    @if ($viewing)
                        <button
                            type="button"
                            wire:click="selectScope('{{ $viewing->slug }}')"
                            class="mt-2 text-xs text-brand-700 underline decoration-dotted hover:text-brand-900"
                        >
                            You are viewing {{ $viewing->name }} — ask about it instead
                        </button>
                    @endif
                @endif

                <p class="mt-2 text-xs text-slate-500">
                    @if ($scope->isWorkspace())
                        A summary of every board you can see. Pick a board for detail.
                    @else
                        Tickets, documentation and repositories on {{ $scope->label() }}.
                    @endif
                </p>
            </div>

            {{-- Conversation --------------------------------------------- --}}
            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                <x-ui.internal-notice class="mb-4" title="Your conversation.">
                    Only you can read it. The assistant sees only what
                    <strong>you</strong> are allowed to see, and changes nothing without your
                    confirmation.
                </x-ui.internal-notice>

                @if ($aiError)
                    <div
                        role="alert"
                        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800"
                    >
                        {{ $aiError }}

                        {{-- The remedy, where the problem is. A conversation
                             that has spent its allowance is answered by a new
                             one, and there is no session bar to start one
                             from any more. --}}
                        @if ($sessionExhausted)
                            <button
                                type="button"
                                wire:click="startNewSession"
                                class="mt-1.5 block font-medium text-rose-900 underline decoration-dotted"
                            >
                                Start a new conversation
                            </button>
                        @endif
                    </div>
                @endif

                @if ($messages->isEmpty())
                    <p class="py-6 text-center text-sm text-slate-400">
                        @if ($configuration->mode->canProposeWrites())
                            Ask how the work is progressing, what changed this week, or what the
                            documentation says.
                        @else
                            Ask how the work is progressing, what changed this week, or what the
                            documentation says. In {{ $configuration->mode->label() }} the assistant
                            answers but cannot draft changes.
                        @endif
                    </p>
                @endif

                <div class="space-y-3">
                    @foreach ($messages as $message)
                        <div wire:key="panel-{{ $message->id }}" @class([
                            'rounded-lg border p-2.5',
                            'border-slate-200 bg-surface' => ! $message->role->isAssistant(),
                            // The assistant's own turn arrives with the
                            // product's one animation: a short rise, once,
                            // when the element is new. See app.css.
                            'nx-reveal border-brand-200 bg-brand-50/40' => $message->role->isAssistant(),
                        ])>
                            <div class="mb-1.5 flex items-center gap-2 text-xs">
                                <span class="font-semibold text-slate-800">
                                    {{ $message->role->isAssistant() ? 'Workspace AI' : 'You' }}
                                </span>
                                <span class="text-slate-400">{{ $message->created_at?->diffForHumans() }}</span>
                            </div>

                            {{-- Safe unescaped: ContentRenderer output has had raw
                                 HTML stripped by App\Support\Markdown. --}}
                            <x-ai.answer
                                :message="$message"
                                :blocks="$blocks[$message->id] ?? null"
                                :html="$rendered[$message->id] ?? null"
                                :chart-views="$chartViews"
                            />

                            <x-ai.consulted :message="$message" />

                            <x-ai.proposal
                                :message="$message"
                                :confirming="$confirming"
                                :deletion-key="$this->pendingDeletionKey()"
                            />
                        </div>
                    @endforeach
                </div>

                {{--
                    The answer as it arrives. Livewire assigns this element's
                    innerHTML per fragment, so the component escapes every
                    fragment; once stored, the turn renders above instead.
                --}}
                <div
                    wire:stream.replace="answer"
                    aria-live="polite"
                    class="mt-3 empty:hidden rounded-lg border border-brand-200 bg-brand-50/40 p-2.5 text-sm whitespace-pre-wrap text-slate-700"
                ></div>
            </div>

            {{-- Composer ------------------------------------------------- --}}
            <div class="shrink-0 border-t border-slate-200 bg-surface px-4 py-3">
                @if (! $providerConfigured)
                    <p class="text-xs text-slate-500">
                        No AI provider is configured for this deployment, so the assistant cannot answer.
                        @can('administer-ai')
                            <a href="{{ route('admin.ai') }}" wire:navigate class="text-brand-700 underline decoration-dotted">
                                Configure it
                            </a>.
                        @endcan
                    </p>
                @else
                    {{-- Attachments. Above the box, so the drop zone is the
                         obvious target and a list of files gets the panel's
                         full width. --}}
                    <div class="mb-2">
                        <x-ai.attachments
                            id="ai-panel-attachments"
                            :attachments="$attachments"
                            :can-attach="$canAttach"
                            :settling="$attachmentsSettling"
                            :error="$uploadError"
                            :disabled="$sending"
                        />
                    </div>

                    <form wire:submit="send" class="space-y-2">
                        <x-ui.textarea
                            x-ref="composer"
                            wire:model="draft"
                            rows="2"
                            placeholder="Ask Nexora AI…"
                            aria-label="Ask Nexora AI"
                            :invalid="$errors->has('draft')"
                            :disabled="$sending"
                            @keydown.enter.prevent="$wire.send()"
                            @keydown.shift.enter.stop
                        >{{ $draft }}</x-ui.textarea>

                        @error('draft')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror

                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-2">
                                {{-- The disabled attribute is the courtesy; the
                                     guard is the re-entry check in send(). --}}
                                <x-ui.button
                                    type="submit"
                                    size="sm"
                                    wire:loading.attr="disabled"
                                    wire:target="send"
                                    :disabled="$sending"
                                >
                                    <span wire:loading.remove wire:target="send">Send</span>
                                    <span wire:loading wire:target="send">Thinking…</span>
                                </x-ui.button>

                                {{-- What the assistant may do with this
                                     question. Beside Send because it is a
                                     property of the question, not of the
                                     conversation's filing.

                                     The refusal line is withheld from a
                                     customer, who already has the standing
                                     sentence under this form saying the
                                     assistant changes nothing — saying it
                                     twice would read as an error rather than
                                     as how it works. --}}
                                <x-ai.chat-mode-picker
                                    id="ai-panel-mode"
                                    :modes="$chatModes"
                                    :selected="$chatMode"
                                    :refusal="$customer ? null : $chatModeRefusal"
                                    :disabled="$sending"
                                />

                                @if ($messages->isNotEmpty())
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        wire:click="clearHistory"
                                        wire:loading.attr="disabled"
                                        wire:target="send"
                                    >
                                        Clear
                                    </x-ui.button>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                {{-- Spoken conversation. Beside Send rather than
                                     inside the composer, because it is a second
                                     way to ask the same question — not a
                                     property of the text box. --}}
                                <x-ai.voice :voice="$voice" :disabled="$sending" />

                                <span class="text-xs text-slate-400">Enter to send</span>
                            </div>
                        </div>

                        @if ($customer)
                            {{-- What a customer is talking to, said plainly.
                                 The restriction is enforced on the server — see
                                 App\Livewire\Ai\Assistant — and this is only so
                                 nobody has to discover it by being refused. --}}
                            <p class="text-[11px] text-slate-500">
                                This assistant answers questions about what has been shared with you.
                                It cannot change tickets or documentation.
                            </p>
                        @endif
                    </form>
                @endif
            </div>
        @endif
    </aside>
</div>
