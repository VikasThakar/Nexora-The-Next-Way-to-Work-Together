<?php

declare(strict_types=1);

namespace App\Livewire\Search;

use App\Services\Search\GlobalSearch;
use Livewire\Component;

/**
 * The command palette, mounted once in the application shell.
 *
 * Holds one property. Whether the palette is open is not server state — it is
 * an Alpine store (resources/js/palette.js), because opening it must happen on
 * the keystroke rather than after a round trip, and because the server has no
 * use for the answer.
 *
 * Everything the palette shows comes from App\Services\Search\GlobalSearch,
 * which applies each category's own visibility scope. Nothing is filtered in
 * the browser: the only rows that ever reach it are rows this viewer could
 * already open by URL.
 *
 * The term is debounced in the template rather than here. A palette that
 * queried on every keystroke would be four table scans per character.
 */
class Palette extends Component
{
    /**
     * What is in the box.
     *
     * Attacker-controlled, like any Livewire property, and it cannot widen
     * anything: GlobalSearch bounds its length and every query it feeds runs
     * behind a visibility scope.
     */
    public string $term = '';

    /**
     * Empty the box without closing the palette.
     */
    public function clear(): void
    {
        $this->term = '';
    }

    public function render(GlobalSearch $search)
    {
        $user = auth()->user();
        $term = $search->normalise($this->term);

        return view('livewire.search.palette', [
            'groups' => $user === null || $term === null ? [] : $search->search($user, $term),

            // Told rather than left silent: a box that does nothing for one
            // character looks broken.
            'tooShort' => $term === null && trim($this->term) !== '',
            'minLength' => GlobalSearch::MIN_LENGTH,
            'searching' => $term !== null,
        ]);
    }
}
