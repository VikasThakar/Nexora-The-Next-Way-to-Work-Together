@props([
    // App\Models\AiChatMessage
    'message',
    // The message currently being confirmed, or null.
    'confirming' => null,
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
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" size="sm" wire:click="confirm">
                        {{ $type?->confirmLabel() ?? 'Confirm' }}
                    </x-ui.button>
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelConfirming">
                        Not yet
                    </x-ui.button>
                </div>
            @else
                <div class="mt-3 flex flex-wrap items-center gap-2">
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
