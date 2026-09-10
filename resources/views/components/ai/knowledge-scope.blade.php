{{--
    The "Outside Project" checkbox: where the assistant may source answers from.

    It lives in the panel HEADER, on the row with the capability badge — not in
    the composer. That is the right filing for two reasons. It is a property of
    the CONVERSATION rather than of the next question, so it belongs with the
    other whole-conversation controls (the context, Clear, close) rather than
    beside Send. And it keeps it well away from the Reading/Writing/Everything
    picker, which is the misreading worth designing against: those two are easy
    to read as one setting with four positions, and they are unrelated — one
    says what the assistant may DO, this says where it may LOOK.

    Two variants
    ------------
    `compact` — the header pill. No helper paragraph; the explanation is on
    `title`, which is how x-ai.mode-badge beside it does the same job. The pill
    tints brand when checked, because with the prose gone the checked state has
    to be legible at a glance rather than from a 16px tick.

    Full — a checkbox with its explanatory line under it, for a surface with the
    width to spend on one.

    A real checkbox in both, not a switch
    -------------------------------------
    Styled from the design system's own checkbox idiom (see the AI settings
    screens) rather than built into a toggle. A switch implies an immediate
    change to the world; a checkbox implies a setting that applies to what
    happens next, which is exactly what this is. It also means keyboard
    operation, screen-reader semantics and label-click behaviour are the
    browser's own — a div with role="switch" would owe all three by hand.

    wire:model.live, unusually for this codebase
    --------------------------------------------
    The selectors elsewhere in the panel use wire:change and an action, because
    their values have to be checked before they are accepted. This one does not:
    both states are permitted for everybody, because the knowledge scope is not
    an authorization setting. What it does need is persisting, which
    TalksToWorkspaceAi::updatedOutsideProject() does — and which also refuses a
    change made while an answer is streaming, then re-reads the stored value so
    the box shows the truth. See that method, and App\Enums\AiKnowledgeScope.

    Dark mode needs no variants here. app.css remaps the neutral scale under
    html.dark, so slate-600 and brand-600 resolve correctly in both appearances
    and in the system setting.
--}}
@props([
    'id' => 'ai-outside-project',
    'disabled' => false,
    // Header pill, or the full control with its explanatory line.
    'compact' => false,
    // Reflected only to style the pill. The input's own state comes from the
    // bound property, never from this.
    'checked' => false,
])

@php
    $explanation = 'Let the assistant answer with general knowledge from outside this project as '
        .'well. It sees no more of the workspace either way.';
@endphp

@if ($compact)
    <label
        for="{{ $id }}"
        title="{{ $explanation }}"
        @class([
            'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium transition',
            'border-brand-300 bg-brand-50 text-brand-800' => $checked,
            'border-slate-200 bg-slate-50 text-slate-600' => ! $checked,
            'cursor-pointer hover:border-slate-300' => ! $disabled,
            'cursor-not-allowed opacity-60' => $disabled,
        ])
    >
        <input
            type="checkbox"
            id="{{ $id }}"
            wire:model.live="outsideProject"
            @disabled($disabled)
            class="size-3 rounded border-slate-300 text-brand-600 focus:ring-1 focus:ring-brand-500 disabled:cursor-not-allowed"
        >

        Outside Project
    </label>
@else
    <div>
        <label
            for="{{ $id }}"
            @class([
                'flex items-start gap-2.5',
                'cursor-pointer' => ! $disabled,
                'cursor-not-allowed opacity-60' => $disabled,
            ])
        >
            <input
                type="checkbox"
                id="{{ $id }}"
                wire:model.live="outsideProject"
                @disabled($disabled)
                class="mt-0.5 size-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:cursor-not-allowed"
                aria-describedby="{{ $id }}-hint"
            >

            <span class="min-w-0">
                <span class="block text-xs font-medium text-slate-700">Outside Project</span>

                {{-- Short, and it says what changes rather than what the
                     feature is called. The one thing worth spending a line on
                     is the reassurance that ticking it does not open anything
                     up — that is the question people actually have. --}}
                <span id="{{ $id }}-hint" class="mt-0.5 block text-[11px] leading-snug text-slate-500">
                    {{ $explanation }}
                </span>
            </span>
        </label>
    </div>
@endif
