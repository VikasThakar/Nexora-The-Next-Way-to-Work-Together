<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentStream;
use App\Models\Concerns\BelongsToBoard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message in one of a ticket's two conversations.
 *
 * Visibility
 * ----------
 * `stream` is the customer boundary here, exactly as `customer_visible` is on a
 * ticket, and it is deliberately NOT mass assignable: only
 * App\Actions\Comments\PostComment sets it, which is where "a customer can only
 * ever write to the customer stream" is enforced and tested.
 *
 * Every read must go through the visibleTo() scope, which applies three
 * restrictions, all of them in SQL:
 *
 *   1. board membership;
 *   2. the stream — customers only ever see customer-facing streams;
 *   3. the ticket — a comment is readable only when its ticket is.
 *
 * The third matters more than it looks. Without it, a comment written in the
 * customer stream of an *internal* ticket would be readable by a customer, even
 * though the ticket itself is not: the two flags live on different rows and
 * only one of them was being checked. It is expressed as a subquery against
 * Ticket::visibleTo() rather than by restating the ticket rules here, so the
 * two definitions cannot drift apart.
 *
 * In practice reads go through App\Services\CommentReader, which has no
 * unscoped entry point at all — but the scope is safe on its own, which is what
 * makes it safe for NotificationReader and anything added later to use.
 */
class Comment extends Model
{
    use BelongsToBoard {
        scopeVisibleTo as private scopeVisibleByBoardAndStream;
    }
    use SoftDeletes;

    /**
     * Only the body. Identity (ticket, board, author) and audience (stream) are
     * assigned by actions, never by whatever array a form happens to submit.
     *
     * @var list<string>
     */
    protected $fillable = [
        'body_md',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stream' => CommentStream::class,
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Comments carry their audience as a stream name rather than a boolean.
     */
    public function customerVisibleColumn(): ?string
    {
        return 'stream';
    }

    /** @return array<int, mixed> */
    public function customerVisibleValues(): array
    {
        return CommentStream::customerFacingValues();
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    public function isInternal(): bool
    {
        return ! $this->stream->isCustomerFacing();
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Board membership, the stream rule, and the ticket rule.
     *
     * Overrides the trait's version to add the third restriction. See the class
     * comment for why a comment needs its parent checked as well as itself.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $this->scopeVisibleByBoardAndStream($query, $user);

        $query->whereIn(
            'comments.ticket_id',
            Ticket::query()->visibleTo($user)->select('tickets.id')
        );
    }

    /** @param  Builder<Comment>  $query */
    public function scopeInStream(Builder $query, CommentStream $stream): void
    {
        $query->where('comments.stream', $stream->value);
    }

    /** @param  Builder<Comment>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('comments.created_at')->orderBy('comments.id');
    }
}
