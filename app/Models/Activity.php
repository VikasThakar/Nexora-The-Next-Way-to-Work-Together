<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityCategory;
use App\Enums\ActivityType;
use App\Services\BoardAccess;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * One entry in the workspace activity feed. Append-only.
 *
 * The package's model with three things added: the board relationship, the
 * scopes the Activity screen reads through, and — the important one —
 * readableBy(), which is the only sanctioned way to query this table.
 *
 * `config('activitylog.activity_model')` points here, so a row written by the
 * bare `activity()` helper is still read back through the scope below. That is
 * the whole reason for subclassing rather than putting a repository beside the
 * package's model: there is no second door.
 *
 * Nothing updates a row after it is written. `updated_at` exists because the
 * package's schema has it, not because anything touches it.
 *
 * @property string|null $log_name
 * @property string $description
 * @property string|null $event
 * @property int|null $board_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $causer_type
 * @property int|null $causer_id
 */
class Activity extends SpatieActivity
{
    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * The person who performed the activity.
     *
     * A second relationship to the same row the `causer` morph resolves, and
     * worth having: the feed only ever renders users, and a plain BelongsTo can
     * be eager loaded with a single `whereIn` where a MorphTo cannot.
     *
     * Rows whose causer is not a user — none are written today — resolve to
     * null here, which the templates already render as "System".
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * The typed activity, or null.
     *
     * Deliberately an accessor rather than a cast. `event` is a plain nullable
     * string in the package's schema, and any caller — the bare activity()
     * helper, or a future version of the package — may write something this
     * enum has never heard of. A cast would throw when such a row is read; this
     * returns null and lets the renderer fall back to the stored description,
     * which is the same defensiveness the ticket timeline already practises
     * with its JSON payloads.
     */
    public function type(): ?ActivityType
    {
        return $this->event === null ? null : ActivityType::tryFrom($this->event);
    }

    public function category(): ?ActivityCategory
    {
        return $this->log_name === null ? null : ActivityCategory::tryFrom($this->log_name);
    }

    /**
     * Read one stored property.
     *
     * The package casts the column to a Collection, and a row written before a
     * key existed simply does not have it. Every template would otherwise need
     * its own guard; this gives them one.
     */
    public function property(string $key, mixed $default = null): mixed
    {
        $properties = $this->properties;

        if ($properties === null) {
            return $default;
        }

        return data_get($properties, $key, $default);
    }

    // ---------------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------------

