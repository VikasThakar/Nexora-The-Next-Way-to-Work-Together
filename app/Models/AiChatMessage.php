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
    use BelongsToBoard {
        scopeVisibleTo as private scopeVisibleByBoard;
    }

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
     * Board membership, and staff only. See the class comment.
     *
     * @param  Builder<AiChatMessage>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (! app(BoardAccess::class)->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeVisibleByBoard($query, $user);
    }

    /** @param  Builder<AiChatMessage>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('ai_chat_messages.created_at')->orderBy('ai_chat_messages.id');
    }
}
