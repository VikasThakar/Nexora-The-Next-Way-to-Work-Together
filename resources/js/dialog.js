/*
 * The application's dialog system.
 *
 * Replaces every native window.confirm()/alert() in the product. Those are
 * unstyleable, they block the whole browser tab, they say "127.0.0.1:8000 says"
 * above the message, and on mobile they are a system sheet that looks nothing
 * like the page underneath.
 *
 * Three things are registered here, all on the Alpine that Livewire bundles —
 * there is no separate Alpine install and nothing to import:
 *
 *   the `dialog` store      holds the one open dialog and its pending promise.
 *                           The markup lives in a single Blade component
 *                           rendered once per layout (x-ui.dialog), so there is
 *                           exactly one modal in the DOM however many places
 *                           trigger it.
 *
 *   `$dialog` magic         so a template can call
 *                           `$dialog.confirm({...}).then(...)` inline.
 *
 *   `x-confirm` directive   the replacement for `wire:confirm`. It intercepts
 *                           the click, asks, and only then lets the original
 *                           handler run — which means `wire:click` stays on the
 *                           element as the single description of what the
 *                           button does. Nothing has to restate the Livewire
 *                           call in JavaScript, so the two cannot drift.
 *
 * Also exposed as `window.dialog` for imperative use from anywhere, and bridged
 * to Livewire so a component can `$this->dispatch('dialog', type: 'error', …)`.
 */

/** Shape of a dialog, with the defaults every caller inherits. */
const DEFAULTS = {
    // success | error | warning | info | confirm | prompt
    type: 'info',
    title: '',
    body: '',
    // Only meaningful for `confirm`: colours the confirm button.
    tone: 'danger',
    confirmText: 'Confirm',
    cancelText: 'Cancel',

    /*
     * `prompt` only: the text field.
     *
     * A prompt exists because the rich text editor needs to ask for a link
     * address, and `window.prompt` is exactly the kind of native dialog this
     * file replaces — unstyleable, tab-blocking, and prefixed with the host
     * name. Adding the type here rather than building an input into the editor
     * means one dialog in the DOM, one focus trap, one Escape handler, and any
     * future "name this thing" question gets it for free.
     */
    value: '',
    placeholder: '',
    inputLabel: '',
    /*
     * Whether Escape and a backdrop click dismiss it.
     *
     * True for everything by default, including confirmations: dismissing
     * resolves to "no", so the easy exit is always the safe one. A caller can
     * set it false for a message that must be acknowledged, which is why the
     * option exists at all.
     */
    dismissible: true,
}

function createStore() {
    return {
        open: false,
        config: { ...DEFAULTS },

        /** Resolver for the promise handed to the caller. */
        resolve: null,

        /** The element focus returns to when the dialog closes. */
        origin: null,

        /**
         * Show a dialog and resolve when it closes.
         *
         * Resolves true only when the confirm button was pressed. Escape, the
         * backdrop, the cancel button and a second dialog arriving all resolve
         * false, so `if (await confirm(...))` is safe in every path.
         */
        show(config = {}) {
            // A second dialog while one is open resolves the first as declined
            // rather than stacking. Two modals over each other is never what
            // was wanted, and silently dropping the first would leave a caller
            // awaiting a promise that never settles.
            if (this.open) {
                this.settle(false)
            }

            this.config = { ...DEFAULTS, ...config }
            this.origin = document.activeElement
            this.open = true

            return new Promise((resolve) => {
                this.resolve = resolve
            })
        },

        /** Close, resolving the pending promise. */
        settle(result) {
            if (!this.open) {
                return
            }

            this.open = false

            const resolve = this.resolve
            this.resolve = null

            // Focus goes back where it came from, so keyboard and screen-reader
            // users are not dropped at the top of the document.
            const origin = this.origin
            this.origin = null

            if (origin && typeof origin.focus === 'function' && document.contains(origin)) {
                origin.focus()
            }

            if (resolve) {
                resolve(result)
            }
        },

        /**
         * A prompt resolves with the text; everything else with true.
         *
         * Trimmed here rather than at the call site, so no caller has to
         * remember: whitespace typed into a link field is not an address.
         */
        confirmed() {
            this.settle(this.isPrompt ? String(this.config.value ?? '').trim() : true)
        },

        dismissed() {
            // Ignored for a dialog that must be acknowledged.
            if (! this.config.dismissible) {
                return
            }

            // null rather than false, so a prompt can tell "cancelled" from
            // "submitted empty" — which for a link means "remove the link".
            this.settle(this.isPrompt ? null : false)
        },

        /** Dialogs with a cancel button as well as an action. */
        get isConfirmation() {
            return this.config.type === 'confirm' || this.isPrompt
        },

        get isPrompt() {
            return this.config.type === 'prompt'
        },
    }
}

