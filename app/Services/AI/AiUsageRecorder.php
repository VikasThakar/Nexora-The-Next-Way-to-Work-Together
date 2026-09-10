<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiProvider;
use App\Enums\AiUsagePurpose;
use App\Models\AiRun;
use App\Models\AiSession;
use App\Models\AiUsageRecord;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\Data\AiCompletion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes down what an exchange with a provider actually cost.
 *
 * One method does the work, and it is called after the provider returns —
 * never before, and never from an estimate. Everything it stores comes from
 * the response: the token counts the provider reported, the model the provider
 * says served the request, and the cost those two imply at the configured rate.
 *
 * The rule this class exists to enforce
 * -------------------------------------
 * A provider that reports no usage produces a record with null counts and
 * `usage_reported = false`. It does not produce zeros, and it does not produce
 * an estimate from a token count of our own. That is the same rule AiCompletion
 * and CostCalculationService already state, and this is the third place it has
 * to hold, because this is the table people will eventually sum.
 *
 * Totals are derived, not trusted
 * -------------------------------
 * The running totals on `ai_sessions` are maintained here, in the same
 * transaction as the ledger row, with `increment`-style arithmetic performed on
 * a locked row. Two questions asked at once by the same person — a second
 * browser tab — would otherwise read the same total and write it back twice.
 *
 * A session whose totals are null and whose exchange reported nothing stays
 * null: adding a reported exchange to an unreported one gives the reported
 * figure, which is right, but starting from zero would silently claim the
 * unreported exchange was free.
 */
class AiUsageRecorder
{
    public function __construct(private readonly CostCalculationService $costs) {}

    /**
     * Record one exchange and fold it into its session's totals.
     *
     * `$startedAt` is when the request was made rather than when it finished,
     * so the duration is the provider's latency and not the time it took to
     * store the answer.
     */
    public function record(
        AiUsagePurpose $purpose,
        AiProvider $provider,
        string $model,
        AiCompletion $completion,
        ?AiSession $session = null,
        ?AiRun $run = null,
        ?User $user = null,
        ?Board $board = null,
        ?Carbon $startedAt = null,
    ): AiUsageRecord {
        $startedAt ??= now();
        $completedAt = now();

        // The provider's own answer about which model served the request, when
        // it gave one. Cost is calculated from that rather than from what was
        // asked for.
        $servingModel = $completion->model ?? $model;

        $reported = $completion->inputTokens !== null || $completion->outputTokens !== null;

        return DB::transaction(function () use (
            $purpose,
            $provider,
            $servingModel,
            $completion,
            $session,
            $run,
            $user,
            $board,
            $startedAt,
            $completedAt,
            $reported
        ): AiUsageRecord {
            $record = new AiUsageRecord;

            $record->ai_session_id = $session?->getKey();
            $record->ai_run_id = $run?->getKey();
            $record->user_id = $user?->getKey();
            $record->board_id = $board?->getKey();
            $record->purpose = $purpose;
            $record->provider = $provider;
            $record->model = $servingModel;
            $record->tokens_input = $completion->inputTokens;
            $record->tokens_output = $completion->outputTokens;
            $record->usage_reported = $reported;
            $record->estimated_cost = $this->costs->estimate(
                $servingModel,
                $completion->inputTokens,
                $completion->outputTokens,
            );
            $record->duration_ms = max(0, (int) $startedAt->diffInMilliseconds($completedAt));
            $record->started_at = $startedAt;
            $record->completed_at = $completedAt;

            $record->save();

            if ($session instanceof AiSession) {
                $this->foldIntoSession($session, $record);
            }

            return $record;
        });
    }

    /**
     * Mirror a finished ticket run into the ledger.
     *
     * The run row is the source here rather than a completion object, because
     * by the time this is called ProcessAiResult has already reconciled what
     * the provider reported with what was asked for — which model actually
     * served it, what the cost came to — and a second reconciliation from the
     * raw completion could disagree with the run's own record.
     *
     * `usage_reported` is derived the same way it is for a chat exchange: the
     * provider said something about tokens, or it did not. A run that failed
     * before the provider answered has null counts and lands here as
     * unreported, which is the truth.
     *
     * Idempotent by lookup rather than by constraint: the job can be retried,
     * and a retried run that completed twice would otherwise be counted twice.
     */
    public function recordRun(AiRun $run, AiProvider $provider): AiUsageRecord
    {
        $existing = AiUsageRecord::query()->where('ai_run_id', $run->getKey())->first();

        $record = $existing instanceof AiUsageRecord ? $existing : new AiUsageRecord;

        $record->ai_run_id = $run->getKey();
        $record->ai_session_id = null;
        $record->user_id = $run->triggered_by_id;
        $record->board_id = $run->board_id;
        $record->purpose = AiUsagePurpose::TicketRun;
        $record->provider = $provider;
        $record->model = (string) ($run->model ?? 'unknown');
        $record->tokens_input = $run->tokens_input;
        $record->tokens_output = $run->tokens_output;
        $record->usage_reported = $run->tokens_input !== null || $run->tokens_output !== null;
        $record->estimated_cost = $run->estimated_cost;
        $record->duration_ms = $run->duration_ms;
        $record->started_at = $run->started_at;
        $record->completed_at = $run->finished_at ?? now();

        $record->save();

        return $record;
    }

    /**
     * Add one record to a session's running totals.
     *
     * Re-read under a lock rather than incremented on the instance the caller
     * happens to hold: that instance may have been loaded before an exchange in
     * another request completed, and writing its stale total back would lose
     * the other exchange entirely.
     */
    private function foldIntoSession(AiSession $session, AiUsageRecord $record): void
    {
        /** @var AiSession|null $locked */
        $locked = AiSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

        if (! $locked instanceof AiSession) {
            return;
        }

        if ($record->tokens_input !== null) {
            $locked->tokens_input = (int) $locked->tokens_input + (int) $record->tokens_input;
        }

        if ($record->tokens_output !== null) {
            $locked->tokens_output = (int) $locked->tokens_output + (int) $record->tokens_output;
        }

        if ($record->estimated_cost !== null) {
            // Summed as a string-safe decimal: these are fractions of a cent
            // and a float round trip would round them away.
            $locked->estimated_cost = number_format(
                (float) $locked->estimated_cost + (float) $record->estimated_cost,
                6,
                '.',
                ''
            );
        }

        $locked->last_activity_at = now();

        $locked->save();

        // Keep the caller's instance honest, so the panel that just asked a
        // question renders the total including it.
        $session->tokens_input = $locked->tokens_input;
        $session->tokens_output = $locked->tokens_output;
        $session->estimated_cost = $locked->estimated_cost;
        $session->last_activity_at = $locked->last_activity_at;
    }

    /**
     * What one person has spent since midnight, in tokens.
     *
     * Reads the ledger rather than the sessions, because a person's day spans
     * sessions and because a run they triggered spends their allowance too.
     * Returns zero when nothing was reported — which is the right answer for a
     * *limit* check even though it would be the wrong answer for a display:
     * a limit that counted unknown usage as infinite would refuse every
     * question on a provider that does not report tokens.
     */
    public function spentTodayBy(User $user): int
    {
        return (int) AiUsageRecord::query()
            ->spentBySince($user->getKey(), now()->startOfDay())
            ->selectRaw('coalesce(sum(tokens_input), 0) + coalesce(sum(tokens_output), 0) as total')
            ->value('total');
    }
}
