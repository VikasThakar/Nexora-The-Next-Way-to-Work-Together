{{--
    The cue to start a fresh conversation, shown above the composer in the
    panel and on the full-page chat.

    Three situations, most urgent first:

      a spending cap   only when a deployment has set one (none by default):
                       from 80% of it, before the ceiling refuses a question
                       halfway through a thought;
      the window       the conversation now fills most of what the model can
                       read at once, so its oldest messages are about to leave
                       the model's view;
      already trimmed  they have left it — said once, plainly, so nobody is
                       surprised when the assistant cannot see the beginning.

    The remedy is the same in all three, the + in the header, offered where the
    person is about to type.
--}}
@props(['session' => null, 'configuration', 'sending' => false, 'compact' => false])

@php
    $message = null;

    if ($session?->isOpen()) {
        $message = match (true) {
            $session->isNearingTokenLimit($configuration->sessionTokenLimit) => 'This conversation is getting long. Start a new one for a fresh context.',
            $session->isNearingContextWindow() => 'This conversation is nearly as long as the assistant can read at once. Older messages will drop out of view; start a new one for a fresh context.',
            $session->hasDroppedHistory() => 'The earliest messages in this conversation are no longer in the assistant\'s view. Start a new one for a fresh context.',
            default => null,
        };
    }
@endphp

@if ($message !== null)
    <div role="status" @class([
        'flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800',
        'mb-2 gap-2 text-xs' => $compact,
        'mb-3 gap-3 text-sm' => ! $compact,
    ])>
        <span>{{ $message }}</span>
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
