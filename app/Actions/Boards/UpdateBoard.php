<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Models\Board;
use App\Services\ActivityLogger;
use App\Support\BoardIdentifiers;

/**
 * Edit a board.
 *
 * The diff is taken from Eloquent's own dirty tracking, immediately before the
 * save, and handed to App\Services\ActivityLogger. That is why the activity is
 * recorded here rather than by a listener on a board event: an event carrying
 * only the board cannot say what the name was before, and "renamed the board
 * Platform to Delivery" is the whole value of the entry. An edit that changes
 * nothing records nothing, so a form resubmitted unchanged stays out of the
 * feed.
 *
 * The one thing deliberately not recorded is the *content* of `settings`. A
 * board's settings hold an encrypted Slack webhook URL and a list of SMS
 * recipients; which group of settings somebody touched is worth knowing, and
 * the values are not something an activity feed should be keeping a second copy
 * of. See ActivityLogger::boardChanged().
 */
class UpdateBoard
{
    public function __construct(
        private readonly BoardIdentifiers $identifiers,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{name?: string, slug?: ?string, ticket_prefix?: ?string, description?: ?string, settings?: array<string, mixed>, archived?: bool}  $attributes
     */
    public function handle(Board $board, array $attributes): Board
    {
        if (array_key_exists('name', $attributes)) {
            $board->name = trim($attributes['name']);
        }

        if (array_key_exists('slug', $attributes) && filled($attributes['slug'])) {
            $board->slug = $this->identifiers->slug($attributes['slug'], $board->getKey());
        }

        if (array_key_exists('ticket_prefix', $attributes) && filled($attributes['ticket_prefix'])) {
            $board->ticket_prefix = mb_strtoupper($attributes['ticket_prefix']);
        }

        if (array_key_exists('description', $attributes)) {
            $board->description = $attributes['description'];
        }

        if (array_key_exists('settings', $attributes)) {
            // Merge rather than replace so a screen that only knows about some
            // settings cannot silently drop the others.
            $board->settings = array_merge($board->settings ?? [], $attributes['settings']);
        }

        if (array_key_exists('archived', $attributes)) {
            $board->archived_at = $attributes['archived'] ? ($board->archived_at ?? now()) : null;
        }

        // Read before the save, while Eloquent still knows what changed.
        $changes = $this->diff($board);

        $board->save();

        if ($changes !== []) {
            $this->activity->boardChanged($board, $changes);
        }

        return $board;
    }

    /**
     * field => [from, to], for every attribute this save will write.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(Board $board): array
    {
        $changes = [];

        foreach (array_keys($board->getDirty()) as $field) {
            $changes[$field] = [
                $board->getOriginal($field),
                $board->getAttribute($field),
            ];
        }

        return $changes;
    }
}
