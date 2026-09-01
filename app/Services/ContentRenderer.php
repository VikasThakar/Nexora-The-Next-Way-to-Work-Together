<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommentStream;
use App\Models\Board;
use App\Support\Markdown;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Renders every piece of user-written prose in the product.
 *
 * Ticket descriptions, comments and documentation all pass through here, so
 * the pipeline is identical everywhere:
 *
 *   1. Markdown to HTML, with raw HTML stripped and unsafe link schemes
 *      rejected (App\Support\Markdown).
 *   2. @mentions of board members highlighted.
 *   3. Ticket references such as AQD-142 linked, but only for tickets this
 *      particular reader may open.
 *
 * Step 1 is viewer-independent and memoised. Steps 2 and 3 are not: the same
 * stored text renders differently for a customer than for the delivery team,
 * which is the whole point. Nothing here may be cached across viewers.
 */
class ContentRenderer
{
    public function __construct(
        private readonly Markdown $markdown,
        private readonly MentionParser $mentions,
        private readonly TicketReferenceLinker $ticketReferences,
    ) {}

    /**
     * @param  Board|null  $board  the board whose members may be mentioned;
     *                             omit to skip mention highlighting
     */
    public function render(
        ?string $body,
        ?Authenticatable $viewer,
        ?Board $board = null,
        CommentStream|bool $mentionScope = true,
    ): string {
        $html = $this->markdown->toHtml($body);

        if ($html === '') {
            return '';
        }

        if ($board instanceof Board) {
            $html = $this->mentions->highlight($html, $this->mentions->candidates($board, $mentionScope));
        }

        return $this->ticketReferences->link($html, $viewer);
    }
}
