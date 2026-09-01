<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRunTrigger;
use App\Models\AiRun;
use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How many runs a board may still start today.
 *
 * The asymmetry between the two triggers is the whole design:
 *
 *   automatic  nobody chose these. A customer filing twenty tickets in an
 *              afternoon starts twenty runs, and on apply mode that is twenty
 *              clones and twenty pull requests. The board's own
 *              `daily_auto_run_cap` (default 20) is the control, and NOBODY
 *              bypasses it — not an administrator, not a configuration flag.
 *              There is no bypass because there is nobody to grant one to: the
 *              runs are not anybody's decision.
 *   manual      a member of staff pressed a button, knowing what it costs. The
 *              cap here is a runaway-loop guard rather than a budget, so it is
 *              much looser and an administrator may exceed it when the
 *              deployment allows.
 *
 * "Today" is the board's own day. A board configured for Europe/Stockholm rolls
 * over at Stockholm midnight, not UTC midnight, because that is when the people
 * looking at the number think the day changed.
 *
 * Counting includes queued, running, completed and failed runs — everything
 * that reached or will reach the provider. Cancelled runs are excluded: they
 * never started, so holding them against the day would punish somebody for
 * changing their mind.
 */
class AiRunCap
{
    public function check(Board $board, AiRunTrigger $trigger, ?User $actor = null): AiRunCapDecision
    {
        return $trigger->isAutomatic()
            ? $this->checkAutomatic($board)
            : $this->checkManual($board, $actor);
    }

    /**
     * How many runs of this kind the board has started today.
     */
    public function usedToday(Board $board, AiRunTrigger $trigger): int
    {
        return AiRun::query()
            ->where('ai_runs.board_id', $board->getKey())
            ->triggered($trigger)
            ->billable()
            ->where('ai_runs.created_at', '>=', $this->startOfDay($board))
            ->count();
    }

    /**
     * The board's automatic limit, for display alongside usedToday().
     */
    public function automaticLimit(Board $board): int
    {
        return $board->aiSettings()->dailyAutoRunCap;
    }

    public function manualLimit(): int
    {
        return max(0, (int) config('ai.caps.daily_manual_runs', 100));
    }

    // -----------------------------------------------------------------

    private function checkAutomatic(Board $board): AiRunCapDecision
    {
        $limit = $this->automaticLimit($board);
        $used = $this->usedToday($board, AiRunTrigger::Automatic);

        if ($limit === 0) {
            return AiRunCapDecision::blocked(
                $used,
                $limit,
                'Automatic AI runs are capped at zero for this board today.'
            );
        }

        return $used < $limit
            ? AiRunCapDecision::allowed($used, $limit)
            : AiRunCapDecision::blocked(
                $used,
                $limit,
                'The daily cap for automatic AI runs on this board has been reached ('
                .$used.' of '.$limit.'). No run was started.'
            );
    }

    private function checkManual(Board $board, ?User $actor): AiRunCapDecision
    {
        $limit = $this->manualLimit();
        $used = $this->usedToday($board, AiRunTrigger::Manual);

        if ($used < $limit) {
            return AiRunCapDecision::allowed($used, $limit);
        }

        $bypass = $actor?->isAdmin() === true
            && (bool) config('ai.caps.admins_bypass_manual_cap', true);

        return $bypass
            ? AiRunCapDecision::bypassed($used, $limit)
            : AiRunCapDecision::blocked(
                $used,
                $limit,
                'The daily cap for manual AI runs on this board has been reached ('
                .$used.' of '.$limit.').'
            );
    }

    /**
     * Midnight, in the board's own timezone, as a UTC instant for the query.
     */
    private function startOfDay(Board $board): Carbon
    {
        $timezone = (string) ($board->setting('timezone') ?: config('app.timezone', 'UTC'));

        try {
            return Carbon::now($timezone)->startOfDay()->utc();
        } catch (\Throwable) {
            // A board carrying a timezone that is no longer valid must not stop
            // the cap being enforced; fall back to the application's own.
            return Carbon::now(config('app.timezone', 'UTC'))->startOfDay()->utc();
        }
    }
}
