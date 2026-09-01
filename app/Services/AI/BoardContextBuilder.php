<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use App\Models\BoardRepository;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\DocPageFinder;
use App\Services\TicketFinder;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything the workspace chat is allowed to know, for one board, for one user.
 *
 * This class is the security boundary of the chat, and the design decision that
 * makes it one is that it holds no rules of its own. Every read goes through the
 * reader that already governs that kind of content, with the *asking user* as
 * the viewer:
 *
 *   tickets         TicketFinder      board membership, and customer_visible
 *   documentation   DocPageFinder     board membership, and every ancestor
 *   repositories    BoardRepository::visibleTo   staff only
 *   activity        TicketEvent::readableBy      drops internal-only events
 *
 * So the model receives exactly what the person asking could have read by
 * clicking around the product, and not one row more. There is no second
 * definition of visibility here to go stale, and a future change to the
 * customer boundary applies to the chat automatically.
 *
 * Two further limits, both deliberate:
 *
 *   one board only  nothing here takes a list of boards. A question asked on
 *                   board A cannot pull in board B, even for an administrator
 *                   who can see both, because leaking one client's roadmap into
 *                   another client's board is the worst failure available.
 *   no secrets      configuration, environment variables and credentials are
 *                   never part of the context. The repository section carries
 *                   names and hints from `board_repositories.configuration`,
 *                   which is a field for "the test command is composer test",
 *                   not for tokens.
 */
class BoardContextBuilder
{
    public function __construct(
        private readonly TicketFinder $tickets,
        private readonly DocPageFinder $pages,
        private readonly RepositoryContext $repositoryContext,
    ) {}

    /**
     * The whole context block for one question.
     */
    public function build(Board $board, ?Authenticatable $viewer): string
    {
        $sections = array_filter([
            $this->ticketSection($board, $viewer),
            $this->activitySection($board, $viewer),
            $this->documentationSection($board, $viewer),
            $this->repositorySection($board, $viewer),
        ]);

        if ($sections === []) {
            return "BOARD CONTEXT\nThis board has no tickets, documentation or repositories you can see.";
        }

        return "BOARD CONTEXT (everything below is what the person asking is allowed to see)\n\n"
            .implode("\n\n", $sections);
    }

    // -----------------------------------------------------------------

    private function ticketSection(Board $board, ?Authenticatable $viewer): ?string
    {
        $limit = max(1, (int) config('ai.chat.context.tickets', 60));

        $tickets = $this->tickets->query($board, $viewer)
            ->with(['board', 'column', 'assignee', 'labels'])
            ->orderByDesc('tickets.updated_at')
            ->limit($limit)
            ->get();

        if ($tickets->isEmpty()) {
            return null;
        }

        $lines = ['TICKETS ('.$tickets->count().' most recently updated)'];

        foreach ($tickets as $ticket) {
            /** @var Ticket $ticket */
            $lines[] = sprintf(
                '- %s [%s] %s%s%s%s',
                $ticket->key(),
                $ticket->column?->name ?? 'no column',
                $ticket->title,
                $ticket->assignee !== null ? ' · assignee '.$ticket->assignee->name : '',
                $ticket->labels->isNotEmpty() ? ' · labels '.$ticket->labels->pluck('name')->implode(', ') : '',
                $ticket->customer_visible ? '' : ' · internal',
            );

            if (filled($ticket->description_md)) {
                $lines[] = '  '.str_replace(
                    ["\r\n", "\n"],
                    ' ',
                    mb_substr(trim((string) $ticket->description_md), 0, 400)
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Recent activity across the board.
     *
     * Read through TicketEvent::readableBy(), which drops the event types a
     * customer must not see — so even if a customer ever reached this screen,
     * the visibility-change history would not come with it.
     */
    private function activitySection(Board $board, ?Authenticatable $viewer): ?string
    {
        $limit = max(1, (int) config('ai.chat.context.activity', 40));

        $events = TicketEvent::query()
            ->readableBy($viewer)
            ->forBoard($board)
            ->with(['actor', 'ticket'])
            ->orderByDesc('ticket_events.created_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $lines = ['RECENT ACTIVITY'];

        foreach ($events as $event) {
            $lines[] = sprintf(
                '- %s: %s %s%s',
                $event->created_at?->toDateString() ?? '',
                $event->actor?->name ?? 'The workspace',
                $event->type->label(),
                $event->ticket !== null ? ' on ticket #'.$event->ticket->number : '',
            );
        }

        return implode("\n", $lines);
    }

    private function documentationSection(Board $board, ?Authenticatable $viewer): ?string
    {
        $limit = max(1, (int) config('ai.chat.context.doc_pages', 40));

        $pages = $this->pages->query($board, $viewer)
            ->orderByDesc('doc_pages.updated_at')
            ->limit($limit)
            ->get();

        // The ancestor rule cannot be expressed as a single scope, so it is
        // applied here through the one method that owns it. A page published
        // under an internal parent is dropped, exactly as it is in the sidebar.
        $pages = $pages->filter(
            fn (DocPage $page): bool => $this->pages->isVisible($page, $viewer)
        );

        if ($pages->isEmpty()) {
            return null;
        }

        $lines = ['DOCUMENTATION'];

        foreach ($pages as $page) {
            $lines[] = '- '.$page->title.' (slug: '.$page->slug.')'
                .($page->customer_visible ? '' : ' · internal');

            if (filled($page->body_md)) {
                $lines[] = '  '.str_replace(
                    ["\r\n", "\n"],
                    ' ',
                    mb_substr(trim((string) $page->body_md), 0, 600)
                );
            }
        }

        return implode("\n", $lines);
    }

    private function repositorySection(Board $board, ?Authenticatable $viewer): ?string
    {
        // Staff only, enforced by the model's own scope rather than by an
        // isStaff() check here — the chat is staff-only anyway, and having one
        // definition means the two cannot disagree.
        $repositories = BoardRepository::query()
            ->visibleTo($viewer)
            ->forBoard($board)
            ->ordered()
            ->get();

        if ($repositories->isEmpty()) {
            return null;
        }

        $blocks = ['REPOSITORIES'];

        foreach ($repositories as $repository) {
            $blocks[] = ($repository->is_primary ? '(primary) ' : '')
                .$this->repositoryContext->metadata($repository);
        }

        return implode("\n\n", $blocks);
    }
}
