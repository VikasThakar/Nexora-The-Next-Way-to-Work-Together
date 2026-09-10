<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiAttachmentKind;
use App\Enums\AiAttachmentStatus;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Database\Factories\AiAttachmentFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What the assistant read out of a file somebody attached to a conversation.
 *
 * The file is an ordinary App\Models\Attachment, stored on the same disk by
 * the same service as a ticket attachment. This row is the reading of it.
 *
 * Visibility
 * ----------
 * Identical to AiSession's, because an attachment cannot be safer than the
 * conversation it was uploaded into: internal access, then ownership, then that
 * the board is still reachable. An administrator has no reading right here,
 * for the same reason they have none on a colleague's transcript.
 *
 * That rule is stated three times over, deliberately: in scopeVisibleTo() for
 * every list, in AiSessionPolicy for every download (reached through
 * AttachmentPolicy, which asks the owner), and in the session filter every
 * lookup in App\Livewire\Ai\Concerns\TalksToWorkspaceAi applies. A file put in
 * front of a language model is the widest blast radius in this feature, so it
 * is the one place where restating the rule is worth the duplication.
 *
 * Text, and the absence of it
 * ---------------------------
 * `extracted_text` is null in three quite different situations, and the status
 * is what tells them apart: an image (Ready, sent as a picture instead), a file
 * we could not parse (Failed), and a format nothing reads (Unsupported). Code
 * that wants "is there anything to put in the prompt" should ask
 * hasReadableText(), not test the column.
 */
class AiAttachment extends Model
{
    /*
     * The trait supplies board() and scopeForBoard(). Its scopeVisibleTo() is
     * not aliased in: this class defines its own, because a conversation can be
     * scoped to the whole workspace and because ownership, not board
     * membership, is the rule.
     */
    use BelongsToBoard;

    /** @use HasFactory<AiAttachmentFactory> */
    use HasFactory;

    /**
     * Assigned by App\Services\AI\Attachments\AiAttachmentPipeline, never mass
     * assigned. A request that could choose its own `ai_session_id` could file
     * a document it uploaded into somebody else's conversation, and a request
     * that could choose its own `extracted_text` could write the prompt.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => AiAttachmentKind::class,
            'status' => AiAttachmentStatus::class,
            'structured' => 'array',
            'characters' => 'integer',
            'token_estimate' => 'integer',
            'truncated' => 'boolean',
            'context_sent_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<AiSession, $this> */
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
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * The name a person sees, which is the name they uploaded.
     *
     * Read from the attachment, which sanitised it on the way in — control
     * characters stripped, any path component dropped. Never used to build a
     * path, here or anywhere.
     */
    public function filename(): string
    {
        return (string) ($this->attachment?->filename ?? 'attachment');
    }

    public function humanSize(): string
    {
        return $this->attachment?->humanSize() ?? '';
    }

    /**
     * The card's second line: what it is, how big, and what came out of it.
     */
    public function descriptor(): string
    {
        return implode(' · ', array_values(array_filter([
            $this->kind->label(),
            $this->humanSize(),
            $this->summary,
        ])));
    }

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    /**
     * Is there text here that a prompt could carry?
     *
     * The question every caller actually means. A Ready image has no text and
     * that is correct rather than a fault; a Failed PDF has no text and that
     * is a fault. Neither should be answered by testing the column for null.
     */
    public function hasReadableText(): bool
    {
        return $this->status === AiAttachmentStatus::Ready
            && trim((string) $this->extracted_text) !== '';
    }

    /**
     * Is this a picture a vision model could look at?
     */
    public function isVisual(): bool
    {
        return $this->status === AiAttachmentStatus::Ready && $this->kind->isVisual();
    }

    /**
     * Does this attachment contribute anything at all to a prompt?
     */
    public function isUsable(): bool
    {
        return $this->hasReadableText() || $this->isVisual();
    }

    /**
     * Has its full text already been put in front of the model?
     *
     * What makes the difference between sending a document and referring to
     * it — see App\Services\AI\Attachments\AiAttachmentContext.
     */
    public function wasSentInFull(): bool
    {
        return $this->context_sent_at !== null;
    }

    /**
     * Structured data extracted from the file, as a plain array.
     *
     * @return array<string, mixed>
     */
    public function structure(): array
    {
        return is_array($this->structured) ? $this->structured : [];
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Ownership, then board reachability.
     *
     * The same conditions as AiSession::scopeVisibleTo, and they are repeated
     * rather than delegated because this is the scope that decides whether an
     * uploaded document is readable. It must be legible on its own, next to the
     * column that holds the document's text.
     *
     * Ownership is what BoardAccess::constrain cannot express: that helper
     * correctly leaves the query untouched for an administrator, which alone
     * would make every administrator's uploads readable by every other one.
     *
     * A customer may upload
     * ---------------------
     * This scope used to refuse customers, when the assistant was staff-only.
     * A customer now has a read-only assistant and may attach their own files
     * to it — a screenshot of the error, the spreadsheet that will not import —
     * which is the point of them having one.
     *
     * That is safe for the same reason it is safe for staff, and no more: an
     * upload is readable by the person who uploaded it and by nobody else.
     * Ownership was always the rule here rather than board membership, so
     * admitting customers changes who may have a row, not who may read one.
     *
     * @param  Builder<AiAttachment>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        // Deactivated accounts see nothing. Stated explicitly now that the
        // customer check — which was itself active-and-staff — has gone.
        if (! $user instanceof User || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('ai_attachments.user_id', $user->getKey());

        /*
         * And the conversation it was filed into must be theirs as well.
         *
         * Two ownership checks rather than one, because they answer different
         * questions: `ai_attachments.user_id` is who uploaded the file, and the
         * session's owner is whose conversation it is part of. The pipeline
         * always sets both to the same person, so this can only ever matter
         * for a row that should not exist — and that is exactly the row worth
         * refusing, since a file whose text has been put in front of a model in
         * somebody else's conversation is the most consequential row in this
         * table.
         *
         * An EXISTS rather than a join, so the scope composes with anything a
         * caller adds afterwards and cannot duplicate rows.
         */
        $query->whereExists(function (QueryBuilder $sub) use ($user): void {
            $sub->selectRaw('1')
                ->from('ai_sessions')
                ->whereColumn('ai_sessions.id', 'ai_attachments.ai_session_id')
                ->where('ai_sessions.user_id', $user->getKey());
        });

        $query->where(function (Builder $outer) use ($access, $user): void {
            $outer->whereNull('ai_attachments.board_id');

            $outer->orWhere(function (Builder $scoped) use ($access, $user): void {
                $scoped->whereNotNull('ai_attachments.board_id');

                $access->constrain($scoped, $user, 'ai_attachments.board_id');
            });
        });
    }

    /** @param  Builder<AiAttachment>  $query */
    public function scopeForSession(Builder $query, AiSession $session): void
    {
        $query->where('ai_attachments.ai_session_id', $session->getKey());
    }

    /**
     * Only the ones that have something to contribute.
     *
     * @param  Builder<AiAttachment>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('ai_attachments.status', AiAttachmentStatus::Ready->value);
    }

    /**
     * Oldest first, which is the order the composer lists them in and the
     * order they are named in the prompt.
     *
     * @param  Builder<AiAttachment>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('ai_attachments.created_at')->orderBy('ai_attachments.id');
    }
}
