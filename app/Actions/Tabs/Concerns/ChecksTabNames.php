<?php

declare(strict_types=1);

namespace App\Actions\Tabs\Concerns;

use App\Models\BoardTab;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The rules for a tab's name, enforced in the actions so they hold however
 * the action is reached (the board's tab strip, a test, a future screen) —
 * the Livewire form checks the same things first only to show a nicer error.
 *
 *  - trimmed, and not empty;
 *  - at most BoardTab::MAX_NAME_LENGTH characters;
 *  - unique on the board, ignoring case. MySQL's collation already treats
 *    "Backend" and "backend" as the same; checking here makes SQLite and the
 *    error message agree with it.
 */
trait ChecksTabNames
{
    private function cleanTabName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            throw new RuntimeException('Give the tab a name.');
        }

        if (mb_strlen($name) > BoardTab::MAX_NAME_LENGTH) {
            throw new RuntimeException('A tab name can be at most '.BoardTab::MAX_NAME_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * @param  Collection<int, BoardTab>  $tabs  the board's other tabs
     */
    private function assertTabNameFree(string $name, Collection $tabs): void
    {
        $wanted = mb_strtolower($name);

        if ($tabs->contains(fn (BoardTab $tab): bool => mb_strtolower((string) $tab->name) === $wanted)) {
            throw new RuntimeException('This board already has a tab called "'.$name.'".');
        }
    }

    private function duplicateTabName(string $name): RuntimeException
    {
        return new RuntimeException('This board already has a tab called "'.$name.'".');
    }
}
