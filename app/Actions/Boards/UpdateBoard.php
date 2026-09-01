<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Models\Board;
use App\Support\BoardIdentifiers;

class UpdateBoard
{
    public function __construct(private readonly BoardIdentifiers $identifiers) {}

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

        $board->save();

        return $board;
    }
}