    /**
     * Every row this user is allowed to read, and no others.
     *
     * This is the only authorization boundary on the table, and it does its
     * work in SQL so no caller can forget half of it. Three rules, in the
     * order they are applied:
     *
     *  1. A viewer who may not see internal content sees nothing at all. The
     *     Activity screen is a delivery-team tool: its descriptions name
     *     internal tickets, internal notes and internal configuration, and
     *     there is no per-row rewriting that would make the feed safe for a
     *     customer. Failing closed here means the screen cannot be made to leak
     *     by a future route or component that forgets its own gate.
     *
     *  2. Board scoping, through the same App\Services\BoardAccess::constrain()
     *     every other board-scoped table uses. A team member sees the boards
     *     they are a member of; an administrator is not narrowed.
     *
     *  3. Rows with no board are workspace-level — a board being deleted, for
     *     instance — and are administrator-only. Stated explicitly rather than
     *     left to the fact that NULL never satisfies the membership EXISTS,
     *     because that is an accident of SQL semantics rather than a decision
     *     anybody would find when reading this later.
     *
     * There is deliberately no fourth rule dropping the types
     * App\Enums\ActivityType::isInternalOnly() marks. Rule 1 has already
     * removed every viewer such a rule could apply to, and a filter that can
     * never fire is a filter nobody maintains. That flag is a declaration for a
     * future customer-safe feed to read; it is held to the existing
     * ticket-timeline rules by Tests\Security\ActivityVisibilityTest, so it
     * cannot drift while it waits.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeReadableBy(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        if (! $access->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $access->constrain($query, $user, 'board_id');

        if (! ($user instanceof User && $user->isAdmin())) {
            $query->whereNotNull($this->getTable().'.board_id');
        }
    }

    // ---------------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------------

    /**
     * Narrow to one board.
     *
     * NOT an authorization boundary on its own — always compose with
     * readableBy(), which is what decides whether this board may be read at
     * all.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeForBoard(Builder $query, Board|int $board): void
    {
        $query->where(
            $this->getTable().'.board_id',
            $board instanceof Board ? $board->getKey() : $board
        );
    }

    /**
     * Narrow to a category, or to a single type.
     *
     * One parameter for both, because the screen has one dropdown and a
     * category is tried first: App\Enums\ActivityCategory and
     * App\Enums\ActivityType share no values, which
     * Tests\Unit\ActivityTaxonomyTest asserts.
     *
     * An unrecognised value narrows to nothing rather than falling through to
     * "everything" — a hand-edited query string must not be able to widen a
     * feed. It could not widen it past readableBy() in any case; this keeps the
     * filter itself honest.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeOfType(Builder $query, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        $table = $this->getTable();

        if (($category = ActivityCategory::tryFrom($value)) !== null) {
            $query->where($table.'.log_name', $category->value);

            return;
        }

        if (($type = ActivityType::tryFrom($value)) !== null) {
            $query->where($table.'.event', $type->value);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Narrow to the person who performed the activity.
     *
     * The causer type is pinned as well as the id. Without it a user id of 3
     * would also match an activity caused by some future non-user causer that
     * happened to have id 3.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeCausedByUser(Builder $query, User|int $user): void
    {
        $table = $this->getTable();

        $query->where($table.'.causer_type', (new User)->getMorphClass())
            ->where($table.'.causer_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<Activity>  $query
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->whereBetween($this->getTable().'.created_at', [$from, $to]);
    }

    /**
     * Free-text search.
     *
     * Three places are searched, and which three is the point:
     *
     *   description   which is why App\Services\ActivityLogger stores a
     *                 composed sentence rather than a template key. It already
     *                 contains the ticket key, the ticket or page title and the
     *                 column names, so "AQD-142", "Safari login" and
     *                 "In Progress" all match without a join.
     *   the causer    by name, so "what did Alex move?" works.
     *   the board     by name and ticket prefix, matching Board::search().
     *
     * The last two are correlated EXISTS subqueries rather than real joins, so
     * no row is multiplied and the ordering and pagination stay on
     * `activity_log` alone.
     *
     * Not searched: `properties`. Matching inside JSON cannot use an index on
     * either supported database, and everything worth finding in there is in
     * the description already, by construction.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $table = $this->getTable();
        $causerType = (new User)->getMorphClass();

        $query->where(function (Builder $query) use ($table, $like, $causerType): void {
            $query->where($table.'.description', 'like', $like)
                ->orWhereExists(function (QueryBuilder $sub) use ($table, $like, $causerType): void {
                    $sub->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', $table.'.causer_id')
                        ->where($table.'.causer_type', $causerType)
                        ->where('users.name', 'like', $like);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($table, $like): void {
                    $sub->selectRaw('1')
                        ->from('boards')
                        ->whereColumn('boards.id', $table.'.board_id')
                        ->where(function (QueryBuilder $inner) use ($like): void {
                            $inner->where('boards.name', 'like', $like)
                                ->orWhere('boards.ticket_prefix', 'like', $like);
                        });
                });
        });
    }

    /**
     * Newest first, with the id as a tiebreak.
     *
     * Two activities written inside one transaction share a timestamp to the
     * second, so without the id their relative order would be whatever the
     * database felt like — and could differ between two pages, which is how
     * rows go missing from a paginated feed.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $table = $this->getTable();

        $query->orderByDesc($table.'.created_at')->orderByDesc($table.'.id');
    }
}
