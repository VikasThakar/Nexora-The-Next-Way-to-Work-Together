import './bootstrap';
import './echo';

/*
 * Application JavaScript.
 *
 * Livewire ships its own bundle (including Alpine) and injects it into the
 * page, so nothing needs to be imported or started here. Drag-and-drop comes
 * from wire:sort, the Alpine Sort plugin bundled inside Livewire 4, and the
 * realtime client is set up in ./echo — but only when a Reverb key is present.
 *
 * This file is the home for hand-written interactions later phases need, where
 * a Livewire round trip would be too slow.
 */

/*
 * The dialog system. Registers the Alpine store, the `$dialog` magic and the
 * `x-confirm` directive that replaces `wire:confirm` — see ./dialog.js for why
 * the native browser dialogs are gone.
 */
import './dialog'

/*
 * The global AI panel's open state and desktop gutter. Same shape as ./dialog:
 * an Alpine store, because the trigger in the top bar and the drawer at the end
 * of the body share no scope.
 */
import './ai-panel'

/*
 * The rich text editor, as the `richEditor` Alpine component. TipTap is the
 * only third-party runtime dependency the browser bundle has; see ./editor for
 * why it was chosen over the alternatives and how it stays out of Livewire's
 * way.
 */
import './editor'

/*
 * Which branches of the documentation tree are open. A store rather than local
 * state on each branch, because wire:navigate replaces the component on every
 * page opened and local state would not survive it — see ./doc-tree.
 */
import './doc-tree'

/*
 * The command palette and its ⌘K / Ctrl+K shortcut. A store, because the
 * shortcut is global while the trigger and the panel live in different corners
 * of the document — see ./palette.
 */
import './palette'

/*
 * Which appearance the application is wearing — light, dark, or whatever the
 * device says. A store, because the control is at the foot of the sidebar and
 * the element it changes is <html>, with every page in between replaced by
 * wire:navigate. The appearance itself is applied before this bundle loads, by
 * the inline script in the layout head; see ./theme.
 */
import './theme'

/*
 * Chart export. Turns an inline SVG chart into a downloadable SVG or PNG in
 * the browser; the CSV behind a chart comes from the server instead, so it is
 * re-derived under the viewer's own scope — see ./chart-export.
 */
import './chart-export'

/*
 * "On this page" for a documentation page, as the `docToc` Alpine component.
 * Reads the headings out of whatever is on screen — rendered document or live
 * editor — and assigns the anchors it links to; see ./doc-toc.
 */
import './doc-toc'

/*
 * The ticket conversation's scroll box, as the `commentThread` Alpine
 * component. Caps the thread to the last few messages and follows new ones,
 * but only while the reader has not scrolled back through the history — see
 * ./comment-thread.
 */
import './comment-thread'

/*
 * Spoken conversation with the assistant, as the `aiVoice` Alpine component.
 * Records a turn, hands it to the server to transcribe, asks the ordinary
 * assistant, and plays the answer back — see ./ai-voice for the state machine
 * and for why no vendor credential ever reaches the browser.
 */
import './ai-voice'
