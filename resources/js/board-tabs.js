/*
 * The board's tab strip, as the `boardTabs` Alpine component.
 *
 * Keyboard behaviour of a tablist with manual activation: Left and Right move
 * focus to the neighbouring tab (wrapping at the ends), Home and End to the
 * first and last, and Enter or Space — the button's own behaviour — opens the
 * focused tab. Focus moves without opening because every switch is a round
 * trip to the server; arrowing along the strip should not fetch each tab it
 * passes.
 *
 * reveal() scrolls the open tab into view when the strip is wider than the
 * screen, on load and whenever the board says the tab changed.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardTabs', () => ({
        init() {
            this.reveal()
        },

        tabs() {
            return [...this.$root.querySelectorAll('[role="tab"]')]
        },

        step(direction) {
            const tabs = this.tabs()

            if (tabs.length === 0) return

            const current = tabs.indexOf(document.activeElement)
            const next = current === -1
                ? 0
                : (current + direction + tabs.length) % tabs.length

            tabs[next]?.focus()
        },

        edge(index) {
            const tabs = this.tabs()

            ;(index === 0 ? tabs[0] : tabs.at(-1))?.focus()
        },

        reveal() {
            this.$nextTick(() => {
                this.$root
                    .querySelector('[aria-selected="true"]')
                    ?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
            })
        },
    }))
})
