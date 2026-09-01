<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after a board and its initial membership have been committed.
 *
 * Nothing listens to this in Phase 1. It exists because board lifecycle is
 * where later phases hang work (seeding default columns, provisioning a
 * documentation space, notifying members) and adding the event now keeps that
 * work out of the action itself.
 */
class BoardCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Board $board,
        public readonly ?User $createdBy = null,
    ) {}
}
