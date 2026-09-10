<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRunMode;
use App\Enums\AiRunStage;
use App\Enums\AiRunStatus;
use App\Enums\AiRunTrigger;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Database\Factories\AiRunFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt by the model to do something about one ticket.
 *
 * Visibility
 * ----------
 * Every row here is internal, without exception. The scope below does not
 * filter on a column — it refuses customers outright — because a filterable
 * flag is a flag somebody eventually sets the wrong way, and the consequence
 * here is a customer reading Claude's private assessment of their own request.
 *
 * That single rule covers more than the analysis text: the *existence* of a
 * run, its status, its cost and its error message are all internal. A customer
 * must not learn that their ticket was machine-triaged, nor that it failed to
 * be.
 *
 * The one thing a customer may ever see is a note a member of staff copied out
 * of an internal note into the customer conversation, deliberately, by hand.
 *
 * Nothing here is mass assignable: runs are created by App\Actions\AI\CreateAiRun
 * and advanced by App\Jobs\ExecuteAiRunJob, which is where the state machine and
 * the duplicate-execution guard live.
 */
class AiRun extends Model
{
    use BelongsToBoard {
        scopeVisibleTo as private scopeVisibleByBoard;
    }

    /** @use HasFactory<AiRunFactory> */
    use HasFactory;

    /**
     * Deliberately empty.
     *
     * There is no form that submits an AI run, and identity, mode, status and
     * cost must never come from an array. Every write goes through an action.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => AiRunMode::class,
            'status' => AiRunStatus::class,
            'trigger_source' => AiRunTrigger::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'duration_ms' => 'integer',
            // A string, not a float. SQLite hands back 0.1 where MySQL hands
            // back '0.100000', and a cost that changes shape with the driver
            // would make the sums differ between development and production.
            'estimated_cost' => 'decimal:6',
        ];
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
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    /** @return BelongsTo<BoardRepository, $this> */
    public function boardRepository(): BelongsTo
    {
        return $this->belongsTo(BoardRepository::class);
    }

    /**
     * The internal note this run produced.
     *
     * Note that this is a plain relation and therefore NOT an authorization
     * boundary — but it cannot leak, because reaching an AiRun at all already
     * requires being staff.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function resultComment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'result_comment_id');
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * What the run is doing, or did last.
     *
     * The status wins wherever the two could disagree, and that ordering
     * is the whole safety property: a worker killed mid-clone leaves
     * `stage: preparing` in the metadata for ever, and a run whose status
     * says failed must not go on claiming it is preparing. Progress can be
     * stale; the lifecycle cannot.
     */
    public function stage(): AiRunStage
    {
        if ($this->status === AiRunStatus::Queued) {
            return AiRunStage::Queued;
        }

        if ($this->status === AiRunStatus::Completed) {
            return AiRunStage::Finished;
        }

        if ($this->status === AiRunStatus::Failed || $this->status === AiRunStatus::Cancelled) {
            return AiRunStage::Failed;
        }

        $recorded = AiRunStage::tryFrom((string) ($this->metadata['stage'] ?? ''));

        // Running, with nothing recorded yet: it has been claimed but has
        // not reached its first step. Analysing is the honest answer for a
        // suggest run and for the first moment of an apply run alike.
        return $recorded ?? AiRunStage::Analysing;
    }

    /**
     * The step this run died on, if it died.
     *
     * Read from the metadata rather than from stage(), which reports
     * Failed — the useful fact for somebody reading a failure is *where*
     * it stopped, and that is the last stage recorded before the status
     * changed.
     */
    public function stoppedAt(): ?AiRunStage
    {
        if (! in_array($this->status, [AiRunStatus::Failed, AiRunStatus::Cancelled], true)) {
            return null;
        }

        return AiRunStage::tryFrom((string) ($this->metadata['stage'] ?? ''));
    }

    /**
     * Files the run changed, as recorded when it finished.
     *
     * Re-derived from `git status` by ApplyModeRunner rather than taken
     * from what the coding runtime claimed, which is why this list can be
     * trusted and the runtime's summary cannot.
     *
     * @return list<string>
     */
    public function changedFiles(): array
    {
        $files = $this->metadata['changed_files'] ?? [];

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, 'is_string'));
    }

    /**
     * The validation commands that ran, and whether each passed.
     *
     * @return list<array{command: string, passed: bool}>
     */
    public function validation(): array
    {
        $rows = $this->metadata['validation'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['command'])) {
                continue;
            }

            $clean[] = [
                'command' => (string) $row['command'],
                'passed' => (bool) ($row['passed'] ?? false),
            ];
        }

        return $clean;
    }

    public function duration(): ?string
    {
        if ($this->duration_ms === null) {
            return null;
        }

        $seconds = $this->duration_ms / 1000;

        return $seconds < 60
            ? number_format($seconds, 1).'s'
            : floor($seconds / 60).'m '.str_pad((string) round(fmod($seconds, 60)), 2, '0', STR_PAD_LEFT).'s';
    }

    /**
     * Total tokens, or null when the provider reported neither half.
     */
    public function totalTokens(): ?int
    {
        if ($this->tokens_input === null && $this->tokens_output === null) {
            return null;
        }

        return (int) $this->tokens_input + (int) $this->tokens_output;
    }

    public function formattedCost(): ?string
    {
        if ($this->estimated_cost === null) {
            return null;
        }

        $cost = (float) $this->estimated_cost;

        // Sub-cent runs are normal on a small ticket; rounding them to $0.00
        // would make a per-board total look free.
        return '$'.number_format($cost, $cost < 0.01 ? 4 : 2);
    }

    /**
     * Read one diagnostic without tripping over a null metadata column.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata ?? [], $key, $default);
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Board membership, and staff only. See the class comment.
     *
     * @param  Builder<AiRun>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (! app(BoardAccess::class)->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeVisibleByBoard($query, $user);
    }

    /** @param  Builder<AiRun>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('ai_runs.created_at')->orderByDesc('ai_runs.id');
    }

    /** @param  Builder<AiRun>  $query */
    public function scopeForTicket(Builder $query, Ticket|int $ticket): void
    {
        $query->where(
            'ai_runs.ticket_id',
            $ticket instanceof Ticket ? $ticket->getKey() : $ticket
        );
    }

    /** @param  Builder<AiRun>  $query */
    public function scopeTriggered(Builder $query, AiRunTrigger $trigger): void
    {
        $query->where('ai_runs.trigger_source', $trigger->value);
    }

    /**
     * Runs that consumed, or may still consume, provider capacity.
     *
     * Cancelled runs are excluded: they never reached the provider. Failed ones
     * are included, because a failure that happened after the request still
     * cost tokens and still counts against the day.
     *
     * @param  Builder<AiRun>  $query
     */
    public function scopeBillable(Builder $query): void
    {
        $query->whereIn('ai_runs.status', AiRunStatus::billableValues());
    }
}
