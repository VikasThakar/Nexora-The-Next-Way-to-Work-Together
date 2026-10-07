@props([
    /**
     * The cases to offer, already in the order they should appear.
     *
     * Any backed enum with a label(). `variant="dot"` additionally needs a
     * dotClass() returning a complete Tailwind class — App\Enums\TicketPriority
     * is the one that has it.
     *
     * @var array<int, \BackedEnum>
     */
    'options',

    /** The value currently held, so the right radio is checked before any script runs. */
    'selected',

    /** The shared `name` of the radio group. One per group, per document. */
    'name',

    /** What the group is for. Rendered sr-only, so the radios have a group name. */
    'legend',

    /** The Livewire property to bind. Deferred, like every other field on the form. */
    'model',

    /** 'dot' for a colour swatch, 'pill' for the label in a chip. */
    'variant' => 'pill',

    /** Prefix for each option's id, so two groups on one page cannot collide. */
    'idPrefix',

    /** Show the chosen option's name in words beside the group. */
    'showSelected' => false,
])

@php
    $isDot = $variant === 'dot';

    /*
     * value => label, for the live caption. Built from the same cases the radios
     * are built from, so the two cannot disagree.
     */
    $names = [];

    foreach ($options as $option) {
        $names[$option->value] = $option->label();
    }
@endphp

{{--
    A compact single choice — a row of coloured dots, or a row of small pills.

    Native radio inputs, hidden with `sr-only` and styled through the `peer`
    variant on the swatch beside them. That is this product's own pattern, not a
    new one: a label's colour is already chosen from a row of circles built
    exactly this way in resources/views/livewire/boards/settings.blade.php, and
    the New Ticket page toggles its labels the same way.

    The point of using real radios is everything we then do not have to write. A
    <fieldset> of inputs sharing a `name` already is a radio group, so the arrow
    keys move within it, Tab moves past it as a single stop, the <legend> names
    it, and the checked state is reported correctly — all from the browser. A row
    of buttons with role="radio" would owe all of that by hand, and would owe it
    again on every screen that reused the component.

    That one-tab-stop behaviour is why `checked` is rendered here on the server
    rather than left to the binding. An unchecked group is a group the browser
    puts *entirely* in the tab order, so without this the eight radios on the
    quick-add card would be eight tab stops until the first script ran, and the
    card would paint with nothing selected.

    Nothing here is live. The binding is a deferred wire:model, so choosing an
    option costs no request at all; the value travels with the form when it is
    submitted, exactly as the <select> it replaces did.

    One deliberate departure from the colour picker it is modelled on: there the
    `title` sits on a <span>, which means the radio itself has no accessible
    name. Here the dot variant carries an sr-only label, so the control is
    nameable as well as hoverable. The pill variant does not need one — its
    label is on screen, and repeating it would have a screen reader say the word
    twice.
--}}
<div
    @if ($showSelected) x-data="{ names: @js($names) }" @endif
    {{ $attributes->class('flex min-w-0 items-center gap-1.5') }}
>
    {{-- min-w-0 because a fieldset defaults to min-width: min-content, which
         Tailwind's preflight does not reset and which would stop the strip
         around it from wrapping. --}}
    <fieldset class="flex min-w-0 items-center gap-1">
        <legend class="sr-only">{{ $legend }}</legend>

        @foreach ($options as $option)
            <label
                @class(['cursor-pointer', 'p-1.5' => $isDot])
                @if ($isDot) title="{{ $option->label() }} priority" @endif
            >
                <input
                    type="radio"
                    id="{{ $idPrefix }}-{{ $option->value }}"
                    name="{{ $name }}"
                    value="{{ $option->value }}"
                    wire:model="{{ $model }}"
                    @checked((string) $selected === $option->value)
                    class="peer sr-only"
                >

                @if ($isDot)
                    {{--
                        Full colour whether chosen or not, like the label colour
                        picker this copies. Dimming the unselected ones was tried
                        and abandoned: two of the five priorities are already
                        greys, and at reduced opacity on a dark surface Low and
                        Nice-to-have composite to within a few per cent of each
                        other and of the card behind them.

                        So selection is the ring, and `ring-offset-surface`
                        rather than the default offset colour — that default is
                        white, which on a dark card would draw a halo.

                        Focus is an outline, not a second ring. A radio group has
                        native roving tabindex, so the only radio that can take
                        focus is the checked one — the same element already
                        carrying a ring. Two utilities writing the same ring
                        colour would be resolved by Tailwind's own ordering
                        rather than by anything written here. An outline is a
                        different property and cannot collide, and it is what
                        every button in the product already focuses with.
                    --}}
                    <span class="block size-3 rounded-full transition hover:opacity-70 peer-checked:ring-2 peer-checked:ring-slate-900 peer-checked:ring-offset-1 peer-checked:ring-offset-surface peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-500 {{ $option->dotClass() }}"></span>

                    <span class="sr-only">{{ $option->label() }}</span>
                @else
                    {{-- Neutral until chosen. Painting each type in its own
                         badge colour would make an unselected rose "Bug" read as
                         a statement about the ticket rather than as an option. --}}
                    <span class="block rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-slate-500 ring-1 ring-slate-200 transition ring-inset hover:text-slate-800 peer-checked:bg-slate-900 peer-checked:text-white peer-checked:ring-slate-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-500">{{ $option->label() }}</span>
                @endif
            </label>
        @endforeach
    </fieldset>

    @if ($showSelected)
        {{--
            The chosen option in words, so five coloured circles do not have to
            be learnt by hovering them one at a time.

            `$wire` is an Alpine-reactive proxy over the component's own state
            and a deferred wire:model writes that state before it does anything
            else, so this updates on the click with no request behind it.

            Hidden from assistive technology: the checked radio is already
            announced by its own sr-only label, and this is the same word again.

            The width is held so that going from "Low" to "Nice-to-have" cannot
            change where the strip wraps and move the card under the pointer.
        --}}
        <span
            aria-hidden="true"
            class="min-w-[4.5rem] text-[11px] whitespace-nowrap text-slate-500"
            x-text="names[$wire.{{ $model }}] ?? ''"
        ></span>
    @endif
</div>
