<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Concerns\BelongsToBoard;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A unit of work on a board.
 *
 * Visibility
 * ----------
 * `customer_visible` is the customer boundary and is stored positively: false
 * means internal. It is deliberately NOT mass assignable, so it can only be set
 * by App\Actions\Tickets\*, which is where the rule "a customer may never
 * create or reveal an internal ticket" is enforced and tested.
 *
 * Every read must go through the visibleTo() scope inherited from
 * BelongsToBoard, which applies board membership and the customer rule in SQL.
 * Loading a ticket by primary key without that scope is a bug.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToBoard;

    use HasFactory;

    /**
     * Suppresses the "moved to" notification for this instance only.
     *
     * A declared property, so it is never an attribute and never persisted —
     * Eloquent's __get is not consulted for properties that exist on the class.
     *
     * It lives here rather than as a switch on App\Observers\TicketObserver or
     * on the dispatcher because the thing being suppressed is a fact about
     * *these tickets*, not about the request. Deleting a column moves every
     * ticket out of it one model at a time
     * (App\Actions\Columns\DeleteColumn::moveTickets), which the observer sees
     * as a status change per ticket — so without this, tidying a board would
     * notify everybody assigned a card on it. The alternative, a flag on a
     * service, would need that service to be a singleton and would then be
     * global state that a forgotten `finally` could leave switched on.
     *
     * The move is still recorded in the timeline and still broadcast; only the
     * bell is quiet, and the timeline says the column was removed.
     */
    public bool $withoutStatusNotification = false;

    /**
     * `board_id`, `number`, `customer_visible` and `created_by_id` are
     * deliberately absent: identity and visibility are assigned by actions,
     * never by whatever array a form happens to submit.
     *
     * @var list<string>
     */
    protected $fillable = [
        'board_column_id',
        'title',
        'type',
        'description_md',
        'priority',
        'assignee_id',
        'estimate',
        'due_date',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'type' => TicketType::class,
            'due_date' => 'date',
            'customer_visible' => 'boolean',
            'estimate' => 'decimal:2',
            'position' => 'integer',
            'number' => 'integer',
        ];
    }

    /**
     * Tickets carry the positive visibility flag; see BelongsToBoard.
     */
    public function customerVisibleColumn(): ?string
    {
        return 'customer_visible';
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<BoardColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'ticket_label')->withTimestamps();
    }

    /** @return HasMany<TicketSubtask, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(TicketSubtask::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /**
     * Both conversation streams, unfiltered.
     *
     * NOT an authorization boundary: this relation contains internal notes.
     * Anything rendered to a user must go through App\Services\CommentReader,
     * which applies the stream rule in SQL.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<TicketLink, $this> */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'source_ticket_id');
    }

    /** @return HasMany<TicketLink, $this> */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'target_ticket_id');
    }

    /**
     * Branches, commits and pull requests that mention this ticket.
     *
     * NOT an authorization boundary: every row is internal. Read through
     * App\Services\GitHub\GithubLinkReader, which refuses customers.
     *
     * @return HasMany<GithubLink, $this>
     */
    public function githubLinks(): HasMany
    {
        return $this->hasMany(GithubLink::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * The human key, e.g. "AQD-42".
     *
     * Requires the board relation. Callers that render many tickets should
     * eager load it — for a single board that is one extra row, and strict mode
     * turns a forgotten eager load into a loud failure rather than an N+1.
     */
    public function key(): string
    {
        return $this->board->ticket_prefix.'-'.$this->number;
    }

    public function isInternal(): bool
    {
        return ! $this->customer_visible;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Free-text search across the human key, title and description.
     *
     * Searching "AQD-42" or just "42" both find ticket 42, because that is what
     * people paste into a search box.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('tickets.title', 'like', '%'.$term.'%')
                ->orWhere('tickets.description_md', 'like', '%'.$term.'%');

            // "AQD-42", "aqd-42" or "42" should all match ticket number 42.
            if (preg_match('/(\d+)\s*$/', $term, $matches) === 1) {
                $query->orWhere('tickets.number', (int) $matches[1]);
            }
        });
    }

    /** @param  Builder<Ticket>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('tickets.position')->orderBy('tickets.id');
    }

    /** @param  Builder<Ticket>  $query */
    public function scopeInColumn(Builder $query, BoardColumn|int $column): void
    {
        $query->where(
            'tickets.board_column_id',
            $column instanceof BoardColumn ? $column->getKey() : $column
        );
    }
}
