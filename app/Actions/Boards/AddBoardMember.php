<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Events\BoardMemberAdded;
use App\Models\Board;
use App\Models\BoardMember;
use App\Models\User;
use App\Services\BoardAccess;

class AddBoardMember
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * Grant a user access to a board.
     *
     * Idempotent: re-adding an existing member returns the existing row rather
     * than violating the unique constraint.
     */
    public function handle(Board $board, User|int $user, ?User $addedBy = null): BoardMember
    {
        $member = $user instanceof User ? $user : User::query()->findOrFail($user);

        $membership = BoardMember::query()->firstOrCreate(
            [
                'board_id' => $board->getKey(),
                'user_id' => $member->getKey(),
            ],
            [
                'added_by_id' => $addedBy?->getKey(),
            ]
        );

        if ($membership->wasRecentlyCreated) {
            $board->unsetRelation('members')->unsetRelation('memberships');
            $this->access->flush($member);

            BoardMemberAdded::dispatch($board, $member, $membership, $addedBy);
        }

        return $membership;
    }
}
