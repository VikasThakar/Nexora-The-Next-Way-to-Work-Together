/*
 * A drag is not a click.
 *
 * Dropping a card on the Kanban board used to open the ticket that had just
 * been moved, and the cause is not the click handler it looks like.
 *
 * `wire:navigate` does not wait for a click. It takes the press: on `mousedown`
 * it prefetches the page and arms a `mouseup` listener on the link itself, and
 * it navigates from there. SortableJS, meanwhile, finishes a drag by putting
 * the card back into the DOM at the drop position — which is directly under the
 * cursor, which is exactly where that `mouseup` lands. So the visit is already
 * under way before any click exists to cancel, and cancelling the click (which
 * SortableJS already does for us) changes nothing.
 *
 * The suppression therefore happens one step further along, at the navigation
 * itself: `livewire:navigate` is cancelable, and cancelling it is the supported
 * way to say "not this one". The drag is untouched, the drop still saves, and a
 * card that is genuinely clicked still opens.
 *
 * Nothing here is specific to tickets. Any draggable wire:navigate link in the
 * product gets the same protection, which is why it is a document-level module
 * rather than something on the card.
 */

/**
 * Has a drag just finished, with a navigation possibly already scheduled
 * behind it?
 */
let suppressNextNavigate = false

/*
 * SortableJS's own DOM event, dispatched on the list the drag started in and
 * bubbling from there. Two things make it the right signal:
 *
 *   - it fires inside the `mouseup` dispatch, while the navigation is still
 *     only a queued animation frame, so the flag is always set in time;
 *   - it fires only for a drag that actually began. A press that never moved
 *     far enough to pick a card up never reaches it, so an ordinary click on a
 *     card is never suppressed.
 *
 * Captured on the document so it is seen whichever list it came from, and
 * checked for `item` because "end" is a common enough name to be worth being
 * sure it is a sortable talking.
 */
document.addEventListener('end', (event) => {
    if (event.item instanceof HTMLElement) {
        suppressNextNavigate = true
    }
}, true)

document.addEventListener('livewire:navigate', (event) => {
    if (! suppressNextNavigate) {
        return
    }

    suppressNextNavigate = false

    event.preventDefault()
})

/*
 * A new press, or a keystroke, is a new intention.
 *
 * A drag does not always have a navigation behind it — the card can be dropped
 * somewhere that is not a link at all — and the flag would otherwise sit armed
 * and swallow whichever real navigation came next. Both of these run before
 * wire:navigate has even armed itself for that press, so they cannot disarm a
 * suppression that is still needed.
 */
const release = () => {
    suppressNextNavigate = false
}

document.addEventListener('pointerdown', release, true)
document.addEventListener('keydown', release, true)
