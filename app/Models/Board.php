<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Support\BoardAiSettings;
use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;

class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'ticket_prefix',
        'description',
        'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Boards are addressed by slug in URLs; primary keys are never exposed.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return HasMany<BoardMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(BoardMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_members')
            ->using(BoardMember::class)
            ->withTimestamps();
    }

    /**
     * Members who may be given a ticket: active staff on this board.
     *
     * Customers are excluded because assignment means "this person is doing the
     * work", and an administrator who wants tickets assigned to them simply
     * joins the board like anyone else.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignableMembers(): BelongsToMany
    {
        return $this->members()
            ->whereNull('users.deactivated_at')
            ->where('users.role', '!=', UserRole::Customer->value);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<BoardColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    /**
     * Every ticket on the board, unfiltered.
     *
     * This relation is NOT an authorization boundary. Anything rendered to a
     * user must go through Ticket::visibleTo(), which applies board membership
     * and the customer rule in SQL.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Every documentation page on the board, unfiltered.
     *
     * As with tickets, this relation is NOT an authorization boundary: it
     * contains internal pages. Read through App\Services\DocPageFinder.
     *
     * @return HasMany<DocPage, $this>
     */
    public function docPages(): HasMany
    {
        return $this->hasMany(DocPage::class);
    }

    /**
     * Every AI run on the board, unfiltered.
     *
     * NOT an authorization boundary — every row here is internal. Read through
     * App\Services\AI\AiRunReader, which refuses customers outright.
     *
     * @return HasMany<AiRun, $this>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * The board's workspace AI transcript, unfiltered.
     *
     * NOT an authorization boundary — the transcript quotes internal tickets
     * and internal notes, so it is staff-only. Read through
     * App\Services\AI\WorkspaceChatService.
     *
     * @return HasMany<AiChatMessage, $this>
     */
    public function aiChatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }

    /**
     * Every repository attached to the board, unfiltered.
     *
     * Like tickets and pages, NOT an authorization boundary: which systems a
     * team works in is internal. Read through BoardRepository::visibleTo().
     *
     * @return HasMany<BoardRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(BoardRepository::class);
    }

    /**
     * The column a ticket lands in when no column is chosen — where
     * customer-created tickets always go.
     */
    public function firstColumn(): ?BoardColumn
    {
        if ($this->relationLoaded('columns')) {
            return $this->columns->first();
        }

        return $this->columns()->first();
    }

    // ---------------------------------------------------------------------
    // Membership
    // ---------------------------------------------------------------------

    /**
     * Does an explicit membership row exist for this user?
     *
     * This intentionally ignores the administrator bypass: it answers a factual
     * question about the pivot table, not an authorization question.
     * Authorization lives in App\Services\BoardAccess and BoardPolicy.
     */
    public function hasMember(User $user): bool
    {
        if ($this->relationLoaded('memberships')) {
            return $this->memberships->contains('user_id', $user->getKey());
        }

        return $this->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /**
     * Read a board setting, falling back to the application-wide default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? config('workspace.board_defaults.'.$key);

        return Arr::get($this->settings ?? [], $key, $fallback);
    }

    /**
     * The board's AI configuration, with application defaults applied.
     *
     * Kept as its own accessor rather than a handful of setting() calls so no
     * caller has to know that the values live under a nested `ai` key, and so
     * the coercion and clamping in BoardAiSettings cannot be skipped.
     */
    public function aiSettings(): BoardAiSettings
    {
        return BoardAiSettings::forBoard($this);
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Constrain a board query to the boards a user is allowed to see.
     *
     * This is the single SQL definition of board visibility in the codebase.
     * Everything else (policies, Livewire components, future ticket and
     * documentation queries) routes through App\Services\BoardAccess, which
     * calls this scope.
     *
     * @param  Builder<Board>  $query
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): void
    {
        // No user, or a deactivated one, sees nothing. Never fall through.
        if (! $user instanceof User || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->isAdmin()) {
            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($user): void {
            $sub->selectRaw('1')
                ->from('board_members')
                ->whereColumn('board_members.board_id', 'boards.id')
                ->where('board_members.user_id', $user->getKey());
        });
    }

    /** @param  Builder<Board>  $query */
    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Board>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', '%'.$term.'%')
                ->orWhere('ticket_prefix', 'like', '%'.$term.'%');
        });
    }
}
