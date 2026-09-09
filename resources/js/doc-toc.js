/*
 * "On this page" — the documentation table of contents.
 *
 * Built from the DOM rather than from the Markdown, which is what lets one
 * implementation serve both states of the page. A reader is looking at
 * ContentRenderer's HTML; a writer is looking at a live ProseMirror document
 * that has no server-side representation between keystrokes. Both are headings
 * in an element, so both work here, and the contents list a writer sees
 * reorganises itself as they type.
 *
 * Nothing about this is persisted or sent anywhere. It is a view of whatever is
 * currently on screen.
 *
 * Headings are addressed by position, not by id
 * ---------------------------------------------
 * The obvious implementation assigns each heading an `id` and links to it. It
 * does not work here, and the reason is worth writing down: inside the editor
 * ProseMirror owns the DOM and reconciles away attributes it did not put there,
 * so ids written by this file are silently stripped again. Every anchor then
 * resolves to nothing — no scrolling, and an active-heading test comparing
 * empty strings that matches every row at once.
 *
 * So a heading is identified by its index among the headings, and `go()` looks
 * the element up again at click time. Nothing is written to the document at all,
 * which is also why this cannot fight the editor for the DOM.
 */

/** Below this, a contents list is longer than the document it describes. */
const MIN_HEADINGS = 3

/** How far above the viewport top a heading counts as the one being read. */
const ACTIVE_OFFSET = 96

/** Clears the sticky page furniture when jumping to a heading. */
const SCROLL_MARGIN = 80

function component(selector) {
    return {
        /** @type {Array<{index: number, text: string, level: number}>} */
        headings: [],

        activeIndex: null,

        root: null,

        observer: null,

        frame: null,

        init() {
            this.root = document.querySelector(selector)

            if (!this.root) {
                return
            }

            this.onScroll = () => this.trackActive()
            this.onResize = () => this.schedule()

            // Both the rendered document and the editor mutate in place, so
            // childList alone is not enough — typing inside an existing
            // heading is a characterData change.
            this.observer = new MutationObserver(() => this.schedule())
            this.observer.observe(this.root, {
                childList: true,
                subtree: true,
                characterData: true,
            })

            window.addEventListener('scroll', this.onScroll, { passive: true })
            window.addEventListener('resize', this.onResize)

            this.schedule()
        },

        destroy() {
            this.observer?.disconnect()
            window.removeEventListener('scroll', this.onScroll)
            window.removeEventListener('resize', this.onResize)

            if (this.frame) {
                cancelAnimationFrame(this.frame)
            }
        },

        /**
         * Rebuild on the next frame, once.
         *
         * Typing produces a mutation per keystroke and rebuilding reads layout,
         * so without this the contents list would be the most expensive thing
         * on the page.
         */
        schedule() {
            if (this.frame) {
                cancelAnimationFrame(this.frame)
            }

            this.frame = requestAnimationFrame(() => {
                this.frame = null
                this.build()
            })
        },

        /** The heading elements currently in the document, in order. */
        elements() {
            return this.root ? [...this.root.querySelectorAll('h1, h2, h3, h4')] : []
        },

        build() {
            const found = []

            this.elements().forEach((el, index) => {
                const text = (el.textContent || '').trim()

                // A heading being typed is empty for a keystroke or two, and an
                // empty row in the contents is worse than a missing one.
                if (text === '') {
                    return
                }

                found.push({ index, text, level: Number(el.tagName.slice(1)) })
            })

            this.headings = found.length >= MIN_HEADINGS ? found : []

            this.trackActive()
        },

        /** Which heading is being read: the last one above the fold line. */
        trackActive() {
            if (this.headings.length === 0) {
                this.activeIndex = null

                return
            }

            const elements = this.elements()
            let active = this.headings[0].index

            for (const heading of this.headings) {
                const el = elements[heading.index]

                if (el && el.getBoundingClientRect().top <= ACTIVE_OFFSET) {
                    active = heading.index

                    continue
                }

                break
            }

            this.activeIndex = active
        },

        go(index) {
            const el = this.elements()[index]

            if (!el) {
                return
            }

            /*
             * Measured and scrolled by hand rather than with scrollIntoView.
             *
             * scrollIntoView takes its offset from `scroll-margin-top`, which
             * would mean writing a style onto a heading the editor may own —
             * the thing this file exists to avoid. Reading the rect and asking
             * the window to move needs nothing from the element.
             */
            const top = el.getBoundingClientRect().top + window.scrollY - SCROLL_MARGIN

            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
            this.activeIndex = index
        },

        /** Indent for a nested heading, as an inline style. */
        indent(level) {
            // h1 and h2 share the left edge: a document whose top-level
            // headings are h2 should not be uniformly indented for it.
            return `padding-left: ${Math.max(0, level - 2) * 0.75}rem`
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('docToc', component)
})
