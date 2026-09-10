<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiProvider;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Database\Factories\AiSessionFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One assistant conversation, owned by one person.
 *
 * Visibility
 * ----------
 * Internal without exception, and private within that: a session is readable
 * only by the person who started it, an administrator included. That is not a
 * new rule — it is the rule AiChatMessage already applies to its turns, and a
 * session that were readable more widely than its own turns would be a way to
 * learn what a colleague has been asking.
 *
 * So visibleTo() refuses customers outright and then narrows to ownership,
 * rather than to board membership. The board matters for a different reason: it
 * is the scope the conversation is *about*, and it still has to be reachable —
 * a session on a board whose membership has since been revoked stops being
 * readable, which is why the board branch is there at all.
 *
 * The snapshot
 * ------------
 * `provider`, `model` and `capability_mode` are what this conversation actually
 * ran under, recorded when it started and never refreshed. Read them, do not
 * re-resolve them: the point of writing them down is that the workspace default
 * will change and an old session must still be interpretable.
 *
 * Ending, not deleting
 * --------------------
 * end() closes a session to further questions. Nothing in the application
 * deletes one. Clearing a conversation removes its turns — which the product
 * has always allowed, and which is the person's own to remove — and leaves the
 * session and its usage ledger, because what was spent was spent.
 */
class AiSession extends Model
{
    /*
     * The trait supplies board() and scopeForBoard(). Its scopeVisibleTo() is
     * not aliased in: this class defines its own, because a session can be
     * scoped to the whole workspace and because ownership, not membership, is
     * the rule.
     */
    use BelongsToBoard;

    /** @use HasFactory<AiSessionFactory> */
    use HasFactory;

    /**
     * Assigned by App\Services\AI\AiSessionManager, never mass assigned. A
     * request that could choose its own user_id could adopt somebody else's
     * conversation.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'capability_mode' => AiCapabilityMode::class,
            // What the asker set this conversation to do. Narrows the
            // capability mode above; never widens it. See AiChatMode.
            'chat_mode' => AiChatMode::class,
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'estimated_cost' => 'decimal:6',
            'message_count' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Sessions are addressed by uuid; primary keys are never put in a URL or a
     * form in this application.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'ai_session_id');
    }

    /** @return HasMany<AiUsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AiUsageRecord::class, 'ai_session_id');
    }

    /**
     * The stored files, as ordinary attachments.
     *
     * A conversation owns its uploads polymorphically, exactly as a ticket or a
     * documentation page owns theirs, which is what lets AttachmentPolicy —
     * and therefore AttachmentController — protect an assistant upload without
     * knowing what a conversation is. The ability it asks for is `view` on this
     * model; see App\Policies\AiSessionPolicy.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * The readings of those files: extracted text, structure, status.
     *
     * @return HasMany<AiAttachment, $this>
     */
    public function aiAttachments(): HasMany
    {
        return $this->hasMany(AiAttachment::class, 'ai_session_id');
    }

    // ---------------------------------------------------------------------
    // Identity
    // ---------------------------------------------------------------------

    /**
     * The reference a person reads and quotes: AI-1042.
     *
     * Derived from the primary key, and it is a *label* rather than an address
     * — every route and form uses the uuid. That is why exposing the key here
     * is acceptable where it would not be in a URL: nothing can be fetched with
     * it, and a conversation people talk about needs a short handle.
     */
    public function reference(): string
    {
        return 'AI-'.$this->getKey();
    }

    /**
     * What the session list shows.
     *
     * A stored title when somebody gave one, otherwise the reference. Not the
     * first question: first questions are typos as often as they are subjects,
     * and a list of them reads worse than a list of references.
     */
    public function displayTitle(): string
    {
        $title = trim((string) $this->title);

        return $title === '' ? $this->reference() : $title;
    }

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Total tokens, or null when nothing was ever reported.
     *
     * Null rather than zero, all the way to the screen. A session whose
     * provider reported no usage genuinely does not know what it spent, and the
     * usage panel says so — see the note in AiCompletion about why an invented
     * figure is worse than a blank one.
     */
    public function totalTokens(): ?int
    {
        if ($this->tokens_input === null && $this->tokens_output === null) {
            return null;
        }

        return (int) $this->tokens_input + (int) $this->tokens_output;
    }

    /**
     * Has this conversation spent its allowance?
     *
     * A limit of zero means unlimited. Unknown usage counts as within budget:
     * refusing a conversation because a provider declines to report tokens
     * would turn the limit into a provider-detection mechanism.
     */
    public function hasReachedTokenLimit(int $limit): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $total = $this->totalTokens();

        return $total !== null && $total >= $limit;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Ownership, then board reachability. Nothing else.
     *
     * Two conditions, and each closes a different door:
     *
     *   ownership, because a session is one person's conversation. An
     *   administrator has no reading right here, which is why this scope does
     *   not lean on BoardAccess::constrain alone — that helper correctly
     *   returns the query untouched for an administrator, which on its own
     *   would expose every administrator's private sessions to each other;
     *
     *   board reachability, applied only to board-scoped rows, so a session
     *   about a board somebody has since been removed from stops being
     *   readable even though they own it.
     *
     * Where the customer check went
     * -----------------------------
     * This scope used to refuse customers outright, because at the time a
     * customer had no assistant at all. They now have a read-only one, so the
     * refusal moved from "who may have a conversation" to "what a conversation
     * may contain" — which is where it belongs, and where it is stronger.
     *
     * A customer's transcript is built from context assembled with them as the
     * viewer (TicketFinder, DocPageFinder), through tools that exclude the
     * staff-only ones (App\Services\AI\Tools\AiToolRegistry), with no write
     * tools at all (AiCapabilityGuard::allowsProposals refuses a customer in
     * every mode). So a customer's own session holds nothing internal to leak,
     * and ownership is a complete rule for it.
     *
     * What has NOT changed is that this is a private thread. A customer sees
     * their own sessions and nobody else's — not another customer's, and not
     * the delivery team's conversation about their board, which is the row this
     * scope's ownership clause is really guarding.
     *
     * @param  Builder<AiSession>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        /*
         * Deactivated accounts see nothing, stated explicitly.
         *
         * It used to be implied: the customer check this scope opened with
         * called User::canSeeInternalContent(), which is itself
         * active-and-staff. With that check gone, "still has an account here"
         * has to be its own condition, or a board-less conversation would stay
         * readable to a deactivated account by ownership alone.
         */
        if (! $user instanceof User || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('ai_sessions.user_id', $user->getKey());

        $query->where(function (Builder $outer) use ($access, $user): void {
            $outer->whereNull('ai_sessions.board_id');

            $outer->orWhere(function (Builder $scoped) use ($access, $user): void {
                $scoped->whereNotNull('ai_sessions.board_id');

                $access->constrain($scoped, $user, 'ai_sessions.board_id');
            });
        });
    }

    /**
     * The sessions started with no single board in context.
     *
     * @param  Builder<AiSession>  $query
     */
    public function scopeWorkspaceScoped(Builder $query): void
    {
        $query->whereNull('ai_sessions.board_id');
    }

    /** @param  Builder<AiSession>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ai_sessions.ended_at');
    }

    /**
     * Most recently active first, which is the order a session list wants.
     *
     * `last_activity_at` is null on a session nobody has asked anything in yet,
     * so `created_at` breaks the tie rather than letting nulls sort arbitrarily
     * across database engines.
     *
     * @param  Builder<AiSession>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('ai_sessions.last_activity_at')
            ->orderByDesc('ai_sessions.created_at')
            ->orderByDesc('ai_sessions.id');
    }
}
