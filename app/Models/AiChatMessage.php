<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiActionType;
use App\Enums\AiChatRole;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the workspace AI conversation for one board.
 *
 * Visibility
 * ----------
 * Internal without exception, for the same reason AiRun is: a transcript
 * quotes internal tickets, internal notes and internal documentation, so it
 * cannot be safer than the least safe thing in it. The scope refuses customers
 * outright rather than filtering rows.
 *
 * Proposed actions
 * ----------------
 * When the assistant proposes a write, the proposal is stored in `metadata`
 * under `action`, together with its state. It is not a pending write and no
 * scheduler will ever pick it up: it is something the assistant said, and it
 * only becomes a database change when a human presses Confirm and
 * App\Actions\AI\ExecuteChatAction authorizes and performs it.
 *
 * The stored states are:
 *
 *   proposed    shown as a preview with Confirm / Discard
 *   confirmed   carried out; `result` names what was created or changed
 *   discarded   dismissed by a human; kept so the transcript still reads
 *   failed      confirmed but refused or errored; `error` says why
 */
class AiChatMessage extends Model
{
    /*
     * The trait supplies board(), scopeForBoard() and boardForeignKey(). Its
     * scopeVisibleTo() is not aliased in: the class defines its own, which
     * takes precedence, because a conversation can also be scoped to the whole
     * workspace and the trait's board rule cannot express that.
     */
    use BelongsToBoard;

    public const ACTION_PROPOSED = 'proposed';

    public const ACTION_CONFIRMED = 'confirmed';

    public const ACTION_DISCARDED = 'discarded';

    public const ACTION_FAILED = 'failed';

    /**
     * Identity and role are assigned by App\Services\AI\WorkspaceChatService,
     * never mass assigned: a request that could choose its own `role` could
     * forge an assistant turn and thereby its own instructions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => AiChatRole::class,
            'metadata' => 'array',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /**
     * The conversation this turn belongs to.
     *
     * Nullable in the schema, and null only on a turn whose author has since
     * been deleted — such a row belongs to no living conversation and was
     * already invisible to the transcript, which reads through ownedBy().
     *
     * @return BelongsTo<AiSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------------
    // Proposed actions
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function action(): ?array
    {
        $action = data_get($this->metadata ?? [], 'action');

        return is_array($action) ? $action : null;
    }

    public function actionType(): ?AiActionType
    {
        $type = data_get($this->action() ?? [], 'type');

        return is_string($type) ? AiActionType::tryFrom($type) : null;
    }

    public function actionState(): ?string
    {
        $state = data_get($this->action() ?? [], 'state');

        return is_string($state) ? $state : null;
    }

    /**
     * Is there a proposal here still waiting on a human?
     */
    public function awaitsConfirmation(): bool
    {
        return $this->actionType() !== null
            && $this->actionState() === self::ACTION_PROPOSED;
    }

    /**
     * The arguments the model proposed, as a plain array.
     *
     * @return array<string, mixed>
     */
    public function actionInput(): array
    {
        $input = data_get($this->action() ?? [], 'input');

        return is_array($input) ? $input : [];
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Board membership, then ownership where ownership is required.
     *
     * A null `board_id` means the turn was asked in the assistant's "All
     * workspace" context and belongs to no single board. Such a row cannot be
     * protected by board membership, so it is protected by ownership instead
     * and is readable only by the person who asked it.
     *
     * The explicit null branch is the load-bearing part. BoardAccess::constrain
     * expresses membership as `EXISTS (board_members WHERE board_id = ...)`,
     * which never matches a null and would therefore hide these rows from the
     * person who owns them — but it also returns the query *untouched* for an
     * administrator, which would expose every other administrator's private
     * conversation. Neither outcome is acceptable, so null is handled here
     * rather than left to the board rule.
     *
     * @param  Builder<AiChatMessage>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        if (! $user instanceof User || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $userId = $user->getKey();

        /*
         * Customers see their own turns and nothing else.
         *
         * This scope used to refuse a customer outright, because at the time a
         * customer had no assistant. They now have a read-only one, and the
         * rule that keeps its transcript safe is not this scope — it is that a
         * customer's context is assembled with them as the viewer and their
         * tools exclude the staff-only ones, so there is nothing internal in
         * their own turns to protect.
         *
         * What this clause protects is the other direction: a customer who is a
         * member of a board must not read the delivery team's conversation
         * about that board, which board reachability alone would allow. So for
         * a customer, reachability is not enough — ownership is required as
         * well.
         *
         * Staff keep the wider rule. A board-scoped turn is readable by any
         * member of that board, and ownedBy() is what narrows a transcript to
         * one person's thread; that separation is deliberate and unchanged, and
         * every caller that renders a conversation composes both.
         */
        $ownershipRequired = ! $access->canSeeInternalContent($user);

        $query->where(function (Builder $outer) use ($access, $user, $userId, $ownershipRequired): void {
            $outer->where(function (Builder $scoped) use ($access, $user, $userId, $ownershipRequired): void {
                $scoped->whereNotNull('ai_chat_messages.board_id');

                $access->constrain($scoped, $user, 'ai_chat_messages.board_id');

                if ($ownershipRequired) {
                    $scoped->where('ai_chat_messages.user_id', $userId);
                }
            });

            $outer->orWhere(function (Builder $own) use ($userId): void {
                $own->whereNull('ai_chat_messages.board_id')
                    ->where('ai_chat_messages.user_id', $userId);
            });
        });
    }

    /**
     * Narrow a transcript to one person's own turns.
     *
     * Composed on top of visibleTo() rather than replacing it: this decides
     * whose conversation is being read, not whether the reader is allowed to
     * read conversations at all.
     *
     * A null `user_id` is a turn whose author has since been deleted. It
     * matches nobody here, which is right — an orphaned turn belongs to no
     * living conversation.
     *
     * @param  Builder<AiChatMessage>  $query
     */
    public function scopeOwnedBy(Builder $query, ?Authenticatable $user): void
    {
        $id = $user?->getAuthIdentifier();

        if ($id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('ai_chat_messages.user_id', $id);
    }

    /**
     * The turns asked with no single board in context.
     *
     * @param  Builder<AiChatMessage>  $query
     */
    public function scopeWorkspaceScoped(Builder $query): void
    {
        $query->whereNull('ai_chat_messages.board_id');
    }

    /** @param  Builder<AiChatMessage>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('ai_chat_messages.created_at')->orderBy('ai_chat_messages.id');
    }
}
