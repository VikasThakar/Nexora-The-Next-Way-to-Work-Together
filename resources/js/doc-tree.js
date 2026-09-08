/*
 * Expand and collapse in the documentation tree.
 *
 * An Alpine store rather than per-node state, for one reason: a documentation
 * page is reached with wire:navigate, which replaces the whole component. Local
 * x-data on each branch would reset on every click, so somebody who had tidied
 * a forty-page tree down to the branch they were working in would find it back
 * the way it was the moment they opened a page.
 *
 *
 * What is open
 * ------------
 * A branch nobody has touched is closed, and the branch containing the page
 * being read is open. That is what makes the sidebar a table of contents rather
 * than a wall — the whole reason the brief asks for expand and collapse at all.
 * The open path comes from the server (openIds in App\Livewire\Docs\Show),
 * because only the server knows the ancestor chain, and only the server is
 * allowed to know it: an invisible ancestor is not in that list.
 *
 * A branch somebody has clicked is whatever they clicked it to be, remembered
 * per board. Their choice beats the default in both directions, so the chevron
 * on an already-open branch closes it rather than appearing to do nothing.
 *
 * The one interaction worth spelling out: opening a page inside a branch that
 * was collapsed earlier clears that branch's stored state, so the page you
 * asked for is visible when you arrive. Collapsing it again then sticks, until
 * the next time you follow a link into it. Anything else means either a link
 * that lands on a sidebar not showing where you are, or a chevron that cannot
 * close the one branch you are working in.
 *
 *
 * Storage
 * -------
 * localStorage, keyed per board so two boards do not share a shape. Every
 * access is guarded: a browser in private mode, or one told to block site data,
 * throws on read as well as on write, and a sidebar is not worth an exception.
 * Losing this state costs a differently shaped tree and nothing else.
 */

const KEY = 'nexora.docs.tree'

/** Stop the stored object growing without bound in a large workspace. */
const MAX_BOARDS = 50

function read() {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(KEY) || '{}')

        return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
        return {}
    }
}

function write(state) {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(state))
    } catch {
        // Nothing to do, and nothing worth telling anybody: the tree simply
        // opens the active path again next time.
    }
}

function store() {
    return {
        /** Which board's tree is on screen. Set by use(). */
        board: 'default',

        /** { [boardId]: { [pageId]: boolean } } — an explicit choice per node. */
        chosen: {},

        loaded: false,

        /** The open path this store last reconciled against, as a string. */
        appliedPath: null,

        /**
         * Called once by the tree container, before any node renders.
         *
         * Reading storage here rather than in an Alpine `init()` keeps the
         * parse off every page in the application with no tree on it.
         *
         * @param {number|string} board
         * @param {Array<number>} openIds  ancestors of the page being read
         */
        use(board, openIds = []) {
            this.board = String(board)

            if (!this.loaded) {
                this.chosen = read()
                this.loaded = true
            }

            this.chosen[this.board] ??= {}

            const path = this.board + ':' + openIds.join(',')

            // Guarded so a Livewire re-render that happens to re-run this —
            // an autosave, a drag, a search — cannot wipe a choice made since
            // the page loaded.
            if (this.appliedPath === path) {
                return
            }

            this.appliedPath = path

            if (openIds.length === 0) {
                return
            }

            // Following a link into a collapsed branch reopens it. See above.
            let changed = false

            for (const id of openIds) {
                if (this.chosen[this.board][String(id)] === false) {
                    delete this.chosen[this.board][String(id)]
                    changed = true
                }
            }

            if (changed) {
                write(this.chosen)
            }
        },

        /**
         * @param {number} id       the page whose children are in question
         * @param {boolean} forced  true when the page being read is at or
         *                          below this node
         */
        isOpen(id, forced = false) {
            const explicit = this.chosen[this.board]?.[String(id)]

            return typeof explicit === 'boolean' ? explicit : forced
        },

        toggle(id, forced = false) {
            const branch = this.chosen[this.board] ??= {}

            branch[String(id)] = !this.isOpen(id, forced)

            this.prune()
            write(this.chosen)
        },

        /** Keep the stored object from accumulating boards forever. */
        prune() {
            const boards = Object.keys(this.chosen)

            if (boards.length <= MAX_BOARDS) {
                return
            }

            for (const board of boards) {
                if (board !== this.board) {
                    delete this.chosen[board]
                }
            }
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('docTree', store())
})
