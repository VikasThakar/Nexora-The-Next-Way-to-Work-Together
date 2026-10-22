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
use Illuminate\Support\Str;

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

    /** Remembered answer of hasMultipleTabs(); cleared by forgetTabs(). */
    private ?bool $multipleTabs = null;

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

    /**
     * The board's tabs, leftmost first. The first one is the default tab.
     *
     * @return HasMany<BoardTab, $this>
     */
    public function tabs(): HasMany
    {
        return $this->hasMany(BoardTab::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every column on the board, across all of its tabs.
     *
     * Positions count within a tab, so this order interleaves tabs; it is the
     * right scope for "does this column id belong to this board", not for
     * displaying columns. Show a tab's columns through BoardTab::columns().
     *
     * @return HasMany<BoardColumn, $this>
     */
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
     * The board's default tab — the leftmost one, where a ticket created
     * without a tab lands and what the board opens on.
     */
    public function defaultTab(): ?BoardTab
    {
        if ($this->relationLoaded('tabs')) {
            return $this->tabs->first();
        }

        return $this->tabs()->first();
    }

    /**
     * A tab of this board by its URL slug, or null — never another board's.
     */
    public function tabBySlug(?string $slug): ?BoardTab
    {
        $slug = Str::lower(trim((string) $slug));

        if ($slug === '') {
            return null;
        }

        if ($this->relationLoaded('tabs')) {
            return $this->tabs->firstWhere('slug', $slug);
        }

        return $this->tabs()->where('slug', $slug)->first();
    }

    /**
     * Is the board split into more than one tab?
     *
     * Labels name the tab ("Backend API › Doing") only when this is true, so a
     * board with a single tab reads exactly as boards did before tabs existed.
     * Uses a loaded `tabs_count` or `tabs` relation when there is one, and
     * remembers the answer on this instance: tickets eager-loaded together
     * share one Board instance, so a list asks once per board.
     */
    public function hasMultipleTabs(): bool
    {
        if ($this->multipleTabs !== null) {
            return $this->multipleTabs;
        }

        if (array_key_exists('tabs_count', $this->getAttributes())) {
            return $this->multipleTabs = (int) $this->getAttribute('tabs_count') > 1;
        }

        if ($this->relationLoaded('tabs')) {
            return $this->multipleTabs = $this->tabs->count() > 1;
        }

        return $this->multipleTabs = BoardTab::query()->where('board_id', $this->getKey())->count() > 1;
    }

    /**
     * Drop what this instance remembers about its tabs, after they changed.
     */
    public function forgetTabs(): void
    {
        $this->multipleTabs = null;
        $this->unsetRelation('tabs');
        unset($this->attributes['tabs_count']);
    }

    /**
     * The column a ticket lands in when neither a tab nor a column is chosen:
     * the first column of the default tab.
     */
    public function firstColumn(): ?BoardColumn
    {
        return $this->defaultTab()?->firstColumn();
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
     * Should a ticket staff create here start out visible to customers?
     *
     * The starting value of the "Visible to the customer" box on the New
     * ticket form and in quick add, and the ceiling for a ticket the assistant
     * creates. A board can override the workspace default in its settings.
     */
    public function newTicketsVisibleToCustomers(): bool
    {
        return (bool) $this->setting('new_tickets_visible_to_customers');
    }

    /**
     * How many active customers are members of this board — the people a
     * customer-visible ticket here is shown to.
     */
    public function activeCustomerCount(): int
    {
        return $this->members()->active()->role(UserRole::Customer)->count();
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
