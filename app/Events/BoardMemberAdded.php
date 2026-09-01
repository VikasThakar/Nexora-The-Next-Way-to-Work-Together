<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Board;
use App\Models\BoardMember;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a user gains access to a board.
 *
 * Later phases will listen to this to send the invitation email and to warm
 * per-user caches. Keeping it here means membership changes always emit a
 * signal, regardless of which screen triggered them.
 */
class BoardMemberAdded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Board $board,
        public readonly User $member,
        public readonly BoardMember $membership,
        public readonly ?User $addedBy = null,
    ) {}
}
