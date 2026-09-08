<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Board;
use App\Models\BoardMember;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BoardAccess;
use App\Services\DocPageFinder;
use App\Support\SearchHit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Everything the command palette can find.
 *
 *
 * Why no search engine
 * --------------------
 * Scout with Meilisearch or Algolia, and certainly Elasticsearch, would each
 * add a service to run, a queue to keep up, an index to reconcile after every
 * write — and, the part that is easy to miss, a second copy of every ticket
 * description living outside this application's visibility rules. An index has
 * no idea that a ticket is internal, so the filtering has to be reproduced in
 * the engine's own query language, correctly, forever.
 *
 * What this workspace actually holds is thousands of rows, not millions. Four
 * `LIKE` queries with a `LIMIT` answer in single-digit milliseconds and every
 * one of them runs through the scope that already protects the screen the row
 * came from. There is no second copy of anything and nothing to reconcile.
 *
 * Where the honest limit is: `LIKE '%term%'` cannot use a B-tree index, so
 * these are table scans, bounded by the number of rows a viewer may see. That
 * is fine into the low hundreds of thousands of tickets on MySQL. Past that the
 * next step is a FULLTEXT index on `tickets.title` plus `description_md` (and a
 * MATCH…AGAINST branch here), which stays inside MySQL and needs no new
 * service. Scout is the step after that, and this workspace is two orders of
 * magnitude away from needing it.
 *
 *
 * Authorization
 * -------------
 * No query here invents a rule. Each category goes through the reader that
 * already owns its visibility:
 *
 *   tickets    Ticket::visibleTo(), the board scope plus the customer flag.
 *   documents  DocPageFinder, because a page needs the ancestor rule as well
 *              as the flag, and that rule has exactly one implementation.
 *   boards     BoardAccess::query().
 *   people     staff only, and narrowed to people the viewer shares a board
 *              with. See people() for why that is not the same as "everyone".
 *
 * A hit therefore cannot exist for a record the viewer could not already open
 * by URL, and the palette can add no category without coming through here.
 */
class GlobalSearch
{
    /**
     * How many hits each category contributes.
     *
     * Small on purpose. A palette is for getting somewhere, not for browsing
     * results, and every row costs a line of screen on a phone. The screens
     * that do browse — the board, the documentation tree, the user list — have
     * their own filters.
     */
    public const PER_GROUP = 5;

    /**
     * Shorter than this searches nothing.
     *
     * One character matches most of the workspace, which is slow and useless
     * at the same time. The palette says so rather than silently doing nothing.
     */
    public const MIN_LENGTH = 2;

    public function __construct(
        private readonly BoardAccess $access,
        private readonly DocPageFinder $pages,
    ) {}

    /**
     * Every group with at least one hit, in the order the palette lists them.
     *
     * @return array<int, array{key: string, label: string, hits: array<int, SearchHit>}>
     */
    public function search(?Authenticatable $user, string $term): array
    {
        $term = $this->normalise($term);

        if ($term === null) {
            return [];
        }

        $groups = [
            ['key' => 'tickets', 'label' => 'Tickets', 'hits' => $this->tickets($user, $term)],
            ['key' => 'documents', 'label' => 'Documentation', 'hits' => $this->documents($user, $term)],
            ['key' => 'boards', 'label' => 'Boards', 'hits' => $this->boards($user, $term)],
            ['key' => 'people', 'label' => 'People', 'hits' => $this->people($user, $term)],
        ];

        return array_values(array_filter($groups, static fn (array $group): bool => $group['hits'] !== []));
    }

