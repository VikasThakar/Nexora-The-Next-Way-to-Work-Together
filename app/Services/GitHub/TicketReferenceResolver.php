<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\BoardRepository;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Turns "AQD-142" in a branch name or commit message into the ticket it means.
 *
 * The rule that makes this safe is not the pattern — it is the repository.
 *
 * A webhook is an anonymous request whose only credential is a signature
 * proving GitHub sent it. It does not prove *who owns the repository it is
 * about*: anybody can add a webhook to a repository they control and point it
 * here, and if they know the secret they can deliver a valid, signed payload
 * claiming a commit in "attacker/anything" fixes AQD-1. Without a repository
 * check that would write a row onto somebody else's ticket — a write into the
 * history of work the sender has no relationship to.
 *
 * So a delivery may only reach tickets on boards where an administrator has
 * already attached that repository. The repository name in the payload is
 * matched against `board_repositories`, and the resulting board ids are the
 * *only* boards in scope. A repository nobody has configured resolves to
 * nothing at all, and the delivery is recorded as ignored.
 *
 * Note what is deliberately absent: any `visibleTo()` scoping. There is no
 * viewer here — a webhook is a system actor with no user — so the ordinary
 * visibility scopes have nothing to apply. The board restriction above is what
 * replaces them, and it is narrower: it is not "boards somebody can see" but
 * "boards this specific repository is wired into".
 */
class TicketReferenceResolver
{
    /**
     * A board prefix then a hyphen then a number — the same shape
     * App\Services\TicketReferenceLinker matches in prose, kept in step with
     * config/workspace.php's prefix rules.
     *
     * Case-insensitive here, unlike in prose: branch names are typed by hand at
     * a terminal and "aqd-42-fix" is what people actually write.
     */
    private const PATTERN = '/\b([A-Za-z][A-Za-z0-9]{1,5})-(\d{1,9})\b/';

    /**
     * The distinct ticket keys mentioned anywhere in the given strings.
     *
     * @param  array<int, ?string>  $texts
     * @return array<string, array{prefix: string, number: int}> upper-cased key => parts
     */
    public function extract(array $texts): array
    {
        $found = [];
        $limit = (int) config('github.max_references_per_delivery', 50);

        foreach ($texts as $text) {
            $text = (string) $text;

            if ($text === '') {
                continue;
            }

            preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (count($found) >= $limit) {
                    return $found;
                }

                $key = mb_strtoupper($match[1]).'-'.(int) $match[2];

                $found[$key] = [
                    'prefix' => mb_strtoupper($match[1]),
                    'number' => (int) $match[2],
                ];
            }
        }

        return $found;
    }

    /**
     * The board repositories configured for a "owner/name" repository.
     *
     * Several boards may attach the same repository, which is legitimate — a
     * shared platform repository serves more than one customer's board — and
     * each gets its own link when the key matches one of its tickets.
     *
     * @return Collection<int, BoardRepository>
     */
    public function repositoriesFor(?string $repository): Collection
    {
        $repository = trim((string) $repository);

        if ($repository === '') {
            return collect();
        }

        return BoardRepository::query()
            ->where('repository_name', $repository)
            ->get();
    }

    /**
     * Resolve mentioned keys to tickets, restricted to the given boards.
     *
     * One query for every reference in the delivery rather than one per key:
     * a merge commit that closes six tickets should cost one round trip.
     *
     * @param  array<string, array{prefix: string, number: int}>  $references
     * @param  array<int, int>  $boardIds
     * @return Collection<int, Ticket> keyed by ticket id
     */
    public function resolve(array $references, array $boardIds): Collection
    {
        if ($references === [] || $boardIds === []) {
            return collect();
        }

        /** @var array<string, array<int, int>> $byPrefix */
        $byPrefix = [];

        foreach ($references as $reference) {
            $byPrefix[$reference['prefix']][] = $reference['number'];
        }

        return Ticket::query()
            // The restriction that makes the whole thing safe. See the class
            // comment: these are the boards the repository is attached to, not
            // the boards anybody can see.
            ->whereIn('tickets.board_id', $boardIds)
            ->where(function (Builder $query) use ($byPrefix): void {
                foreach ($byPrefix as $prefix => $numbers) {
                    $query->orWhere(function (Builder $group) use ($prefix, $numbers): void {
                        $group->whereIn('tickets.number', array_values(array_unique($numbers)))
                            ->whereExists(function (QueryBuilder $sub) use ($prefix): void {
                                $sub->selectRaw('1')
                                    ->from('boards')
                                    ->whereColumn('boards.id', 'tickets.board_id')
                                    ->where('boards.ticket_prefix', $prefix);
                            });
                    });
                }
            })
            ->with('board')
            ->get()
            ->keyBy(fn (Ticket $ticket): int => (int) $ticket->getKey());
    }
}
