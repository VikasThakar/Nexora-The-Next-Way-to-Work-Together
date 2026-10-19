{{--
    Workspace AI chat. Team only.

    Customers cannot reach this: the route group carries role:admin,team, the
    component authorizes useAiChat on mount and on every render, and
    AiChatMessage::visibleTo() refuses them in SQL. The notice below is the
    product's consistent signal for "internal" — see x-ui.internal-notice.

    Assistant turns carry a quiet brand tint rather than the slate of the panel
    around them. That is the one accent the design system spends on generated
    content: enough to tell you at a glance who wrote a paragraph, without
    implying anything is wrong with it.

    Message bodies are rendered per viewer by ContentRenderer, so a ticket
    reference links only for somebody who may open that ticket.
--}}
<div class="mx-auto max-w-4xl">
    <x-ui.page-header
        title="Workspace AI"
        :description="'Ask about this board. Internal to the delivery team — '.$configuration->modelLabel().'.'"
        :trail="\App\Support\Breadcrumbs::boardChild($board, 'Workspace AI')"
    >

        <x-slot:actions>
            <x-ai.mode-badge :mode="$configuration->mode" :inherited="$configuration->isInherited('mode')" />

            {{-- Where this conversation may look, beside what it may do — the
                 same pairing the panel's header makes, so the two surfaces
                 present one setting in one place. --}}
            <x-ai.knowledge-scope
                id="ai-chat-outside-project"
                :disabled="$sending"
                :checked="$outsideProject"
                compact
            />

            @if ($canConfigure)
                <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary">AI settings</x-ui.button>
            @endif

            {{-- No longer behind the board-configuration ability: the
                 transcript is this person's own, so clearing it can only ever
                 discard their own turns. --}}
            @if ($messages->isNotEmpty())
                <x-ui.button type="button" variant="ghost" wire:click="clearHistory">Clear conversation</x-ui.button>
            @endif

            {{-- A fresh conversation on this board, the same control as the +
                 in the panel's header: the current session is ended, not
                 deleted. Not offered on one nothing has been asked in yet,
                 which already is fresh. See
                 TalksToWorkspaceAi::startNewSession(). --}}
            @unless ($session->isFresh())
                <x-ui.button
                    type="button"
                    variant="secondary"
                    wire:click="startNewSession"
                    wire:loading.attr="disabled"
                    wire:target="send,startNewSession"
                    :disabled="$sending"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New conversation
                </x-ui.button>
            @endunless

            <x-ui.button :href="route('boards.show', $board)" variant="secondary">Back to board</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{--
        The band that used to sit here — the session bar, the model picker and
        the usage panel — is gone, and its job moved to the mode picker beside
        Send in the composer. The panel made the same change at the same time;
        the two surfaces share a trait precisely so they cannot end up offering
        different controls for the same conversation.
    --}}

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($aiError)
        <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $aiError }}

            {{-- The remedy, offered where the problem is: a conversation that
                 has spent its allowance is answered by a new one. New
                 conversation in the header does the same, but the person
                 reading this error is looking here. --}}
            @if ($sessionExhausted)
                <button
                    type="button"
                    wire:click="startNewSession"
                    class="mt-1 block text-sm font-medium text-rose-900 underline decoration-dotted"
                >
                    Start a new conversation
                </button>
            @endif
        </div>
    @endif

    <x-ui.internal-notice class="mb-5" title="Your conversation, on this board.">
        Only you can read it, and it is never shown to customers. The assistant only receives
        board data <strong>you</strong> are allowed to see, and it cannot change anything without
        you confirming it first.
    </x-ui.internal-notice>

    {{-- ------------------------------------------------------------- --}}
    {{-- Transcript                                                      --}}
    {{-- ------------------------------------------------------------- --}}
    <div class="space-y-4">
        @if ($messages->isEmpty())
            <x-ui.empty-state
                title="Nothing asked yet"
                description="Ask about ticket status, what changed this week, what the documentation says, or ask for a ticket or a page to be drafted."
            />
        @endif

        @foreach ($messages as $message)
            <div wire:key="chat-{{ $message->id }}" @class([
                'rounded-lg border p-3',
                'border-slate-200 bg-surface' => ! $message->role->isAssistant(),
                'border-brand-200 bg-brand-50/40' => $message->role->isAssistant(),
            ])>
                <div class="mb-2 flex items-center gap-2 text-xs">
                    <span class="font-semibold text-slate-800">
                        {{ $message->role->isAssistant() ? 'Workspace AI' : ($message->user?->name ?? 'Someone') }}
                    </span>
                    <span class="text-slate-400">{{ $message->created_at?->diffForHumans() }}</span>

                    @if ($message->role->isAssistant() && data_get($message->metadata, 'tokens_output') !== null)
                        <span class="ml-auto text-slate-400">
                            {{ number_format((int) data_get($message->metadata, 'tokens_input', 0)) }} in /
                            {{ number_format((int) data_get($message->metadata, 'tokens_output', 0)) }} out
                        </span>
                    @endif
                </div>

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

    {{-- ------------------------------------------------------------- --}}
    {{-- Composer                                                        --}}
    {{-- ------------------------------------------------------------- --}}
    <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-slate-50/95 py-4 backdrop-blur">
        @if (! $providerConfigured)
            <p class="text-sm text-slate-500">
                No AI provider is configured for this deployment, so the chat cannot answer.
                @can('administer-ai')
                    An administrator can add a provider key in the
                    <a href="{{ route('admin.ai') }}" wire:navigate class="text-brand-700 underline decoration-dotted">global AI settings</a>.
                @else
                    Ask an administrator to add a provider key in the global AI settings.
                @endcan
            </p>
        @else
            {{--
                Where the answer appears while it is still arriving.

                Livewire replaces this element's innerHTML with each streamed
                fragment, so the component escapes every fragment before
                sending it. Once the turn is stored it renders in the transcript
                above through ContentRenderer, and this is cleared.
            --}}
            <div
                wire:stream.replace="answer"
                class="mb-3 empty:hidden rounded-lg border border-brand-200 bg-brand-50/40 p-3 text-sm whitespace-pre-wrap text-slate-700"
            ></div>

            {{-- The cue to start afresh before the ceiling refuses a question,
                 from 80% of the conversation's token allowance — the same
                 note the panel shows above its composer. --}}
            @if ($session->isOpen() && $session->isNearingTokenLimit($configuration->sessionTokenLimit))
                <div role="status" class="mb-3 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span>This conversation is getting long. Start a new one for a fresh context.</span>
                    <button
                        type="button"
                        wire:click="startNewSession"
                        wire:loading.attr="disabled"
                        wire:target="send,startNewSession"
                        @disabled($sending)
                        class="shrink-0 font-medium text-amber-900 underline decoration-dotted disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        New conversation
                    </button>
                </div>
            @endif

            {{--
                Attachments above the box rather than beside it, and the whole
                composer is one drop target: a screenshot dropped on the
                question or pasted into it is attached exactly as one dropped
                on the strip. See resources/js/file-drop.js.
            --}}
            <div
                x-data="fileDrop"
                x-on:dragenter.prevent="dragEnter($event)"
                x-on:dragover.prevent
                x-on:dragleave.prevent="dragLeave($event)"
                x-on:drop.prevent="drop($event)"
                class="rounded-lg transition"
                x-bind:class="dragging ? 'ring-2 ring-brand-500/40 ring-offset-2 ring-offset-slate-50' : ''"
            >
            <div class="mb-3">
                <x-ai.attachments
                    id="ai-chat-attachments"
                    :attachments="$attachments"
                    :can-attach="$canAttach"
                    :settling="$attachmentsSettling"
                    :error="$uploadError"
                    :disabled="$sending"
                />
            </div>

            <form wire:submit="send" class="space-y-2">
                <x-ui.textarea
                    wire:model="draft"
                    rows="3"
                    placeholder="Ask about this board, or about a file you attach — tickets, documentation, repositories. Or drop a screenshot and ask for a ticket."
                    :invalid="$errors->has('draft')"
                    :disabled="$sending"
                    @keydown.meta.enter.prevent="$wire.send()"
                    @keydown.ctrl.enter.prevent="$wire.send()"
                    x-on:paste="paste($event)"
                >{{ $draft }}</x-ui.textarea>

                @error('draft')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="flex items-start gap-3">
                    {{-- Disabled while a question is in flight. The real guard
                         is the re-entry check in TalksToWorkspaceAi::send(). --}}
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send" :disabled="$sending">
                        <span wire:loading.remove wire:target="send">Send</span>
                        <span wire:loading wire:target="send">Thinking…</span>
                    </x-ui.button>

                    {{-- What the assistant may do with this question:
                         reading, writing, or everything. The model follows
                         from it, which is why there is no model picker. --}}
                    <x-ai.chat-mode-picker
                        id="ai-chat-mode"
                        :modes="$chatModes"
                        :selected="$chatMode"
                        :refusal="$chatModeRefusal"
                        :disabled="$sending"
                    />
                    {{-- Spoken conversation, the same control the panel has.
                         One component and one state machine: a page and a
                         drawer that behaved differently would be two
                         features to keep in step. --}}
                    <x-ai.voice :voice="$voice" :disabled="$sending" />

                    <span class="text-xs text-slate-400">⌘/Ctrl + Enter</span>
                </div>

                {{-- The knowledge toggle was briefly here, below the composer.
                     It is in the page header now, beside the capability badge,
                     matching the panel. One control, one place. --}}
            </form>
            </div>
        @endif
    </div>
</div>