    /**
     * A search term, or null when there is nothing worth running.
     */
    public function normalise(?string $term): ?string
    {
        $term = trim(preg_replace('/\s+/u', ' ', (string) $term) ?? '');

        // Bounded before it reaches a LIKE. A megabyte pasted into the box is
        // not a search, and building the pattern from it would cost more than
        // the query.
        $term = mb_substr($term, 0, 120);

        return mb_strlen($term) < self::MIN_LENGTH ? null : $term;
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    /**
     * Tickets by key, title or description.
     *
     * Two queries rather than one, because the two questions are different
     * shapes. "NL-123" is an identifier and deserves an index: the prefix
     * resolves to a board and `(board_id, number)` is unique, so that lookup is
     * a key seek however large the table gets. Everything else is prose and has
     * to be scanned.
     *
     * The exact match is put first and then excluded from the scan, so pasting
     * a key puts that ticket at the top instead of somewhere among every other
     * ticket whose number happens to end in 123 — which is what
     * Ticket::scopeSearch alone would do, by design, since it is also used by
     * board filters where that behaviour is wanted.
     *
     * @return array<int, SearchHit>
     */
    private function tickets(?Authenticatable $user, string $term): array
    {
        $hits = [];
        $exactId = null;

        if (($exact = $this->ticketByKey($user, $term)) instanceof Ticket) {
            $exactId = (int) $exact->getKey();
            $hits[] = $this->ticketHit($exact);
        }

        $matches = Ticket::query()
            ->visibleTo($user)
            ->search($term)
            ->when($exactId !== null, fn (Builder $query) => $query->whereKeyNot($exactId))
            ->with('board:id,name,slug,ticket_prefix')
            ->orderByDesc('tickets.updated_at')
            ->limit(self::PER_GROUP - count($hits))
            ->get();

        foreach ($matches as $ticket) {
            $hits[] = $this->ticketHit($ticket);
        }

        return $hits;
    }

    /**
     * The one ticket a term names outright, if it names one.
     */
    private function ticketByKey(?Authenticatable $user, string $term): ?Ticket
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,9})\s*-\s*(\d{1,9})$/', $term, $matches) !== 1) {
            return null;
        }

        // Through BoardAccess, so an unreachable board's prefix resolves to
        // nothing rather than to that board.
        $boardIds = $this->access->query($user)
            ->where('boards.ticket_prefix', $matches[1])
            ->pluck('boards.id')
            ->all();

        if ($boardIds === []) {
            return null;
        }

        return Ticket::query()
            ->visibleTo($user)
            ->whereIn('tickets.board_id', $boardIds)
            ->where('tickets.number', (int) $matches[2])
            ->with('board:id,name,slug,ticket_prefix')
            ->first();
    }

    private function ticketHit(Ticket $ticket): SearchHit
    {
        return new SearchHit(
            key: $ticket->key(),
            label: (string) $ticket->title,
            hint: $ticket->board->name,
            url: route('tickets.show', ['board' => $ticket->board->slug, 'number' => $ticket->number]),
            id: 'ticket-'.$ticket->getKey(),
        );
    }

    /**
     * Documentation pages by title or body.
     *
     * Through DocPageFinder rather than a scope of its own. A documentation
     * page has a rule no other model has — it is readable only when every one
     * of its ancestors is — and that rule has one implementation on purpose.
     * A palette that queried `DocPage::visibleTo()` directly would surface the
     * title of a page published beneath an internal parent, which is the exact
     * leak DocPageFinder exists to prevent.
     *
     * @return array<int, SearchHit>
     */
    private function documents(?Authenticatable $user, string $term): array
    {
        return array_map(
            fn (DocPage $page): SearchHit => new SearchHit(
                key: null,
                label: (string) $page->title,
                hint: $page->board->name,
                url: route('docs.show', ['board' => $page->board->slug, 'slug' => $page->slug]),
                id: 'doc-'.$page->getKey(),
            ),
            $this->pages->searchAcrossBoards($user, $term, self::PER_GROUP)->all(),
        );
    }

    /**
     * @return array<int, SearchHit>
     */
    private function boards(?Authenticatable $user, string $term): array
    {
        $boards = $this->access->query($user)
            ->search($term)
            ->orderBy('boards.name')
            ->limit(self::PER_GROUP)
            ->get(['boards.id', 'boards.name', 'boards.slug', 'boards.ticket_prefix', 'boards.archived_at']);

        return $boards->map(fn (Board $board): SearchHit => new SearchHit(
            key: $board->ticket_prefix,
            label: $board->name,
            hint: $board->archived_at === null ? null : 'Archived',
            url: route('boards.show', $board->slug),
            id: 'board-'.$board->getKey(),
        ))->all();
    }

    /**
     * People, for viewers who are allowed to know who they are.
     *
     * Two rules, and both are narrower than they might first look.
     *
     * Customers get nothing. A customer's view of this workspace is their own
     * tickets and the documentation published to them; the membership of the
     * delivery team is not part of it, and a palette that answered "vik" with
     * a name and an email address would be a directory a customer was never
     * given.
     *
     * Staff get the people they share a board with, and administrators get
     * everybody — the same shape as the rest of the product, where a team
     * member sees their boards and an administrator sees the workspace. A team
     * member searching a name they have no working relationship with gets
     * nothing rather than a hit they cannot act on.
     *
     * Deactivated accounts are included for staff. Somebody looking up a name
     * is usually looking up who did something, and dropping people the moment
     * they leave makes exactly that question unanswerable.
     *
     * @return array<int, SearchHit>
     */
    private function people(?Authenticatable $user, string $term): array
    {
        if (! $this->access->canSeeInternalContent($user)) {
            return [];
        }

        $query = User::query()->search($term);

        if (! ($user instanceof User && $user->isAdmin())) {
            $boardIds = $this->access->boardIdsFor($user);

            if ($boardIds === []) {
                return [];
            }

            $query->whereExists(function (QueryBuilder $sub) use ($boardIds): void {
                $sub->selectRaw('1')
                    ->from((new BoardMember)->getTable())
                    ->whereColumn('board_members.user_id', 'users.id')
                    ->whereIn('board_members.board_id', $boardIds);
            });
        }

        $people = $query
            ->orderBy('users.name')
            ->limit(self::PER_GROUP)
            ->get(['users.id', 'users.name', 'users.email', 'users.role', 'users.deactivated_at']);

        return $people->map(fn (User $person): SearchHit => new SearchHit(
            key: null,
            label: $person->name,
            hint: $person->isActive()
                ? $person->role->label()
                : $person->role->label().' · deactivated',
            // Only an administrator has a screen to open. For everybody else
            // the hit is an answer, not a destination.
            url: $user instanceof User && $user->isAdmin()
                ? route('users.index', ['q' => $person->name])
                : null,
            id: 'user-'.$person->getKey(),
        ))->all();
    }
}
