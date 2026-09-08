<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One row in the command palette.
 *
 * Flat and already rendered — a label, a hint, a URL — rather than a model.
 * Two reasons, and the second is the one that matters:
 *
 *   the palette renders four kinds of thing in one list, and a template that
 *   branches on which model it is holding is a template that eventually
 *   renders one of them with the wrong rules;
 *
 *   whatever reaches here has already passed its own visibility scope in
 *   App\Services\Search\GlobalSearch. Handing the view a Ticket would let it
 *   reach for `$ticket->comments` or `$ticket->aiRuns` — relations with their
 *   own rules that nothing on this screen has applied.
 *
 * So the service decides what may be said about a hit, and the view says only
 * that.
 */
final class SearchHit
{
    public function __construct(
        /** Short monospace identifier, or null. A ticket key, a board prefix. */
        public readonly ?string $key,
        public readonly string $label,
        /** Context under the label: the board, the parent page, a role. */
        public readonly ?string $hint,
        /** Where Enter goes. Null for a hit with nowhere to open. */
        public readonly ?string $url,
        /** Distinguishes rows in wire:key. */
        public readonly string $id,
    ) {}
}
