@props([
    /** Which panel this handle sizes: 'nav' or 'ai'. The bounds live in resources/js/panel-resize.js. */
    'panel',

    /** Named for a screen reader, which has no edge to point at. */
    'label',

    /** The id of the region being sized. */
    'controls',

    /** Which side of its own container the handle sits on — 'right' for a left-hand panel. */
    'side' => 'right',
])

@php
    /*
     * Written out as whole class names rather than built from $side, so that
     * Tailwind's source scanner can see both of them in this file.
     */
    $edge = $side === 'left' ? 'left-0' : 'right-0';
@endphp

{{--
    The grab edge of a resizable panel.

    One component for both panels, so there is a single piece of resize markup
    in the product in the same way there is a single piece of resize script.

    `role="separator"` with a tabindex is the W3C window-splitter pattern: a
    separator is normally decorative, and a focusable one is the exception that
    is genuinely operable. That is what gives a keyboard the arrow keys, Home
    and End — a drag handle reachable only with a mouse would put a preference
    behind a pointing device. `role="separator"` has no text to take a name
    from, hence the explicit label.

    The aria-value* attributes are deliberately absent here and are written by
    resources/js/panel-resize.js. They cannot be rendered: the maximum depends
    on how much room the viewport has left after the other panel, which is a
    fact about the browser and not about the request.

    `wire:ignore` matters for the assistant, whose handle sits inside a
    component that re-renders on every streamed token — a morph that replaced
    this node mid-drag would drop the pointer capture with it. In the layout it
    is inert, and it is kept here rather than made conditional because a handle
    is a handle.

    The whole thing sits inside the panel's own width and never reaches out over
    the content, where the sticky top bar would be above it for the first four
    rem and the handle would simply stop working up there. That also means no
    z-index: an absolutely positioned element already paints over the static
    panel beside it.
--}}
<div
    x-data="panelResize({ panel: '{{ $panel }}' })"
    x-on:pointerdown="start($event)"
    x-on:keydown="key($event)"
    wire:ignore
    role="separator"
    aria-orientation="vertical"
    aria-label="{{ $label }}"
    aria-controls="{{ $controls }}"
    tabindex="0"
    title="Drag to resize. Double-click to reset."
    @class([
        'group absolute inset-y-0 hidden w-3 cursor-col-resize touch-none focus:outline-none lg:block',
        $edge,
    ])
>
    {{--
        The line itself.

        Invisible at rest: the panel already has a border there, and a second
        visible divider would be a rule the design does not have. It thickens
        and takes the brand colour on hover, and thickens again on focus — two
        steps rather than one, so a keyboard user can tell being focused from
        being merely pointed at.

        That second step is the focus indicator, which is why the outline is
        turned off above. A three-pixel brand line running the full height of
        the viewport against slate has considerably more area and contrast than
        the outline it replaces, and it appears in the place the control
        actually acts.

        `pointer-events-none` so every event belongs to the handle, whichever of
        the two is under the cursor.
    --}}
    <div
        x-bind:class="dragging && 'w-1 bg-brand-500'"
        @class([
            'pointer-events-none absolute inset-y-0 w-px transition-[width,background-color] duration-100',
            'group-hover:w-0.5 group-hover:bg-brand-500',
            'group-focus-visible:w-1 group-focus-visible:bg-brand-500',
            $edge,
        ])
    ></div>
</div>
