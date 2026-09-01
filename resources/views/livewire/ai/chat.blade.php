{{--
    Workspace AI chat. Team only.

    Customers cannot reach this: the route group carries role:admin,team, the
    component authorizes useAiChat on mount and on every render, and
    AiChatMessage::visibleTo() refuses them in SQL. The amber framing is the
    product's consistent signal for "internal".

    Message bodies are rendered per viewer by ContentRenderer, so a ticket
    reference links only for somebody who may open that ticket.
--}}
<div class="mx-auto max-w-4xl">
    <x-ui.page-header
        title="Workspace AI"
        :description="'Ask about this board. Internal to the delivery team — '.$model.'.'"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('boards.index') }}" wire:navigate class="hover:text-slate-700">Boards</a>
            <span class="mx-1">/</span>
            <a href="{{ route('boards.show', $board) }}" wire:navigate class="hover:text-slate-700">{{ $board->name }}</a>
        </x-slot:breadcrumb>

        <x-slot:actions>
            @if ($canConfigure)
                <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary">AI settings</x-ui.button>

                @if ($messages->isNotEmpty())
                    <x-ui.button type="button" variant="ghost" wire:click="clearHistory">Clear conversation</x-ui.button>
                @endif
            @endif

            <x-ui.button :href="route('boards.show', $board)" variant="secondary">Back to board</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-5 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
        <svg class="mt-0.5 size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
        <span>
            This conversation is shared with everyone on the delivery team for this board and is never
            shown to customers. The assistant only receives board data <strong>you</strong> are allowed to
            see, and it cannot change anything without you confirming it first.
        </span>
    </div>

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
                'border-amber-200 bg-amber-50/50' => $message->role->isAssistant(),
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

                {{-- --------------------------------------------------- --}}
                {{-- A proposed action, and its state                      --}}
                {{-- --------------------------------------------------- --}}
                @php $action = $message->action(); @endphp

                @if ($action)
                    @php
                        $type = $message->actionType();
                        $state = $message->actionState();
                        $input = $message->actionInput();
                    @endphp

                    <div @class([
                        'mt-4 rounded-lg border p-3',
                        'border-brand-200 bg-brand-50' => $state === \App\Models\AiChatMessage::ACTION_PROPOSED,
                        'border-emerald-200 bg-emerald-50' => $state === \App\Models\AiChatMessage::ACTION_CONFIRMED,
                        'border-slate-200 bg-slate-50' => $state === \App\Models\AiChatMessage::ACTION_DISCARDED,
                        'border-rose-200 bg-rose-50' => $state === \App\Models\AiChatMessage::ACTION_FAILED,
                    ])>
                        <p class="text-xs font-semibold text-slate-800">
                            Proposed: {{ $type?->label() ?? 'unknown action' }}
                        </p>

                        {{-- The preview. Everything the model proposed, so what is
                             being confirmed is visible before confirming it. --}}
                        <dl class="mt-2 space-y-1 text-xs">
                            @foreach ($input as $field => $value)
                                @if (is_scalar($value) && filled($value))
                                    <div>
                                        <dt class="font-medium text-slate-600">{{ str_replace(['_md', '_'], ['', ' '], (string) $field) }}</dt>
                                        <dd class="whitespace-pre-wrap text-slate-700">{{ \Illuminate\Support\Str::limit((string) $value, 1200) }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>

                        @if ($state === \App\Models\AiChatMessage::ACTION_PROPOSED)
                            @if ($confirming && $confirming->id === $message->id)
                                <p class="mt-3 text-xs font-medium text-brand-900">
                                    Confirm this change? It will be made now, as you, and recorded in the
                                    ticket or page history.
                                </p>
                                <div class="mt-2 flex items-center gap-2">
                                    <x-ui.button type="button" size="sm" wire:click="confirm">
                                        {{ $type?->confirmLabel() ?? 'Confirm' }}
                                    </x-ui.button>
                                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                                        Not yet
                                    </x-ui.button>
                                </div>
                            @else
                                <div class="mt-3 flex items-center gap-2">
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        wire:click="startConfirming({{ $message->id }})"
                                    >
                                        Review and confirm
                                    </x-ui.button>
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        wire:click="discard({{ $message->id }})"
                                    >
                                        Discard
                                    </x-ui.button>
                                </div>
                            @endif
                        @elseif ($state === \App\Models\AiChatMessage::ACTION_CONFIRMED)
                            <p class="mt-2 text-xs text-emerald-800">
                                {{ $action['result']['label'] ?? 'Done.' }}
                                @if (! empty($action['result']['url']))
                                    &middot; <a href="{{ $action['result']['url'] }}" wire:navigate class="underline">Open</a>
                                @endif
                            </p>
                        @elseif ($state === \App\Models\AiChatMessage::ACTION_DISCARDED)
                            <p class="mt-2 text-xs text-slate-500">Discarded. Nothing was changed.</p>
                        @elseif ($state === \App\Models\AiChatMessage::ACTION_FAILED)
                            <p class="mt-2 text-xs text-rose-800">
                                {{ $action['error'] ?? 'That change could not be made.' }}
                            </p>
                        @endif
                    </div>
                @endif
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
            <form wire:submit="send" class="space-y-2">
                <x-ui.textarea
                    wire:model="draft"
                    rows="3"
                    placeholder="Ask about this board — tickets, documentation, repositories. Or ask for a ticket to be drafted."
                    :invalid="$errors->has('draft')"
                    @keydown.meta.enter="$wire.send()"
                    @keydown.ctrl.enter="$wire.send()"
                >{{ $draft }}</x-ui.textarea>

                @error('draft')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send">
                        <span wire:loading.remove wire:target="send">Send</span>
                        <span wire:loading wire:target="send">Thinking…</span>
                    </x-ui.button>
                    <span class="text-xs text-slate-400">⌘/Ctrl + Enter</span>
                </div>
            </form>
        @endif
    </div>
</div>
