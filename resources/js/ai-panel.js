/*
 * The global AI panel's open state.
 *
 * Structured like ./dialog.js, and for the same reason: the thing that opens the
 * panel (a button in the top bar) and the panel itself (a drawer at the end of
 * the body) share no Alpine scope and no Livewire component, so neither can hold
 * the state for the other. A store is the only clean channel between them.
 *
 * Keeping open/closed on the client rather than in the Livewire component also
 * means opening the drawer is instant instead of a server round trip, and the
 * panel stays open across wire:navigate because Alpine is never torn down.
 *
 * The server is told only two things: reveal() the first time the panel is
 * opened, so a page nobody opened it on pays nothing, and syncPage() on each
 * navigation so the assistant knows what is on screen. Both are re-resolved and
 * re-authorized server-side; nothing here is trusted.
 */

const DEFAULTS = {
    open: false,
    // Where focus was when the panel opened, so it can be given back.
    origin: null,
    // The page descriptor most recently reported by the trigger.
    page: { board: null, ticket: null, doc: null },
    // Whether the server has been asked to populate the panel yet.
    revealed: false,
}

function createStore() {
    return {
        ...DEFAULTS,

        /*
         * `page` is passed in by the caller rather than read from the DOM here,
         * because the trigger is the only element that is re-rendered on every
         * navigation and therefore the only one that knows.
         */
        show(page) {
            if (page) this.page = page

            this.origin = document.activeElement instanceof HTMLElement ? document.activeElement : null
            this.open = true
        },

        close() {
            this.open = false

            /*
             * Return focus to whatever opened the panel, but only if it is still
             * in the document — a wire:navigate between opening and closing can
             * have replaced it, and focusing a detached node silently sends
             * focus to <body>.
             */
            const origin = this.origin
            this.origin = null

            if (origin && document.contains(origin)) origin.focus()
        },

        toggle(page) {
            this.open ? this.close() : this.show(page)
        },

        /*
         * Report the current page. Called by the trigger on every render, so it
         * fires once per navigation without needing a router hook.
         */
        setPage(page) {
            this.page = page
        },
    }
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine

    Alpine.store('aiPanel', createStore())

    const store = Alpine.store('aiPanel')

    Alpine.magic('aiPanel', () => store)

    /*
     * The panel component's own behaviour: tell the server the first time it is
     * opened, keep it told where the person is, and handle Escape.
     */
    Alpine.data('aiPanel', () => ({
        init() {
            /*
             * `$wire` is the Livewire component this element belongs to. The
             * first open populates the panel; later opens cost nothing.
             */
            this.$watch('$store.aiPanel.open', (open) => {
                if (! open) return

                const page = store.page

                if (! store.revealed) {
                    store.revealed = true
                    this.$wire.reveal(page.board, page.ticket, page.doc)
                } else {
                    this.$wire.syncPage(page.board, page.ticket, page.doc)
                }

                // Focus the composer once the drawer has finished moving.
                this.$nextTick(() => this.$refs.composer?.focus())
            })

            /*
             * Escape closes the panel — unless a confirmation dialog is open on
             * top of it, which has its own Escape handler on the window. Without
             * this guard, dismissing a confirmation would also close the panel
             * underneath it.
             */
            this.escape = (event) => {
                if (event.key !== 'Escape') return
                if (! store.open) return
                if (window.Alpine.store('dialog')?.open) return

                store.close()
            }

            window.addEventListener('keydown', this.escape)
        },

        destroy() {
            window.removeEventListener('keydown', this.escape)
        },
    }))
})

/*
 * The desktop gutter.
 *
 * The panel is `fixed`, so it never participates in layout and cannot push the
 * page. Toggling a class on <body> lets one CSS rule inset the content column
 * instead, which gives the docked feel of an editor sidebar without moving the
 * panel into the layout's flex tree — where a persisted subtree would sit inside
 * a re-initialising x-data root.
 */
document.addEventListener('alpine:init', () => {
    const store = window.Alpine.store('aiPanel')

    window.Alpine.effect(() => {
        document.body.classList.toggle('ai-panel-open', store.open)
    })
})
