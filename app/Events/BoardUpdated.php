<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Something on this board changed. Ask the server what it is."
 *
 * The payload is a timestamp and nothing else. That is the central design
 * decision of the realtime layer, and it is a security one: a message that
 * carries no ticket title, no id, no column and no author cannot leak any of
 * them, no matter who is listening or how the channel authorization is later
 * changed. Clients react by re-rendering the Livewire component, which re-runs
 * the same authorized, scoped query it used on first paint — so what a viewer
 * ends up seeing is decided by the same code as always, not by what arrived
 * over the websocket.
 *
 * The cost is one extra round trip per change; the benefit is that realtime can
 * never become a second, weaker copy of the visibility rules.
 *
 * Two audiences, two channels
 * ---------------------------
 * `internal` reaches staff, `customer` reaches everyone on the board. An
 * internal ticket changing signals only the internal channel: waking a
 * customer's browser every time the team touches something they cannot see
 * would let them infer internal activity from the timing alone.
 *
 * Queued rather than sent inline (ShouldBroadcast, not ShouldBroadcastNow) so a
 * websocket server that is down or slow cannot fail or delay the write that
 * triggered it.
 */
class BoardUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const AUDIENCE_INTERNAL = 'internal';

    public const AUDIENCE_CUSTOMER = 'customer';

    public function __construct(
        public readonly int $boardId,
        public readonly string $audience,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId.'.'.$this->audience)];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    /**
     * Deliberately empty of content. See the class comment.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['at' => now()->toIso8601String()];
    }
}
