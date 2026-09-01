<?php

declare(strict_types=1);

use App\Models\Board;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Every channel in this product is private. There is no public channel and no
| presence channel: joining one is a request Laravel authorizes here, per user,
| before the websocket server will deliver anything at all.
|
| The board channels are split by audience, and that split is the point.
|
|   board.{id}.internal   the delivery team's channel. Staff members of the
|                         board only.
|   board.{id}.customer   the channel everyone on the board may join.
|
| A customer can subscribe to the second and is refused the first. Because
| App\Events\BoardUpdated only signals the internal channel when an internal
| ticket changes, a customer's browser is never even woken by activity they are
| not allowed to know about — which closes the timing side channel that a single
| shared channel would leave open, even with an empty payload.
|
| Both callbacks delegate to BoardAccess. Membership and the customer boundary
| are not restated here; this file decides which existing question to ask.
|
| Note the shape of the callbacks: Laravel only reaches them for an
| authenticated user, and a return of false is a refusal. Nothing here returns
| information about the board itself, so a failed subscription tells the caller
| only that it failed.
*/

Broadcast::channel('board.{boardId}.internal', function (User $user, string $boardId): bool {
    $board = Board::query()->find((int) $boardId);

    if (! $board instanceof Board) {
        return false;
    }

    $access = app(BoardAccess::class);

    // Both halves are required. Membership alone is not enough: a customer is
    // a member of their board and must still never join this channel.
    return $access->canView($user, $board) && $access->canSeeInternalContent($user);
});

Broadcast::channel('board.{boardId}.customer', function (User $user, string $boardId): bool {
    $board = Board::query()->find((int) $boardId);

    if (! $board instanceof Board) {
        return false;
    }

    return app(BoardAccess::class)->canView($user, $board);
});

/*
| One person's own notification channel. Used only to nudge the bell into
| re-reading; nothing about the notification travels over it.
*/
Broadcast::channel('users.{userId}', function (User $user, string $userId): bool {
    return $user->isActive() && (int) $user->getKey() === (int) $userId;
});
