<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\BoardAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the assistant did, written down.
 *
 * The audit ledger's row. Created by App\Services\AI\Audit\AiAuditLogger and by
 * nothing else, never updated, never deleted by the application.
 *
 * Visibility
 * ----------
 * Staff only, and then board-scoped. That is stricter than it looks, and the
 * reason is that a row's `target` is workspace content: "AQD-42",
 * "docs:incident-runbook". A customer reading their own audit trail would be
 * reading a list of the internal tickets the assistant consulted while
 * answering them, which is exactly the leak the whole feature is designed to
 * prevent. So a customer sees nothing here, including their own rows.
 *
 * An administrator sees the workspace; a team member sees rows for the boards
 * they can reach plus their own workspace-scoped ones. Expressed through
 * BoardAccess::constrain(), so "which boards" has one definition in the
 * application and this table inherits it.
 */
class AiToolInvocation extends Model
{
    public const CATEGORY_READ = 'read';

    public const CATEGORY_PROPOSAL = 'proposal';

    public const CATEGORY_ACTION = 'action';

    public const OUTCOME_OK = 'ok';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_INVALID = 'invalid';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_ERROR = 'error';

    /**
     * Deliberately empty. An audit row that could be mass assigned is an audit
     * row a request could write, which is worth nothing.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'success' => 'boolean',
            'result_characters' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<AiSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }

    /** @return BelongsTo<AiChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'ai_chat_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * How the row is coloured. Matches x-ui.badge's variants.
     *
     * Slate for a successful read rather than emerald: the assistant reading a
     * ticket is the normal state, and this product reserves colour for things
     * that need attention.
     */
    public function badgeVariant(): string
    {
        if (! $this->success) {
            return $this->outcome === self::OUTCOME_ERROR ? 'rose' : 'amber';
        }

        return $this->category === self::CATEGORY_ACTION ? 'brand' : 'slate';
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_OK => 'Succeeded',
            self::OUTCOME_REFUSED => 'Refused',
            self::OUTCOME_INVALID => 'Invalid input',
            self::OUTCOME_NOT_FOUND => 'Not found',
            default => 'Failed',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_READ => 'Read',
            self::CATEGORY_PROPOSAL => 'Proposal',
            self::CATEGORY_ACTION => 'Change',
            default => $this->category,
        };
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * @param  Builder<AiToolInvocation>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        // Staff only, including for their own rows. See the class comment.
        if (! $access->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $userId = $user?->getAuthIdentifier();

        $query->where(function (Builder $outer) use ($access, $user, $userId): void {
            $outer->where(function (Builder $scoped) use ($access, $user): void {
                $scoped->whereNotNull('ai_tool_invocations.board_id');

                $access->constrain($scoped, $user, 'ai_tool_invocations.board_id');
            });

            // A workspace-scoped invocation has no board to check, so it is
            // protected by ownership — the same rule AiSession applies to a
            // board-less conversation.
            $outer->orWhere(function (Builder $own) use ($userId): void {
                $own->whereNull('ai_tool_invocations.board_id')
                    ->where('ai_tool_invocations.user_id', $userId);
            });
        });
    }

    /**
     * @param  Builder<AiToolInvocation>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('ai_tool_invocations.created_at')
            ->orderByDesc('ai_tool_invocations.id');
    }

    /**
     * @param  Builder<AiToolInvocation>  $query
     */
    public function scopeForSession(Builder $query, AiSession|int $session): void
    {
        $query->where(
            'ai_tool_invocations.ai_session_id',
            $session instanceof AiSession ? $session->getKey() : $session
        );
    }
}
