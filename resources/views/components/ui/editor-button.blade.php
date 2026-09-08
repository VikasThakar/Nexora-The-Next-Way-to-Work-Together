@props([
    /*
     * Name of a command in the COMMANDS map in resources/js/editor.js.
     *
     * Omitted for buttons that do something the map cannot express — inserting
     * a table, prompting for a link — which pass their own x-on:click instead.
     */
    'command' => null,

    /** Announced name. There is no visible text: these are icons. */
    'label',
])

{{--
    One toolbar button.

    The icon is the slot, so a caller passes only the <path> — every button then
    shares one 24px stroke-1.7 viewBox and they cannot drift apart visually.

    aria-pressed rather than aria-selected or a class: these are toggles, and
    "Bold, toggle button, pressed" is what a screen reader should say. Buttons
    that are not toggles (undo, insert table) pass no command and get no
    aria-pressed, because a pressed state they never enter is worse than none.

    type="button" is load-bearing. The editor sits inside a <form wire:submit>,
    and a button without it defaults to submit — so clicking Bold would save the
    ticket.
--}}
<button
    type="button"
    @if ($command)
        x-on:click="run(@js($command))"
        x-bind:aria-pressed="isActive(@js($command))"
        x-bind:class="isActive(@js($command)) ? 'bg-brand-100 text-brand-700' : 'text-slate-500 hover:bg-slate-200 hover:text-slate-900'"
    @endif
    {{ $attributes->class([
        'shrink-0 rounded p-1.5 transition focus-visible:ring-2 focus-visible:ring-brand-500/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40',
        'text-slate-500 hover:bg-slate-200 hover:text-slate-900' => ! $command,
    ]) }}
    title="{{ $label }}"
    aria-label="{{ $label }}"
>
    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
        {{ $slot }}
    </svg>
</button>