/*
 * Background scrolling and focus are handled by `x-trap.noscroll.inert` on the
 * panel, not here. That comes from the Alpine Focus plugin, which Livewire
 * bundles — it locks the body, compensates for the scrollbar width so the page
 * does not jump sideways as the dialog opens, traps Tab inside the panel, and
 * marks everything outside it inert for assistive technology.
 *
 * Doing it here as well would mean two mechanisms fighting over the same
 * `overflow` and `padding-right`, which goes wrong the first time one of them
 * misses a cleanup. Returning focus to the trigger IS handled here, though: the
 * store outlives the panel element, which Livewire may re-render underneath a
 * closing dialog.
 */

/**
 * The public API. Deliberately small, and the same whether it is called from a
 * template, from application JavaScript, or from a Livewire event.
 */
function createApi(store) {
    const message = (type) => (title, body = '', options = {}) =>
        store.show({
            type,
            title,
            body,
            confirmText: 'Close',
            ...options,
        })

    return {
        show: (config) => store.show(config),

        /** @returns {Promise<boolean>} */
        confirm: (config) => store.show({ type: 'confirm', confirmText: 'Confirm', ...config }),

        /**
         * Ask for a line of text.
         *
         * Resolves with the trimmed string, or null if it was dismissed. An
         * empty string is a real answer and is deliberately distinguishable
         * from a cancellation.
         *
         * @returns {Promise<string|null>}
         */
        ask: (config) => store.show({ type: 'prompt', confirmText: 'Save', ...config }),

        success: message('success'),
        error: message('error'),
        warning: message('warning'),
        info: message('info'),

        close: () => store.settle(false),
    }
}

/**
 * `x-confirm` — ask before letting a click through.
 *
 * The direct replacement for `wire:confirm`, written the same way at the call
 * site and reading the same in a diff:
 *
 *     <x-ui.button
 *         wire:click="remove({{ $comment->id }})"
 *         x-confirm="@js(['title' => 'Delete comment?', 'body' => '…'])"
 *     >
 *
 * The attribute is an ordinary Alpine expression, evaluated rather than parsed.
 * That is what makes `@js()` work — it emits `JSON.parse('…')`, a JavaScript
 * expression, not raw JSON — and it means escaping is Alpine's problem, which
 * it already solves for `x-data`. It also allows a config that reads component
 * state, since the expression is re-evaluated on every click.
 *
 * How the interception works. The listener is registered in the CAPTURE phase,
 * which runs before the bubble-phase listener Livewire attaches to the same
 * element, and `stopImmediatePropagation()` there prevents Livewire's handler
 * from seeing the click at all. If the person confirms, the element is clicked
 * again with a one-shot flag set, and that second click passes straight through
 * to the handler that was always there.
 *
 * The alternative — `x-on:click="$dialog.confirm(…).then(() => $wire.remove(1))"`
 * — would make the template state what the button does twice, in two languages,
 * and a change to one would silently not reach the other. Here `wire:click`
 * remains the single description of the action and the confirmation is a
 * decoration on top of it.
 */
function registerDirective(Alpine, api) {
    Alpine.directive('confirm', (el, { expression }, { evaluateLater, cleanup }) => {
        const readConfig = evaluateLater(expression || '{}')

        const listener = (event) => {
            // The re-dispatched click after confirming. Let it through.
            if (el.dataset.confirmed === 'true') {
                delete el.dataset.confirmed
                return
            }

            event.preventDefault()
            event.stopImmediatePropagation()

            readConfig((config) => {
                api.confirm(typeof config === 'object' && config !== null ? config : {})
                    .then((confirmed) => {
                        if (!confirmed) {
                            return
                        }

                        el.dataset.confirmed = 'true'
                        el.click()
                    })
            })
        }

        el.addEventListener('click', listener, { capture: true })

        cleanup(() => el.removeEventListener('click', listener, { capture: true }))
    })
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine

    Alpine.store('dialog', createStore())

    const store = Alpine.store('dialog')
    const api = createApi(store)

    window.dialog = api
    Alpine.magic('dialog', () => api)

    registerDirective(Alpine, api)
})

/*
 * Livewire bridge.
 *
 * Lets a component raise a dialog without any JavaScript at the call site:
 *
 *     $this->dispatch('dialog', type: 'error', title: '…', body: '…');
 *
 * Livewire delivers named parameters as an object and positional ones as an
 * array, so both shapes are accepted rather than depending on how the caller
 * happened to dispatch.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.on('dialog', (payload) => {
        const config = Array.isArray(payload) ? payload[0] : payload

        if (config && window.dialog) {
            window.dialog.show(config)
        }
    })
})
