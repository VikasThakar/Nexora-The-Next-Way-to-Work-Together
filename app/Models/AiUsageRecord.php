<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\AiUsagePurpose;
use App\Services\BoardAccess;
use Database\Factories\AiUsageRecordFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metered exchange with a provider.
 *
 * The ledger under the running totals on `ai_sessions` and `ai_runs`. A row is
 * written after every provider call that returned — including one that returned
 * nothing useful, because "we called and it reported no usage" is a fact worth
 * keeping and is not the same fact as "we never called".
 *
 * `usage_reported` is the column that distinguishes those, and reading it is
 * not optional. `tokens_input` and `tokens_output` are null when a provider
 * declined to report, and null must reach the screen as "not reported" rather
 * than being coalesced to zero somewhere on the way — somebody adds these up.
 *
 * Visibility follows the session it belongs to: internal, and private to the
 * person whose exchange it was. Board reachability is applied on top for a
 * board-scoped row, so a person removed from a board stops seeing what they
 * spent on it.
 */
class AiUsageRecord extends Model
{
    /** @use HasFactory<AiUsageRecordFactory> */
    use HasFactory;

    /**
     * Written only by App\Services\AI\AiUsageRecorder.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => AiUsagePurpose::class,
            'provider' => AiProvider::class,
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'usage_reported' => 'boolean',
            'estimated_cost' => 'decimal:6',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
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
    // Reading the numbers
    // ---------------------------------------------------------------------

    /**
     * Total tokens, or null when the provider reported none.
     */
    public function totalTokens(): ?int
    {
        if (! $this->usage_reported) {
            return null;
        }

        if ($this->tokens_input === null && $this->tokens_output === null) {
            return null;
        }

        return (int) $this->tokens_input + (int) $this->tokens_output;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * The same rule as AiSession: staff, own rows, reachable board.
     *
     * Stated here rather than inferred from the parent session, because a row
     * for a ticket run has no session to inherit from and would otherwise fall
     * through unfiltered.
     *
     * @param  Builder<AiUsageRecord>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        $access = app(BoardAccess::class);

        if (! $access->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $userId = $user?->getAuthIdentifier();

        if ($userId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('ai_usage_records.user_id', $userId);

        $query->where(function (Builder $outer) use ($access, $user): void {
            $outer->whereNull('ai_usage_records.board_id');

            $outer->orWhere(function (Builder $scoped) use ($access, $user): void {
                $scoped->whereNotNull('ai_usage_records.board_id');

                $access->constrain($scoped, $user, 'ai_usage_records.board_id');
            });
        });
    }

    /**
     * Everything one person has spent since a moment.
     *
     * The daily-limit check, and the reason `(user_id, created_at)` is indexed.
     * Not scoped by visibility: a limit counts what was actually spent, and a
     * board somebody has left does not refund it.
     *
     * @param  Builder<AiUsageRecord>  $query
     */
    public function scopeSpentBySince(Builder $query, int $userId, \DateTimeInterface $since): void
    {
        $query->where('ai_usage_records.user_id', $userId)
            ->where('ai_usage_records.created_at', '>=', $since);
    }

    /** @param  Builder<AiUsageRecord>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('ai_usage_records.created_at')->orderBy('ai_usage_records.id');
    }
}
