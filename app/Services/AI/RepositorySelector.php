<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use App\Models\BoardRepository;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Which repository should this run look at?
 *
 * A board may have several. The strategy is deterministic and ordered, and each
 * step records *why* it fired in `ai_runs.repository_strategy`, so a run that
 * looked at the wrong code can be explained rather than guessed at.
 *
 * The order, first match wins:
 *
 *   1. only_repository    the board has exactly one. Nothing to decide.
 *   2. board_setting      the board's `primary_repository` setting names one by
 *                         name. An explicit human choice outranks the flag,
 *                         because it is the newer and more specific statement.
 *   3. primary_flag       the row flagged `is_primary`.
 *   4. ticket_mention     the ticket's title or description names exactly one
 *                         of the board's repositories. Only accepted when the
 *                         match is unambiguous: two candidates means no answer,
 *                         not the first one.
 *   5. none               several repositories, nothing to distinguish them.
 *                         Suggest mode proceeds without a working tree and says
 *                         so; apply mode refuses, because guessing which
 *                         repository to open a pull request against is not a
 *                         mistake worth making automatically.
 *
 * Step 4 is deliberately the weakest and is checked last. Text matching a
 * customer wrote is a hint, not an instruction, so it can never override a
 * setting a member of staff made.
 */
class RepositorySelector
{
    public const STRATEGY_ONLY = 'only_repository';

    public const STRATEGY_SETTING = 'board_setting';

    public const STRATEGY_PRIMARY = 'primary_flag';

    public const STRATEGY_MENTION = 'ticket_mention';

    public const STRATEGY_NONE = 'none';

    /**
     * @return array{repository: ?BoardRepository, strategy: string}
     */
    public function select(Board $board, ?Ticket $ticket = null): array
    {
        // Read through the relation rather than a scoped query on purpose: this
        // runs inside a queue worker with no authenticated user, and the
        // authorization question ("may this person start a run?") was settled
        // before the run was created. See AiRunPolicy.
        $repositories = $board->repositories()->ordered()->get();

        if ($repositories->isEmpty()) {
            return ['repository' => null, 'strategy' => self::STRATEGY_NONE];
        }

        if ($repositories->count() === 1) {
            return ['repository' => $repositories->first(), 'strategy' => self::STRATEGY_ONLY];
        }

        $named = $board->aiSettings()->primaryRepository;

        if ($named !== null) {
            $match = $repositories->first(
                fn (BoardRepository $repository): bool => strcasecmp($repository->repository_name, $named) === 0
            );

            if ($match instanceof BoardRepository) {
                return ['repository' => $match, 'strategy' => self::STRATEGY_SETTING];
            }
        }

        $primary = $repositories->firstWhere('is_primary', true);

        if ($primary instanceof BoardRepository) {
            return ['repository' => $primary, 'strategy' => self::STRATEGY_PRIMARY];
        }

        if ($ticket instanceof Ticket) {
            $mentioned = $this->mentionedIn($ticket, $repositories);

            if ($mentioned instanceof BoardRepository) {
                return ['repository' => $mentioned, 'strategy' => self::STRATEGY_MENTION];
            }
        }

        return ['repository' => null, 'strategy' => self::STRATEGY_NONE];
    }

    /**
     * Exactly one repository named in the ticket text, or null.
     *
     * Matches on the short name as well as `owner/name`, because that is how
     * people write it — but only when the result is unique. Ambiguity resolves
     * to null rather than to a coin toss.
     *
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function mentionedIn(Ticket $ticket, $repositories): ?BoardRepository
    {
        $haystack = mb_strtolower($ticket->title.' '.(string) $ticket->description_md);

        $matches = $repositories->filter(function (BoardRepository $repository) use ($haystack): bool {
            $full = mb_strtolower($repository->repository_name);
            $short = mb_strtolower((string) ($repository->ownerAndName()['name'] ?? $repository->repository_name));

            if (str_contains($haystack, $full)) {
                return true;
            }

            // A short name has to appear as a whole word: "api" must not match
            // "rapid", and a repository called "core" must not match every
            // ticket that says "of course".
            return mb_strlen($short) >= 3
                && preg_match('/(?<![\w-])'.preg_quote($short, '/').'(?![\w-])/u', $haystack) === 1;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
