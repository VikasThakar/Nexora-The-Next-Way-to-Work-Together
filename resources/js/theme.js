/*
 * Which appearance the application is wearing.
 *
 * An Alpine store rather than component state, for the same reason the
 * documentation tree and the command palette are stores: the control lives at
 * the foot of the sidebar, the thing it changes is the <html> element, and
 * every page in between is replaced by wire:navigate. Local x-data would be
 * rebuilt on each navigation and would have to re-derive what it already knew.
 *
 *
 * Three states, two of them visible
 * ---------------------------------
 * The *preference* is one of light, dark or system — the same three values as
 * App\Enums\ThemePreference, because this is the browser half of that one
 * setting. The *resolved* appearance is only ever light or dark. Keeping them
 * apart is what makes "System" work: the preference stays `system` while the
 * resolved value follows the device, so a laptop that turns dark at sunset
 * takes Nexora with it without anybody having stored `dark`.
 *
 *
 * Who is the source of truth
 * --------------------------
 * The <html> element. Not this store, and not localStorage — both of those are
 * caches of it. That matters because the class on <html> is applied by an
 * inline script in the layout head, before this bundle has downloaded, and it
 * is the only way to avoid a flash of the wrong appearance. By the time Alpine
 * boots, the appearance is already correct; this store's first job is to read
 * what was decided rather than to decide it again.
 *
 *
 * Where the preference is kept
 * ----------------------------
 * For a signed-in person, on their `users` row, so the choice follows them to
 * another browser. The write is fire-and-forget: the appearance has already
 * changed by the time the request leaves, so its latency is invisible and its
 * failure costs only the cross-device part.
 *
 * For everybody, in localStorage as well. That is what a guest gets — and it
 * is also what keeps the login page in the right appearance after somebody has
 * signed out, when there is no row to read.
 */

const KEY = 'nexora.theme'

const VALUES = ['light', 'dark', 'system']

/*
 * What to use when nothing has been chosen.
 *
 * Light, matching App\Enums\ThemePreference::default() and the column default
 * — the appearance the product had before this setting existed. Dark and
 * follow-the-device are things somebody opts into.
 *
 * In practice this is rarely reached: the inline script in the layout head has
 * already settled the question and written it to `data-theme` before this file
 * runs. It matters for the cases where that script did not run at all.
 */
const FALLBACK = 'light'

/** How long the colour transition in app.css is allowed to run. */
const TRANSITION_MS = 220

const query = () => window.matchMedia('(prefers-color-scheme: dark)')

function read() {
    try {
        const stored = window.localStorage.getItem(KEY)

        return VALUES.includes(stored) ? stored : null
    } catch {
        // A browser in private mode, or one told to block site data, throws on
        // read as well as on write. An appearance is not worth an exception:
        // the preference simply lasts as long as the page does.
        return null
    }
}

function write(preference) {
    try {
        window.localStorage.setItem(KEY, preference)
    } catch {
        // See read().
    }
}

/**
 * Put the appearance on the <html> element.
 *
 * `data-theme` carries the preference and the class carries the resolution,
 * because the two answer different questions: the control needs to know which
 * of three buttons is pressed, and the stylesheet needs to know which of two
 * palettes to use.
 */
function paint(preference, { animate = false } = {}) {
    const root = document.documentElement
    const dark = preference === 'dark' || (preference === 'system' && query().matches)

    /*
     * The transition is opt-in per change rather than a standing rule. See the
     * `html.theme-switching` block in resources/css/app.css: left on
     * permanently it would fade every hover in the product and animate the
     * first paint of every page from the wrong colours to the right ones.
     */
    if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        root.classList.add('theme-switching')
        window.setTimeout(() => root.classList.remove('theme-switching'), TRANSITION_MS)
    }

    root.classList.toggle('dark', dark)
    root.dataset.theme = preference

    return dark
}

function component() {
    return {
        /** 'light' | 'dark' | 'system' */
        preference: FALLBACK,

        /** 'light' | 'dark' — what `preference` currently amounts to. */
        resolved: 'light',

        init() {
            /*
             * Read what the inline head script already decided rather than
             * deciding again. If it did not run — a page served without the
             * layout, a test rendering a component on its own — fall back
             * through localStorage to the default.
             */
            const root = document.documentElement

            this.preference = VALUES.includes(root.dataset.theme)
                ? root.dataset.theme
                : (read() ?? FALLBACK)

            this.resolved = paint(this.preference) ? 'dark' : 'light'

            /*
             * Follow the device while the preference is `system`, and only
             * then. Somebody who has explicitly chosen light does not want
             * their laptop overriding them at sunset.
             */
            query().addEventListener('change', () => {
                if (this.preference === 'system') {
                    this.resolved = paint('system', { animate: true }) ? 'dark' : 'light'
                }
            })

            /*
             * Re-assert after a wire:navigate.
             *
             * The class should survive on its own — <html> is outside the
             * region Livewire morphs — but "should" is doing a lot of work in
             * a sentence about a full-page swap, and re-applying a class that
             * is already there costs nothing. Without the animate flag, so
             * arriving on a page never fades.
             */
            document.addEventListener('livewire:navigated', () => {
                this.resolved = paint(this.preference) ? 'dark' : 'light'
            })
        },

        /** Whether a given option is the one currently chosen. */
        is(preference) {
            return this.preference === preference
        },

        set(preference) {
            if (!VALUES.includes(preference) || preference === this.preference) {
                return
            }

            this.preference = preference
            this.resolved = paint(preference, { animate: true }) ? 'dark' : 'light'

            write(preference)
            this.persist(preference)
        },

        /**
         * Tell the server, if there is anybody to tell.
         *
         * The endpoint is rendered onto <html> by the layout, and only for a
         * signed-in viewer — so its absence is how this knows not to bother.
         * Deliberately unawaited and deliberately silent: nothing on screen is
         * waiting for it, and a failed write leaves the appearance correct
         * everywhere except on the person's other devices.
         */
        persist(preference) {
            const endpoint = document.documentElement.dataset.themeEndpoint

            if (!endpoint) {
                return
            }

            const token = document.querySelector('meta[name="csrf-token"]')?.content

            fetch(endpoint, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ theme: preference }),
                credentials: 'same-origin',
                keepalive: true,
            }).catch(() => {
                // See the comment above.
            })
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', component())
})
