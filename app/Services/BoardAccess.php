<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Board;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single source of truth for "what may this user see?".
 *
 * Every board-scoped read in the application — now and in later phases
 * (tickets, comments, documentation, stats) — must go through this service so
 * that the rules exist in exactly one place.
 *
 * The rules:
 *
 *  1. An administrator may see every board.
 *  2. Any other user may see a board only when an explicit `board_members`
 *     row links them to it.
 *  3. A deactivated or unauthenticated user may see nothing at all.
 *  4. Customers may never observe content flagged as internal, on any board,
 *     including boards they are a member of.
 *
 * Registered as a singleton (see AppServiceProvider) so membership lookups are
 * resolved at most once per user per request.
 */
class BoardAccess
{
    /**
     * Memoised board ids per user id for the lifetime of the request.
     *
     * @var array<int, array<int, int>>
     */
    private array $boardIds = [];

    /**
     * Base query for the boards a user may see.
     *
     * @return Builder<Board>
     */
    public function query(?Authenticatable $user): Builder
    {
        return Board::query()->accessibleBy($this->normalise($user));
    }

    /**
     * Ids of every board the user may see.
     *
     * This materialises the full list and is therefore intended for membership
     * checks and small dashboards — not for constraining large queries. Use
     * constrain() for that, which keeps the work in SQL.
     *
     * @return array<int, int>
     */
    public function boardIdsFor(?Authenticatable $user): array
    {
        $user = $this->normalise($user);

        if (! $user instanceof User) {
            return [];
        }

        return $this->boardIds[$user->getKey()] ??= $this->query($user)
            ->pluck('boards.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Is there an explicit membership row for this user and board?
     *
     * Administrators are NOT members implicitly. Use canView() for
     * authorization decisions.
     */
    public function isMember(?Authenticatable $user, Board $board): bool
    {
        $user = $this->normalise($user);

        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        return $board->hasMember($user);
    }

    /**
     * May this user see the board at all?
     */
    public function canView(?Authenticatable $user, Board $board): bool
    {
        $user = $this->normalise($user);

        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $board->hasMember($user);
    }

    /**
     * May this user change the board itself or its membership list?
     *
     * Phase 1: administrators only. Kept as a method (rather than inlining an
     * isAdmin() check at call sites) so a future per-board owner role only
     * needs to be implemented here.
     */
    public function canManage(?Authenticatable $user, ?Board $board = null): bool
    {
        $user = $this->normalise($user);

        return $user instanceof User
            && $user->isActive()
            && $user->canAdministerWorkspace();
    }

    /**
     * May this user configure the working surface of a board they can see —
     * its columns and its labels?
     *
     * Distinct from canManage(), which governs the board record itself (name,
     * prefix, membership, deletion) and stays administrator-only. Columns and
     * labels are day-to-day workflow, so any staff member of the board may
     * change them. Customers never can, on any board.
     */
    public function canManageBoardContent(?Authenticatable $user, Board $board): bool
    {
        $user = $this->normalise($user);

        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff() && $board->hasMember($user);
    }

    /**
     * May this user observe content flagged as internal?
     *
     * This is the customer boundary. It is intentionally independent of board
     * membership: being a member of a board never grants a customer access to
     * internal tickets, comments or documentation on that board.
     */
    public function canSeeInternalContent(?Authenticatable $user): bool
    {
        $user = $this->normalise($user);

        return $user instanceof User && $user->canSeeInternalContent();
    }

    /**
     * Fail the request when the user may not see the board.
     *
     * Throws 404 rather than 403 on purpose: a 403 would confirm that a board
     * with this slug exists, which is itself information a non-member must not
     * receive.
     */
    public function authorizeView(?Authenticatable $user, Board $board): Board
    {
        if (! $this->canView($user, $board)) {
            throw new NotFoundHttpException;
        }

        return $board;
    }

    /**
     * Constrain any board-scoped query to the boards this user may see.
     *
     * This is the reusable primitive for later phases. Example:
     *
     *     app(BoardAccess::class)->constrain(Ticket::query(), $user);
     *
     * The restriction is expressed as a correlated EXISTS subquery so it stays
     * in SQL and never depends on a caller remembering to filter in PHP.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, ?Authenticatable $user, string $column = 'board_id'): Builder
    {
        $user = $this->normalise($user);

        if (! $user instanceof User || ! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        $qualified = str_contains($column, '.')
            ? $column
            : $query->getModel()->getTable().'.'.$column;

        return $query->whereExists(function (QueryBuilder $sub) use ($user, $qualified): void {
            $sub->selectRaw('1')
                ->from('board_members')
                ->whereColumn('board_members.board_id', $qualified)
                ->where('board_members.user_id', $user->getKey());
        });
    }

    /**
     * Hide internal rows from customers.
     *
     * Reusable by any future model that carries an internal visibility flag
     * (tickets, comments, documentation pages, stat widgets):
     *
     *     app(BoardAccess::class)->hideInternal(Comment::query(), $user);
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function hideInternal(Builder $query, ?Authenticatable $user, string $column = 'is_internal'): Builder
    {
        if ($this->canSeeInternalContent($user)) {
            return $query;
        }

        $qualified = str_contains($column, '.')
            ? $column
            : $query->getModel()->getTable().'.'.$column;

        return $query->where($qualified, false);
    }

    /**
     * The same rule as hideInternal(), for tables that store the flag the other
     * way round: `customer_visible = true` rather than `is_internal = true`.
     *
     * Tickets use the positive form because "is this shown to the customer?" is
     * the question the product actually asks, and a column that defaults to
     * false then fails closed — a row created by code that forgets to set it is
     * internal, not exposed.
     *
     *     app(BoardAccess::class)->restrictToCustomerVisible(Ticket::query(), $user);
     *
     * Not every table stores the answer as a boolean. Comments store a stream
     * name, of which only some are customer-facing, so the customer-facing
     * values are a parameter:
     *
     *     $access->restrictToCustomerVisible(
     *         Comment::query(), $user, 'stream', CommentStream::customerFacingValues()
     *     );
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, mixed>  $customerFacingValues  values a customer may see
     * @return Builder<TModel>
     */
    public function restrictToCustomerVisible(
        Builder $query,
        ?Authenticatable $user,
        string $column = 'customer_visible',
        array $customerFacingValues = [true],
    ): Builder {
        if ($this->canSeeInternalContent($user)) {
            return $query;
        }

        $qualified = str_contains($column, '.')
            ? $column
            : $query->getModel()->getTable().'.'.$column;

        // An empty allow-list means nothing is customer-facing. Fail closed
        // rather than degrading into "no restriction".
        if ($customerFacingValues === []) {
            return $query->whereRaw('1 = 0');
        }

        return count($customerFacingValues) === 1
            ? $query->where($qualified, reset($customerFacingValues))
            : $query->whereIn($qualified, $customerFacingValues);
    }

    /**
     * Apply both board scoping and internal-content scoping in one call.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(
        Builder $query,
        ?Authenticatable $user,
        string $boardColumn = 'board_id',
        string $internalColumn = 'is_internal'
    ): Builder {
        return $this->hideInternal(
            $this->constrain($query, $user, $boardColumn),
            $user,
            $internalColumn
        );
    }

    /**
     * Drop memoised state. Used by tests and long-running workers where
     * memberships may change mid-process.
     */
    public function flush(?Authenticatable $user = null): void
    {
        if ($user instanceof User) {
            unset($this->boardIds[$user->getKey()]);

            return;
        }

        $this->boardIds = [];
    }

    private function normalise(?Authenticatable $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
