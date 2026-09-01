<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\Broadcasting;
use Illuminate\Support\Facades\Auth;

/**
 * Subscribes a component to the realtime channel for its audience.
 *
 * Which channel a component listens on is decided here, from the viewer's own
 * role, and never from anything the browser sent. Staff listen on the internal
 * channel, everyone else on the customer channel — and the websocket server
 * will refuse the subscription anyway if that does not match what
 * routes/channels.php says, so a tampered client gains nothing.
 *
 * The handler is `$refresh`. There is no payload to read: the component simply
 * re-renders, running the same authorized, scoped queries it ran on first
 * paint. That is what makes it impossible for the realtime layer to show
 * somebody something the page itself would not have.
 */
trait ListensForBoardUpdates
{
    /**
     * @return array<string, string>
     */
    protected function boardUpdateListeners(?int $boardId): array
    {
        if ($boardId === null || ! Broadcasting::enabled()) {
            return [];
        }

        $user = Auth::user();

        $audience = $user !== null && $user->canSeeInternalContent()
            ? 'internal'
            : 'customer';

        // The internal channel receives every change on the board, including
        // customer-visible ones, so staff need only this one subscription.
        return [
            'echo-private:board.'.$boardId.'.'.$audience.',.board.updated' => '$refresh',
        ];
    }
}
