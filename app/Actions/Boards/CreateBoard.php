<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Events\BoardCreated;
use App\Models\Board;
use App\Models\User;
use App\Support\BoardIdentifiers;
use Illuminate\Support\Facades\DB;

/**
 * Create a board and its initial membership in one transaction.
 *
 * Authorization is the caller responsibility (BoardPolicy::create); actions
 * deliberately do not check gates so they remain usable from seeders, console
 * commands and queued jobs where there is no authenticated user.
 */
class CreateBoard
{
    public function __construct(
        private readonly BoardIdentifiers $identifiers,
        private readonly AddBoardMember $addMember,
        private readonly CreateDefaultColumns $createDefaultColumns,
    ) {}

    /**
     * @param  array{name: string, slug?: ?string, ticket_prefix?: ?string, description?: ?string, settings?: array<string, mixed>}  $attributes
     * @param  array<int, int>  $memberIds
     */
    public function handle(array $attributes, ?User $creator = null, array $memberIds = []): Board
    {
        return DB::transaction(function () use ($attributes, $creator, $memberIds): Board {
            $name = trim($attributes['name']);

            $board = new Board([
                'name' => $name,
                'slug' => $this->identifiers->slug($attributes['slug'] ?? $name),
                'ticket_prefix' => isset($attributes['ticket_prefix']) && $attributes['ticket_prefix'] !== null && $attributes['ticket_prefix'] !== ''
                    ? mb_strtoupper($attributes['ticket_prefix'])
                    : $this->identifiers->ticketPrefix($name),
                'description' => $attributes['description'] ?? null,
                'settings' => $attributes['settings'] ?? [],
            ]);

            $board->created_by_id = $creator?->getKey();
            $board->save();

            // A board with no columns cannot hold a ticket, so the default
            // workflow is created in the same transaction rather than left for
            // the first person who opens the board.
            $this->createDefaultColumns->handle($board);

            // Cast rather than trusting the caller to have done it. This action
            // is also reached from seeders, console commands and imports, where
            // ids commonly arrive as numeric strings, and AddBoardMember asks
            // for an int under strict_types.
            $ids = array_unique(array_map('intval', $memberIds));

            foreach ($ids as $memberId) {
                $this->addMember->handle($board, $memberId, $creator);
            }

            BoardCreated::dispatch($board, $creator);

            return $board->refresh();
        });
    }
}
