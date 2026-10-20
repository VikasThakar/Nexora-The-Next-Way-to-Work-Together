<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which AI app, if any, a change in this request is being made through.
 *
 * A change made by an AI app over the remote MCP server is still the
 * person's change — they connected the app, approved the call, and their
 * permissions decided it — so the history records them as the actor, exactly
 * as it does for a confirmed in-app proposal. What it adds is "via Claude":
 * the one fact a colleague reading the history wants and the actor column
 * cannot say.
 *
 * Request-scoped (registered with `scoped()`), and set only for the duration
 * of one write by App\Mcp\Tools\Write\WriteTool. TicketActivity and
 * ActivityLogger read it when they write a row, so every history entry a
 * write produces carries it — the ticket's own, the comment's, the page's —
 * without any action having to pass it along.
 */
final class WriteOrigin
{
    private ?string $via = null;

    /**
     * The app's name while a write through it is in progress, else null.
     */
    public function via(): ?string
    {
        return $this->via;
    }

    /**
     * Run $callback with $via recorded as the origin of whatever it writes.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function through(string $via, callable $callback): mixed
    {
        $previous = $this->via;
        $this->via = $via;

        try {
            return $callback();
        } finally {
            $this->via = $previous;
        }
    }
}
