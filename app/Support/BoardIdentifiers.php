<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Board;
use Illuminate\Support\Str;

/**
 * Derives the two human-facing identifiers a board needs: its URL slug and its
 * ticket prefix.
 *
 * Both must be unique, and both are proposed by the UI before the board is
 * saved, so the logic lives here rather than in a model observer.
 *
 * The uniqueness scope is global today. When an organization layer is added,
 * only the two `exists` closures below need to become organization-aware.
 */
class BoardIdentifiers
{
    /**
     * Build a unique slug for a board name, appending -2, -3, … on collision.
     */
    public function slug(string $name, ?int $ignoreBoardId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'board';
        }

        $base = Str::limit($base, 60, '');
        $candidate = $base;
        $suffix = 1;

        while ($this->slugTaken($candidate, $ignoreBoardId)) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * Build a unique ticket prefix, e.g. "Aqueduct Platform" becomes "AP",
     * "Website" becomes "WEB". Collisions get a numeric suffix (WEB2).
     */
    public function ticketPrefix(string $name, ?int $ignoreBoardId = null): string
    {
        $max = (int) config('workspace.ticket_prefix.max_length', 6);
        $min = (int) config('workspace.ticket_prefix.min_length', 2);

        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $base = count($words) > 1
            ? collect($words)->take($max)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')
            : mb_substr($words[0] ?? 'BRD', 0, 3);

        $base = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $base) ?? '');

        if (mb_strlen($base) < $min) {
            $base = str_pad($base === '' ? 'BRD' : $base, $min, 'X');
        }

        $base = mb_substr($base, 0, $max);
        $candidate = $base;
        $suffix = 1;

        while ($this->prefixTaken($candidate, $ignoreBoardId)) {
            $suffix++;
            $candidate = mb_substr($base, 0, max(1, $max - mb_strlen((string) $suffix))).$suffix;
        }

        return $candidate;
    }

    private function slugTaken(string $slug, ?int $ignoreBoardId): bool
    {
        return Board::query()
            ->where('slug', $slug)
            ->when($ignoreBoardId, fn ($query) => $query->whereKeyNot($ignoreBoardId))
            ->exists();
    }

    private function prefixTaken(string $prefix, ?int $ignoreBoardId): bool
    {
        return Board::query()
            ->where('ticket_prefix', $prefix)
            ->when($ignoreBoardId, fn ($query) => $query->whereKeyNot($ignoreBoardId))
            ->exists();
    }
}
