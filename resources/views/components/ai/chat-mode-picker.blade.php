{{--
    What this conversation is for, chosen beside Send.

    It sits next to the button rather than in a band above the transcript
    because it is a property of the question about to be asked, not of the
    session as a filing object — somebody switches to Writing in the same
    movement as typing "and raise a ticket for it". That is also why it replaced
    the model picker rather than joining it: the model follows from the mode
    (see App\Enums\AiChatMode), and two controls that can contradict each other
    are worse than one that cannot.

    A native <select>, for the reason the model picker was one: an <option>
    cannot hold two lines, and a bespoke listbox would owe the design system
    keyboard handling, focus trapping and screen-reader semantics it does not
    otherwise need. The mode's own summary goes on `title`, so hovering says
    what it does without spending a line of the composer on it.

    wire:change, not wire:model. The value is checked by an action before it is
    assigned, so a tampered <option> cannot put a conversation into a mode this
    person may not use — the same reasoning as the panel's context selector. The
    server narrows anyway, and this component is redrawn from what was actually
    stored.

    A mode that is not on offer is rendered DISABLED rather than omitted. Hiding
    it would leave somebody wondering where the feature went; showing it greyed,
    with the reason spelled out underneath, tells them it exists and what would
    have to change.
--}}
@props([
    // mode value => whether it may be selected. From chatModeOptions().
    'modes',
    'selected',
    // Why the write modes are unavailable, or null when they are not.
    'refusal' => null,
    'disabled' => false,
    'id' => 'ai-chat-mode',
])

<div>
    <label for="{{ $id }}" class="sr-only">What the assistant may do</label>

    <x-ui.select
        :id="$id"
        class="h-8 w-auto py-1 text-xs"
        wire:change="selectChatMode($event.target.value)"
        :disabled="$disabled"
        aria-describedby="{{ $refusal ? $id.'-refusal' : false }}"
    >
        @foreach (\App\Enums\AiChatMode::cases() as $mode)
            <option
                value="{{ $mode->value }}"
                title="{{ $mode->summary() }}"
                @selected($mode->value === $selected)
                @disabled(! ($modes[$mode->value] ?? false))
            >{{ $mode->label() }}</option>
        @endforeach
    </x-ui.select>

    @if ($refusal)
        <p id="{{ $id }}-refusal" class="mt-1 text-[11px] text-slate-500">{{ $refusal }}</p>
    @endif
</div>
