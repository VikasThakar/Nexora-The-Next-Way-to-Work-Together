@props([
    // App\Models\AiChatMessage
    'message',
    // The message currently being confirmed, or null.
    'confirming' => null,
    // For a destructive action being confirmed: the key that has to be typed.
    // Supplied by the host from $this->pendingDeletionKey(); null otherwise.
    'deletionKey' => null,
])

@php
    $action = $message->action();
@endphp

{{--
    A change the assistant has proposed, and its state.

    Shared by the full-page board chat and the global panel, so the preview and
    the confirmation read identically wherever somebody meets them. That matters
    more here than in most shared markup: this is the screen on which a person
    decides whether a write happens, and two versions of it would eventually
    disagree about what they were agreeing to.

    Nothing here executes anything. The buttons call the host component, which
    re-authorizes and hands the message to App\Actions\AI\ExecuteChatAction.
--}}
@if ($action)
    @php
        $type = $message->actionType();
        $state = $message->actionState();
        $input = $message->actionInput();
    @endphp

    @php
        $destructive = $type?->isDestructive() === true;
    @endphp

    <div @class([
        'mt-4 rounded-lg border p-3',
        // Rose for a pending deletion rather than brand: this is the one
        // proposal where the colour should say "stop and read", and amber means
        // warning rather than destruction in this design system.
        'border-rose-300 bg-rose-50' => $state === \App\Models\AiChatMessage::ACTION_PROPOSED && $destructive,
        'border-brand-200 bg-brand-50' => $state === \App\Models\AiChatMessage::ACTION_PROPOSED && ! $destructive,
        'border-emerald-200 bg-emerald-50' => $state === \App\Models\AiChatMessage::ACTION_CONFIRMED,
        'border-slate-200 bg-slate-50' => $state === \App\Models\AiChatMessage::ACTION_DISCARDED,
        'border-rose-200 bg-rose-50' => $state === \App\Models\AiChatMessage::ACTION_FAILED,
    ])>
        <p @class([
            'text-xs font-semibold',
            'text-rose-900' => $destructive && $state === \App\Models\AiChatMessage::ACTION_PROPOSED,
            'text-slate-800' => ! ($destructive && $state === \App\Models\AiChatMessage::ACTION_PROPOSED),
        ])>
            @if ($state === \App\Models\AiChatMessage::ACTION_CONFIRMED)
                {{ $type?->label() ?? 'Action' }}
            @else
                Proposed: {{ $type?->label() ?? 'unknown action' }}
            @endif
        </p>

        {{-- The preview. Everything the model proposed, so what is
             being confirmed is visible before confirming it. --}}
        <dl class="mt-2 space-y-1 text-xs">
            @foreach ($input as $field => $value)
                @php
                    // Arrays are flattened to a readable list rather than
                    // skipped: `labels` arrives as one, and a preview that
                    // silently omitted it would be a preview of a different
                    // change from the one about to be made.
                    $shown = is_array($value)
                        ? implode(', ', array_filter(array_map(
                            fn ($item) => is_scalar($item) ? (string) $item : null,
                            $value,
                        )))
                        : (is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : null));
                @endphp

                @if (filled($shown))
                    <div>
                        <dt class="font-medium text-slate-600">{{ str_replace(['_md', '_'], ['', ' '], (string) $field) }}</dt>
                        <dd class="whitespace-pre-wrap text-slate-700">{{ \Illuminate\Support\Str::limit($shown, 1200) }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>

        @if ($state === \App\Models\AiChatMessage::ACTION_PROPOSED)
            @if ($confirming && $confirming->id === $message->id)
                @if ($destructive)
                    {{--
                        The destructive gate.

                        Typing the ticket's own key is the confirmation, because
                        the requirement is that no phrase may serve as one — and
                        a phrase is exactly what a model produces. The server
                        re-checks this when the button is pressed; the box is
                        the courtesy, not the boundary.
                    --}}
                    <p class="mt-3 text-xs font-medium text-rose-900">
                        This permanently deletes the ticket, with its comments and history.
                        It cannot be undone.
                    </p>

                    <label class="mt-2 block text-xs text-rose-900">
                        <span class="font-medium">
                            Type {{ $deletionKey ?? 'the ticket’s key' }} to confirm
                        </span>
                        <input
                            type="text"
                            wire:model="deleteConfirmation"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ $deletionKey ?? 'e.g. NL-18' }}"
                            class="mt-1 w-40 rounded-md border border-rose-300 bg-white px-2 py-1 font-mono text-xs text-slate-900 placeholder:text-rose-300 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                        />
                    </label>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" size="sm" variant="danger" wire:click="confirm">
                            {{ $type?->confirmLabel() ?? 'Confirm' }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                            Keep it
                        </x-ui.button>
                    </div>
                @else
                    <p class="mt-3 text-xs font-medium text-brand-900">
                        Confirm this change? It will be made now, as you, and recorded in the
                        ticket or page history.
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" size="sm" wire:click="confirm">
                            {{ $type?->confirmLabel() ?? 'Confirm' }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                            Not yet
                        </x-ui.button>
                    </div>
                @endif
            @else
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.button
                        type="button"
                        size="sm"
                        :variant="$destructive ? 'danger' : 'primary'"
                        wire:click="startConfirming({{ $message->id }})"
                    >
                        {{ $destructive ? 'Review this deletion' : 'Review and confirm' }}
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
