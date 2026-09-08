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
        :description="'Ask about this board. Internal to the delivery team — '.$model.'.'"
        :trail="\App\Support\Breadcrumbs::boardChild($board, 'Workspace AI')"
    >

        <x-slot:actions>
            @if ($canConfigure)
                <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary">AI settings</x-ui.button>
            @endif

            {{-- No longer behind the board-configuration ability: the
                 transcript is this person's own, so clearing it can only ever
                 discard their own turns. --}}
            @if ($messages->isNotEmpty())
                <x-ui.button type="button" variant="ghost" wire:click="clearHistory">Clear conversation</x-ui.button>
            @endif

            <x-ui.button :href="route('boards.show', $board)" variant="secondary">Back to board</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($aiError)
        <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $aiError }}
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
                'rounded-xl border p-4',
                'border-slate-200 bg-white' => ! $message->role->isAssistant(),
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

                <div class="markdown text-sm">{!! $rendered[$message->id] ?? e($message->content) !!}</div>

                <x-ai.proposal :message="$message" :confirming="$confirming" />
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
                Set <code class="font-mono">ANTHROPIC_API_KEY</code> on the web service.
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
                class="mb-3 empty:hidden rounded-xl border border-brand-200 bg-brand-50/40 p-4 text-sm whitespace-pre-wrap text-slate-700"
            ></div>

            <form wire:submit="send" class="space-y-2">
                <x-ui.textarea
                    wire:model="draft"
                    rows="3"
                    placeholder="Ask about this board — tickets, documentation, repositories. Or ask for a ticket to be drafted."
                    :invalid="$errors->has('draft')"
                    :disabled="$sending"
                    @keydown.meta.enter.prevent="$wire.send()"
                    @keydown.ctrl.enter.prevent="$wire.send()"
                >{{ $draft }}</x-ui.textarea>

                @error('draft')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    {{-- Disabled while a question is in flight. The real guard
                         is the re-entry check in TalksToWorkspaceAi::send(). --}}
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send" :disabled="$sending">
                        <span wire:loading.remove wire:target="send">Send</span>
                        <span wire:loading wire:target="send">Thinking…</span>
                    </x-ui.button>
                    <span class="text-xs text-slate-400">⌘/Ctrl + Enter</span>
                </div>
            </form>
        @endif
    </div>
</div>
