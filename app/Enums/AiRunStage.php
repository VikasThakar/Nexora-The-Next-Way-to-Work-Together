<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far through its work a run has got.
 *
 * Distinct from AiRunStatus, and the distinction is the point. Status is the
 * lifecycle the database enforces — queued, running, completed, failed,
 * cancelled — and it is what every guard and every cap is expressed in. This is
 * what the run is *doing* while its status is `running`, which is the question
 * somebody watching a coding session actually has:
 *
 *     Analysing → Coding → Testing → Pull request
 *
 * Why it is not a column
 * ----------------------
 * A stage is progress reporting, not state. Nothing branches on it, no policy
 * consults it, and a run whose stage was never written is still perfectly
 * interpretable from its status — so it lives in `ai_runs.metadata`, which is
 * already the run's diagnostic scratch space, rather than costing a migration
 * and a second state machine to keep consistent with the first.
 *
 * That also makes it safe: a worker that dies between two stages leaves a stale
 * value, and stale progress on a run whose status says `failed` reads correctly
 * because stageFor() below prefers the status. Progress can be wrong; the
 * lifecycle cannot.
 *
 * Suggest runs pass through Analysing and stop. That is not a truncated version
 * of the apply pipeline — it is the whole of what a suggest run does, and the
 * timeline says so rather than showing three steps greyed out for ever.
 */
enum AiRunStage: string
{
    case Queued = 'queued';

    /** Cloning the repository into the run's isolated directory. */
    case Preparing = 'preparing';

    /** Reading the ticket, the repository metadata and the documentation. */
    case Analysing = 'analysing';

    /** The coding runtime is editing files in the checkout. */
    case Coding = 'coding';

    /** Running the board's configured validation commands. */
    case Testing = 'testing';

    /** Committing, pushing the branch, opening the draft pull request. */
    case PullRequest = 'pull_request';

    case Finished = 'finished';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Preparing => 'Preparing',
            self::Analysing => 'Analysing',
            self::Coding => 'Coding',
            self::Testing => 'Testing',
            self::PullRequest => 'Pull request',
            self::Finished => 'Finished',
            self::Failed => 'Failed',
        };
    }

    /**
     * One line saying what is happening, for somebody watching.
     */
    public function description(): string
    {
        return match ($this) {
            self::Queued => 'Waiting for a worker to pick it up.',
            self::Preparing => 'Cloning the repository into an isolated directory.',
            self::Analysing => 'Reading the ticket and the repository.',
            self::Coding => 'Editing files in the isolated checkout.',
            self::Testing => 'Running the board\'s validation commands.',
            self::PullRequest => 'Committing, pushing the branch and opening a draft pull request.',
            self::Finished => 'Done. Nothing was merged.',
            self::Failed => 'Stopped before finishing.',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Finished || $this === self::Failed;
    }

    /**
     * Position in the pipeline, for drawing a timeline.
     *
     * Failed has no position: it replaces whichever step was in progress rather
     * than following the last one, which is why a failed run's timeline shows
     * the step it died on rather than a completed row of ticks.
     */
    public function order(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Preparing => 1,
            self::Analysing => 2,
            self::Coding => 3,
            self::Testing => 4,
            self::PullRequest => 5,
            self::Finished => 6,
            self::Failed => -1,
        };
    }

    /**
     * The steps a run in this mode actually passes through.
     *
     * @return list<self>
     */
    public static function pipelineFor(AiRunMode $mode): array
    {
        if ($mode->writesCode()) {
            return [self::Preparing, self::Analysing, self::Coding, self::Testing, self::PullRequest];
        }

        // A suggest run reads and writes an opinion. There is no checkout, no
        // test command and no branch, so the timeline has one step.
        return [self::Analysing];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
