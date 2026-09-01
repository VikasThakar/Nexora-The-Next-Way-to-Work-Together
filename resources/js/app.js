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
