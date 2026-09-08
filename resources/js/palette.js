/*
 * The command palette: ⌘K on a Mac, Ctrl+K everywhere else.
 *
 * Two pieces, in the same shape as ./dialog and ./ai-panel:
 *
 *   a store    whether the palette is open. A store because the shortcut is
 *              global, the trigger lives in the top bar and the panel lives at
 *              the end of the body — three places with no shared scope.
 *   a data     keyboard navigation within the results. Per-instance, because
 *              it tracks a cursor over DOM that Livewire re-renders.
 *
 * Open state is deliberately NOT Livewire state. Pressing the shortcut has to
 * put a focused input on screen immediately; a round trip to learn that the
 * palette is open would make the fastest path through the application wait for
 * the network.
 *
 *
 * Why the cursor is read from the DOM
 * -----------------------------------
 * The results are server-rendered links, and Livewire replaces them on every
 * debounced keystroke. Mirroring them into JavaScript would mean keeping two
 * copies of the list in step — and the copy in the browser is the one that
 * would go stale mid-keypress. So the cursor is an index into whatever
 * `[data-hit]` elements are on screen at the moment a key is pressed, which
 * cannot disagree with what the person is looking at.
 */

/** Which modifier the platform's users expect. Cosmetic; both are accepted. */
function isApple() {
    return /Mac|iPhone|iPad|iPod/.test(window.navigator.platform ?? '')
        || /Mac/.test(window.navigator.userAgent ?? '')
}

function store() {
    return {
        open: false,

        /** For the hint on the trigger button: "⌘K" or "Ctrl K". */
        get shortcut() {
            return isApple() ? '⌘K' : 'Ctrl K'
        },

        show() {
            this.open = true
        },

        hide() {
            this.open = false
        },

        toggle() {
            this.open = !this.open
        },
    }
}

/**
 * Keyboard navigation over the rendered results.
 *
 * `$wire` is available because this component is inside the palette's Livewire
 * root.
 */
function component() {
    return {
        /** Index into the hits currently on screen. */
        active: 0,

        init() {
            /*
             * Focus and clear are driven from the store rather than from a
             * click handler, so the palette behaves the same whether it was
             * opened by the shortcut or by the button in the top bar.
             */
            this.$watch('$store.palette.open', (open) => {
                if (open) {
                    this.active = 0
                    this.$nextTick(() => this.$refs.input?.focus())

                    return
                }

                /*
                 * Cleared without a round trip. The palette is closed, so
                 * nothing is waiting on the result; the empty value simply
                 * travels with whichever request happens next.
                 */
                if (this.$refs.input) {
                    this.$refs.input.value = ''
                }

                this.$wire.set('term', '', false)
                this.active = 0
            })
        },

        hits() {
            return Array.from(this.$el.querySelectorAll('[data-hit]'))
        },

        /**
         * Keep the cursor on something that exists.
         *
         * Called after every re-render: a narrowing search can leave the cursor
         * past the end of a shorter list.
         */
        clamp() {
            const count = this.hits().length

            this.active = count === 0 ? 0 : Math.min(this.active, count - 1)
        },

        move(delta) {
            const hits = this.hits()

            if (hits.length === 0) {
                return
            }

            // Wraps, so Down from the last row returns to the first — which is
            // what every palette does and what people try.
            this.active = (this.active + delta + hits.length) % hits.length

            hits[this.active]?.scrollIntoView({ block: 'nearest' })
        },

        /** Enter: follow the highlighted result. */
        choose() {
            const hit = this.hits()[this.active]

            if (!hit) {
                return
            }

            /*
             * A real click rather than assigning location, so wire:navigate
             * handles it and the palette does not cost a full page load. Rows
             * with nowhere to open are rendered as non-links and simply do
             * nothing here.
             */
            hit.click()
            this.$store.palette.hide()
        },

        isActive(index) {
            return this.active === index
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('palette', store())
    window.Alpine.data('commandPalette', component)
})

/*
 * The global shortcut.
 *
 * On the document rather than on the palette element, so it fires wherever the
 * focus happens to be — including inside the rich text editor, whose own
 * shortcuts do not use Ctrl+K.
 *
 * `capture: true` so it runs before an editor or a form can consume the key.
 */
document.addEventListener('keydown', (event) => {
    if (event.key !== 'k' && event.key !== 'K') {
        return
    }

    if (!(event.metaKey || event.ctrlKey) || event.altKey || event.shiftKey) {
        return
    }

    const palette = window.Alpine?.store('palette')

    if (!palette) {
        return
    }

    // Ctrl+K is "delete to end of line" in some inputs and a link shortcut in
    // some editors; the palette wins, deliberately, and the alternative in
    // those places is the mouse.
    event.preventDefault()

    palette.toggle()
}, { capture: true })

/*
 * A palette left open across a wire:navigate would sit over the page it just
 * navigated to. Closed on arrival rather than on the click, so a click that
 * turns out not to navigate leaves it alone.
 */
document.addEventListener('livewire:navigated', () => {
    window.Alpine?.store('palette')?.hide()
})

