<?php

declare(strict_types=1);

namespace App\Services\AI\Tools\Concerns;

use App\Models\Board;
use App\Models\Ticket;
use App\Services\AI\Tools\AiToolContext;
use App\Services\BoardAccess;
use App\Services\TicketFinder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turning a tool argument into a board or a ticket, safely.
 *
 * Shared by every tool, because getting this wrong once would be enough. Two
 * rules, both non-negotiable:
 *
 *   a board is only ever resolved through BoardAccess::query(), which is the
 *   same query the sidebar uses. So a board the model names is a board the
 *   *asking person* can already navigate to, and a slug they cannot reach is
 *   indistinguishable from one that does not exist;
 *
 *   a ticket is only ever resolved through TicketFinder, with the asking person
 *   as the viewer. Which means the customer boundary is applied by the same
 *   code that applies it on the ticket screen, and an internal ticket answers
 *   "no such ticket" rather than "not allowed".
 *
 * The board argument is optional everywhere. When the assistant is pointed at
 * one board that is the default, and a tool asked about a different board than
 * the one in context is answered about the board it was asked about — because
 * the person asking can reach both, and "which of my boards has the most
 * overdue work" is a reasonable question. The board scope is a focus, not a
 * fence; the fence is board membership.
 */
trait ResolvesWorkspaceSubjects
{
    /**
     * The board a tool call is about, or null.
     *
     * @param  array<string, mixed>  $input
     */
    protected function boardFrom(array $input, AiToolContext $context): ?Board
    {
        $slug = isset($input['board']) ? trim((string) $input['board']) : '';

        if ($slug === '') {
            return $context->board();
        }

        // A slug the person cannot reach resolves to nothing, which the caller
        // reports as "no such board". Falling back to the context board would
        // be worse than an error: the answer would be about a different client
        // than the question.
        return app(BoardAccess::class)->query($context->user)
            ->where('boards.slug', $slug)
            ->first();
    }

    /**
     * Every board this person can reach, for a cross-board lookup.
     *
     * @return Collection<int, Board>
     */
    protected function reachableBoards(AiToolContext $context, int $limit = 25)
    {
        return app(BoardAccess::class)->query($context->user)
            ->notArchived()
            ->orderBy('boards.name')
            ->limit($limit)
            ->get();
    }

    /**
     * A ticket named the way people name them: AQD-42, or 42 with a board.
     *
     * Returns null when it does not resolve, for any reason — wrong prefix,
     * wrong board, internal ticket, no such number. The caller reports one
     * answer for all of them.
     *
     * @param  array<string, mixed>  $input
     */
    protected function ticketFrom(array $input, AiToolContext $context): ?Ticket
    {
        $reference = isset($input['ticket']) ? trim((string) $input['ticket']) : '';

        if ($reference === '') {
            return null;
        }

        $tickets = app(TicketFinder::class);

        /*
         * A prefixed key names its own board, so it is resolved without one.
         *
         * This is what makes the workspace scope useful: somebody on the
         * dashboard can ask about NL-123 without first selecting a board, and
         * the prefix is unambiguous across the workspace because
         * `boards.ticket_prefix` is unique.
         */
        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,9})-(\d{1,9})$/', $reference, $matches) === 1) {
            $board = app(BoardAccess::class)->query($context->user)
                ->where('boards.ticket_prefix', strtoupper($matches[1]))
                ->first();

            if (! $board instanceof Board) {
                return null;
            }

            return $this->findTicket($board, (int) $matches[2], $context);
        }

        if (! ctype_digit($reference)) {
            return null;
        }

        $board = $this->boardFrom($input, $context);

        if (! $board instanceof Board) {
            return null;
        }

        return $this->findTicket($board, (int) $reference, $context);
    }

    private function findTicket(Board $board, int $number, AiToolContext $context): ?Ticket
    {
        try {
            return app(TicketFinder::class)->findOrFail($board, $number, $context->user);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    /**
     * A body, flattened and bounded.
     *
     * Newlines are kept — a description with its structure intact reads far
     * better to a model than one collapsed into a paragraph — but the length is
     * not negotiable: a tool that can return a whole document can spend a
     * context window on one call.
     */
    protected function excerpt(?string $text, int $limit = 1200): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        // Runs of blank lines collapse to one. Markdown written in a browser
        // accumulates them and they cost tokens without carrying meaning.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text));

        return Str::limit($text, $limit, ' […truncated]');
    }

    /**
     * One line describing a ticket, in the vocabulary the team uses.
     */
    protected function ticketLine(Ticket $ticket): string
    {
        $ticket->loadMissing(['board', 'column', 'assignee']);

        $parts = [
            $ticket->key(),
            '['.($ticket->column?->name ?? 'no column').']',
            Str::limit((string) $ticket->title, 140, '…'),
        ];

        $line = implode(' ', $parts);

        if ($ticket->assignee !== null) {
            $line .= ' · assignee '.$ticket->assignee->name;
        }

        if ($ticket->priority !== null) {
            $line .= ' · '.$ticket->priority->label();
        }

        if ($ticket->due_date !== null) {
            $line .= ' · due '.$ticket->due_date->toDateString();
        }

        // Only ever reached for staff: a customer's query cannot return an
        // internal ticket in the first place, because TicketFinder filtered it
        // out before this line was built.
        if (! $ticket->customer_visible) {
            $line .= ' · internal';
        }

        return $line;
    }
}
