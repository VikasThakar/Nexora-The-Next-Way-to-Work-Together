<?php

declare(strict_types=1);

namespace App\Actions\Boards;

use App\Events\BoardMemberRemoved;
use App\Models\Board;
use App\Models\BoardMember;
use App\Models\User;
use App\Services\BoardAccess;

class RemoveBoardMember
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * Revoke a user access to a board.
     *
     * Returns true when a membership actually existed, so callers can decide
     * whether to report a change.
     */
    public function handle(Board $board, User|int $user, ?User $removedBy = null): bool
    {
        $member = $user instanceof User ? $user : User::query()->findOrFail($user);

        $deleted = BoardMember::query()
            ->where('board_id', $board->getKey())
            ->where('user_id', $member->getKey())
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        $board->unsetRelation('members')->unsetRelation('memberships');
        $this->access->flush($member);

        BoardMemberRemoved::dispatch($board, $member, $removedBy);

        return true;
    }
}
