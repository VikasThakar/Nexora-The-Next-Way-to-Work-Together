/*
 * The ticket conversation's scroll box.
 *
 * A long thread used to push the composer off the bottom of the screen, so the
 * box is capped and the history scrolls inside it. Two things make that behave
 * like a conversation rather than a list:
 *
 *   1. The cap is measured, not guessed. Messages are not a fixed height — one
 *      is a line, the next is three paragraphs and two attachments — so a
 *      `max-h-96` would show four messages here and six there. The height is
 *      taken from the last five items actually on the page, which is the only
 *      way "five messages" means five messages.
 *   2. It follows the newest message only while the reader is already at the
 *      bottom. Realtime means a message can arrive while somebody is reading
 *      back through the history, and yanking them to the end mid-sentence is
 *      worse than making them scroll. Scrolling up opts out; scrolling back
 *      down opts in again.
 *
 * Re-measuring is driven by a MutationObserver rather than a Livewire hook, so
 * it covers every way the thread changes — a post, an edit, a realtime refresh
 * — without this file knowing which of them happened.
 */

/** How many messages stay in view. The rest are above, in the scroll. */
const VISIBLE_MESSAGES = 5

/**
 * How close to the bottom still counts as "at the bottom".
 *
 * Not zero: a fractional scrollHeight, a sub-pixel zoom level or the last
 * message's border are each enough to leave a pixel or two behind, and a reader
 * who is visibly at the end should not be treated as having scrolled away.
 */
const PIN_THRESHOLD = 24

function component() {
    return {
        /** Does the view follow new messages? False once the reader scrolls up. */
        pinned: true,

        observer: null,

        frame: null,

        init() {
            this.onScroll = () => {
                this.pinned = this.atBottom()
            }

            this.onResize = () => this.schedule()

            // Passive: this only reads scroll offsets, it never preventDefaults.
            this.$el.addEventListener('scroll', this.onScroll, { passive: true })
            window.addEventListener('resize', this.onResize)

            // characterData and subtree as well as childList: an edit saved in
            // place changes a message's height without adding or removing one.
            this.observer = new MutationObserver(() => this.schedule())
            this.observer.observe(this.$el, {
                childList: true,
                subtree: true,
                characterData: true,
            })

            this.schedule()
        },

        destroy() {
            this.observer?.disconnect()
            this.$el.removeEventListener('scroll', this.onScroll)
            window.removeEventListener('resize', this.onResize)

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

                if (this.pinned) {
                    this.toBottom()
                }
            })
        },

        measure() {
            const items = this.$el.children

            /*
             * An open edit form lifts the cap.
             *
             * The composer sits outside this element, so a <form> in here is
             * somebody editing a message in place — and a textarea inside a
             * five-message window is a keyhole to type through. The thread
             * expands until they are done.
             */
            const editing = this.$el.querySelector('form') !== null

            if (editing || items.length <= VISIBLE_MESSAGES) {
                // `none`, not empty: clearing the inline style would hand the
                // element back to the Tailwind cap that stands in before this
                // runs, and the point here is to be uncapped.
                this.$el.style.maxHeight = 'none'

                return
            }

            const first = items[items.length - VISIBLE_MESSAGES]
            const last = items[items.length - 1]

            /*
             * Bottom of the last message to the top of the fifth from last,
             * which spans the gaps between them too. Read from rects rather
             * than offsetTop so it does not depend on which ancestor happens
             * to be the offset parent, and so it stays correct while scrolled.
             */
            const height = last.getBoundingClientRect().bottom
                - first.getBoundingClientRect().top

            /*
             * Nothing laid out yet — the thread is in a hidden container, or
             * this ran before the first layout. Capping to zero would collapse
             * the conversation to an empty strip, so leave it alone and wait
             * for the mutation or resize that makes it real.
             */
            if (height <= 0) {
                this.$el.style.maxHeight = 'none'

                return
            }

            this.$el.style.maxHeight = Math.ceil(height) + 'px'
        },

        atBottom() {
            return this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight
                < PIN_THRESHOLD
        },

        toBottom() {
            this.$el.scrollTop = this.$el.scrollHeight
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('commentThread', component)
})
