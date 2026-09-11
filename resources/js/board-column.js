/*
 * A Kanban column's scroll box.
 *
 * A column used to grow to whatever length its contents wanted, so a board's
 * height was decided by its busiest column and the quiet ones trailed off into
 * dead space below the fold. Each column is capped at seven cards instead, and
 * the rest of them scroll inside it — which also means the column headers, the
 * counts and every "+ Add a ticket" stay on screen together however lopsided
 * the board is.
 *
 * The cap is measured rather than guessed, for the same reason the comment
 * thread measures its own (see ./comment-thread). Cards are not a fixed
 * height: a title wraps to three lines, labels add a row, a due date and a
 * subtask count do not. A `max-h-96` would show five cards on one board and
 * eight on another, so the height is taken from the first seven cards actually
 * on the page, which is the only way "seven cards" means seven cards.
 *
 * Re-measuring is driven by a MutationObserver rather than a Livewire hook, so
 * it covers every way a column changes — a drop, a quick add, a filter, a
 * realtime refresh from somebody else's drop — without this file knowing which
 * of them happened.
 */

/** How many cards stay in view. The rest are below, in the scroll. */
const VISIBLE_CARDS = 7

function component() {
    return {
        observer: null,

        frame: null,

        /** Was a measurement turned away because a card was in the air? */
        frozen: false,

        init() {
            this.onResize = () => this.schedule()
            window.addEventListener('resize', this.onResize)

            /*
             * The end of a drag.
             *
             * The cap is frozen for the duration of one (see measure), and a
             * card dropped back where it started changes no ordering, so no
             * Livewire render and no mutation follow it to thaw the cap again.
             *
             * Only the columns a drag actually passed through were ever
             * frozen, so this is the one that leaves every ordinary click on
             * the page costing nothing: measuring forces layout, and a board
             * has a column of these.
             *
             * A timeout rather than a frame: `body.sorting` is cleared by
             * SortableJS's own pointer-up handling, which runs on the document
             * and so may not have happened when this one does.
             */
            this.onDragEnd = () => {
                if (this.frozen) {
                    setTimeout(() => this.schedule(), 0)
                }
            }
            window.addEventListener('pointerup', this.onDragEnd)
            window.addEventListener('pointercancel', this.onDragEnd)

            /*
             * childList is the obvious one, but not on its own enough:
             *
             *   - subtree and characterData, because a card changes height
             *     without being added or removed when a label is attached or
             *     a due date is set, and a morph patches that in place;
             *   - style, because the cap is an inline style and the server's
             *     HTML has none, so every morph strips it back off. Watching
             *     for that is what puts it back. It cannot loop: the cap is
             *     only written when the value would change, so the write that
             *     follows the strip is the last one.
             */
            this.observer = new MutationObserver(() => this.schedule())
            this.observer.observe(this.$el, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['style'],
            })

            this.schedule()
        },

        destroy() {
            this.observer?.disconnect()
            window.removeEventListener('resize', this.onResize)
            window.removeEventListener('pointerup', this.onDragEnd)
            window.removeEventListener('pointercancel', this.onDragEnd)

            if (this.frame) {
                cancelAnimationFrame(this.frame)
            }
        },

        /**
         * Measure on the next frame, once.
         *
         * A Livewire morph fires a burst of mutations; without this each one
         * would measure separately, and measuring forces layout.
         */
        schedule() {
            if (this.frame) {
                cancelAnimationFrame(this.frame)
            }

            this.frame = requestAnimationFrame(() => {
                this.frame = null
                this.measure()
            })
        },

        measure() {
            /*
             * Not while a card is in the air.
             *
             * SortableJS moves the dragged node out of one column and into
             * another as the pointer crosses between them, so during a single
             * drag both columns lose and gain a child several times. Measuring
             * on each of those would resize the drop target under the cursor
             * and shift the very column somebody is aiming at. The cap in
             * force when the drag started stands until it ends.
             */
            if (document.body.classList.contains('sorting')) {
                this.frozen = true

                return
            }

            this.frozen = false

            // Element children only, so Livewire's morph markers — which are
            // comment nodes — are not counted as cards.
            const cards = this.$el.children

            if (cards.length <= VISIBLE_CARDS) {
                // `none`, not empty: clearing the inline style would hand the
                // element back to the Tailwind cap that stands in before this
                // runs, and a column of three cards should not be capped.
                this.cap('none')

                return
            }

            const first = cards[0]
            const last = cards[VISIBLE_CARDS - 1]

            /*
             * Top of the first card to the bottom of the seventh, which spans
             * the gaps between them too. Read from rects rather than offsetTop
             * so it does not depend on which ancestor happens to be the offset
             * parent, and so it stays correct while the column is scrolled.
             */
            const span = last.getBoundingClientRect().bottom
                - first.getBoundingClientRect().top

            /*
             * Nothing laid out yet — the board is in a hidden container, or
             * this ran before the first layout. Capping to zero would collapse
             * the column to an empty strip, so leave it alone and wait for the
             * mutation or resize that makes it real.
             */
            if (span <= 0) {
                this.cap('none')

                return
            }

            /*
             * max-height is a limit on the border box, and the tray has
             * padding under the cards. Without adding it back the seventh card
             * would be clipped by exactly that much, which reads as six cards
             * and a sliver.
             */
            const style = getComputedStyle(this.$el)
            const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom)

            this.cap(Math.ceil(span + padding) + 'px')
        },

        /**
         * Write the cap, but only when it would actually change.
         *
         * The guard is what keeps the style half of the MutationObserver from
         * chasing its own tail: setting an attribute reports a mutation even
         * when the value is identical, so an unconditional write here would
         * schedule the next measure for ever.
         */
        cap(value) {
            if (this.$el.style.maxHeight !== value) {
                this.$el.style.maxHeight = value
            }
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardColumn', component)
})
