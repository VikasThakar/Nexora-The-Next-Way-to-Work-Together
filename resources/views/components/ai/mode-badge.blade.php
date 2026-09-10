{{--
    What the AI is allowed to do here, as a badge.

    The colour comes from the enum rather than from this template, so the three
    modes read the same wherever they appear — the global settings screen, a
    board's overrides, the assistant header. Slate for AI Observer because
    "reads only" is the quiet, safe state, and amber for AI Agent because
    unattended work is the one an administrator should notice; amber means
    attention in this design system and nothing else.

    The title attribute carries the mode's one-line summary, so the badge is
    self-explanatory on hover without spending a line of layout on it.
--}}
@props([
    'mode',
    'inherited' => false,
])

<x-ui.badge :variant="$mode->badgeVariant()" :title="$mode->summary()">
    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        @if ($mode === \App\Enums\AiCapabilityMode::Observer)
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        @elseif ($mode === \App\Enums\AiCapabilityMode::Operator)
            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
        @else
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
        @endif

        <title>{{ $mode->label() }}</title>
    </svg>

    {{ $mode->label() }}

    @if ($inherited)
        <span class="font-normal opacity-70">· inherited</span>
    @endif
</x-ui.badge>
