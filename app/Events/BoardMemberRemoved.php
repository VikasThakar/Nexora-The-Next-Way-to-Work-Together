<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a user loses access to a board.
 *
 * Security-relevant: later phases should listen here to revoke cached
 * artefacts (signed file URLs, exported reports) that were granted while the
 * membership existed.
 */
class BoardMemberRemoved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Board $board,
        public readonly User $member,
        public readonly ?User $removedBy = null,
    ) {}
}
