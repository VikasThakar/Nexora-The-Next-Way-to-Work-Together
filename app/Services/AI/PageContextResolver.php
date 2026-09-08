<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use App\Services\BoardAccess;
use App\Services\DocPageFinder;
use App\Services\TicketFinder;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "The person asking is currently looking at NL-123."
 *
 * The assistant panel lives in the layout and survives wire:navigate, so it is
 * not re-rendered when the page changes and `request()->route()` inside it
 * describes wherever the panel first mounted. The current page therefore has to
 * arrive from the browser, which makes it untrusted input.
 *
 * This class is the boundary that makes it safe, and the rule is simple: the
 * browser supplies a *hint*, and every part of it is re-resolved server-side
 * through the reader that already governs that kind of thing —
 *
 *   board      BoardAccess::query()        board membership
 *   ticket     TicketFinder::findOrFail()  membership and the customer boundary
 *   doc page   DocPageFinder::findOrFail() membership and the ancestor rule
 *
 * A hint that does not resolve is dropped silently and the answer is simply
 * built without page context. Nothing is trusted, nothing is echoed back, and a
 * forged hint therefore buys nothing: naming a ticket you cannot open produces
 * the same empty result as naming one that does not exist.
 *
 * Size
 * ----
 * One line. The point of page context is to disambiguate "why is this blocked?"
 * — not to re-send the ticket, which the board context already carries in full.
 * Keeping it to a title is what stops this becoming a second, unbudgeted
 * context builder.
 */
class PageContextResolver
{
    public function __construct(
        private readonly BoardAccess $access,
        private readonly TicketFinder $tickets,
        private readonly DocPageFinder $pages,
    ) {}

    /**
     * Turn a browser hint into one authorized line, or nothing.
     *
     * @param  array<string, mixed>  $hint  as sent by the panel: board slug, and
     *                                      optionally a ticket number or a doc
     *                                      page slug
     */
    public function describe(array $hint, ?Authenticatable $viewer): ?string
    {
        $board = $this->resolveBoard($hint['board'] ?? null, $viewer);

        if (! $board instanceof Board) {
            return null;
        }

        if (($number = $this->positiveInt($hint['ticket'] ?? null)) !== null) {
            return $this->describeTicket($board, $number, $viewer);
        }

        if (($slug = $this->slug($hint['page'] ?? null)) !== null) {
            return $this->describePage($board, $slug, $viewer);
        }

        return 'The person asking is currently looking at the '.$board->name.' board.';
    }

    /**
     * The board named by a hint, if the viewer may reach it.
     *
     * Resolved through BoardAccess rather than by slug alone, so this doubles as
     * the authorization check. The panel uses the same method to decide whether
     * the hint may also pre-select the context selector.
     */
    public function resolveBoard(mixed $slug, ?Authenticatable $viewer): ?Board
    {
        $slug = $this->slug($slug);

        if ($slug === null) {
            return null;
        }

        return $this->access->query($viewer)->where('boards.slug', $slug)->first();
    }

    // -----------------------------------------------------------------

    private function describeTicket(Board $board, int $number, ?Authenticatable $viewer): ?string
    {
        try {
            $ticket = $this->tickets->findOrFail($board, $number, $viewer);
        } catch (NotFoundHttpException) {
            // Either it does not exist or this person may not open it. The two
            // are deliberately indistinguishable here, as they are everywhere
            // else in the product.
            return null;
        }

        return sprintf(
            'The person asking is currently looking at ticket %s ("%s") on the %s board.',
            $ticket->key(),
            mb_substr($ticket->title, 0, 160),
            $board->name,
        );
    }

    private function describePage(Board $board, string $slug, ?Authenticatable $viewer): ?string
    {
        try {
            $page = $this->pages->findOrFail($board, $slug, $viewer);
        } catch (NotFoundHttpException) {
            return null;
        }

        return sprintf(
            'The person asking is currently reading the documentation page "%s" on the %s board.',
            mb_substr($page->title, 0, 160),
            $board->name,
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    /**
     * A slug shaped like one, or nothing.
     *
     * Bounded and character-checked before it reaches a query. The query binds
     * its parameters regardless, so this is about refusing obvious nonsense
     * early rather than about injection.
     */
    private function slug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 220) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1 ? $value : null;
    }
}
