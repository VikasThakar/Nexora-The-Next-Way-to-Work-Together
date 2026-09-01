<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Models\BoardRepository;
use App\Models\GithubLink;
use App\Models\Ticket;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns one verified GitHub delivery into ticket links.
 *
 * Runs on the queue, never in the request: GitHub disables a webhook endpoint
 * that repeatedly takes longer than ten seconds to answer, and a push to a
 * monorepo can carry hundreds of commits. The controller verifies, records and
 * dispatches; everything below happens afterwards.
 *
 * Two rules hold for every event type:
 *
 *   The repository decides the scope. A delivery can only touch tickets on
 *   boards where that repository is attached — see TicketReferenceResolver for
 *   why this is the security boundary and not the key pattern.
 *
 *   Links are idempotent. GitHub redelivers on timeout and replays on demand,
 *   and a pull request's life is several deliveries about one object. Every
 *   write is keyed on (ticket, repository, type, external id), so a replay
 *   updates the row it wrote the first time.
 *
 * Unrecognised events are not an error. New event types appear in GitHub, and a
 * webhook configured with "send me everything" is common; those deliveries are
 * acknowledged and recorded as ignored rather than retried forever.
 */
class WebhookProcessor
{
    public function __construct(private readonly TicketReferenceResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int how many links were written or updated
     */
    public function process(string $event, array $payload): int
    {
        $repository = (string) Arr::get($payload, 'repository.full_name', '');

        if ($repository === '') {
            return 0;
        }

        $repositories = $this->resolver->repositoriesFor($repository);

        // Nobody has attached this repository to a board. Not an error — a team
        // may point a repository here before configuring it, or leave a webhook
        // behind after removing one.
        if ($repositories->isEmpty()) {
            return 0;
        }

        return match ($event) {
            'push' => $this->handlePush($payload, $repository, $repositories),
            'create' => $this->handleCreate($payload, $repository, $repositories),
            'pull_request' => $this->handlePullRequest($payload, $repository, $repositories),
            'check_suite' => $this->handleCheckSuite($payload, $repository),
            'status' => $this->handleStatus($payload, $repository),
            default => 0,
        };
    }

    // -----------------------------------------------------------------
    // Event handlers
    // -----------------------------------------------------------------

    /**
     * Commits landing on a branch, and the branch itself.
     *
     * The branch name is checked as well as each commit message, because the
     * convention most teams actually use is to put the key in the branch once
     * rather than in every commit.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function handlePush(array $payload, string $repository, Collection $repositories): int
    {
        $ref = (string) Arr::get($payload, 'ref', '');

        // Tags and other refs are not branches and have no place on a ticket.
        if (! str_starts_with($ref, 'refs/heads/')) {
            return 0;
        }

        $branch = Str::after($ref, 'refs/heads/');
        $written = 0;

        // The branch, linked from its own name.
        $written += $this->linkBranch($branch, $payload, $repository, $repositories);

        // The most recent commits. A force-push of a long-lived branch is one
        // delivery carrying its whole history; the cap keeps that bounded and
        // the newest commits are the ones anybody looks at.
        $commits = array_slice(
            array_reverse((array) Arr::get($payload, 'commits', [])),
            0,
            (int) config('github.max_commits_per_push', 20)
        );

        foreach ($commits as $commit) {
            $sha = (string) Arr::get($commit, 'id', '');
            $message = (string) Arr::get($commit, 'message', '');

            if ($sha === '') {
                continue;
            }

            // The branch name counts for every commit on it, so a team that
            // names the branch once gets all of its commits linked.
            $tickets = $this->ticketsFor([$message, $branch], $repositories);

            foreach ($tickets as $ticket) {
                $written += $this->upsert($ticket, $repositories, [
                    'repository' => $repository,
                    'type' => GithubLinkType::Commit,
                    'external_id' => $sha,
                    'reference' => $sha,
                    'title' => Str::limit(strtok($message, "\n") ?: $message, 290),
                    'url' => (string) Arr::get($commit, 'url', $this->commitUrl($payload, $repository, $sha)),
                    'author_login' => Arr::get($commit, 'author.username'),
                    'metadata' => ['branch' => $branch],
                ]);
            }
        }

        return $written;
    }

    /**
     * A branch created through the GitHub UI or a `git push -u`.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function handleCreate(array $payload, string $repository, Collection $repositories): int
    {
        if (Arr::get($payload, 'ref_type') !== 'branch') {
            return 0;
        }

        return $this->linkBranch((string) Arr::get($payload, 'ref', ''), $payload, $repository, $repositories);
    }

    /**
     * A pull request opened, edited, closed, merged or marked ready.
     *
     * References are taken from the title, the body and the head branch, in
     * that order of likelihood.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function handlePullRequest(array $payload, string $repository, Collection $repositories): int
    {
        $pr = (array) Arr::get($payload, 'pull_request', []);
        $number = (int) Arr::get($pr, 'number', 0);

        if ($number === 0) {
            return 0;
        }

        $state = GithubLinkState::fromPullRequest(
            Arr::get($pr, 'state'),
            (bool) Arr::get($pr, 'merged', false),
            (bool) Arr::get($pr, 'draft', false),
        );

        $tickets = $this->ticketsFor([
            Arr::get($pr, 'title'),
            Arr::get($pr, 'body'),
            Arr::get($pr, 'head.ref'),
        ], $repositories);

        $written = 0;

        foreach ($tickets as $ticket) {
            $written += $this->upsert($ticket, $repositories, [
                'repository' => $repository,
                'type' => GithubLinkType::PullRequest,
                'external_id' => (string) $number,
                'reference' => '#'.$number,
                'title' => Str::limit((string) Arr::get($pr, 'title', ''), 290),
                'url' => (string) Arr::get($pr, 'html_url', ''),
                'state' => $state,
                'author_login' => Arr::get($pr, 'user.login'),
                'merged_at' => Arr::get($pr, 'merged_at'),
                'metadata' => [
                    'head' => Arr::get($pr, 'head.ref'),
                    'base' => Arr::get($pr, 'base.ref'),
                    'action' => Arr::get($payload, 'action'),
                ],
            ]);
        }

        return $written;
    }

    /**
     * CI finished for a commit, and possibly for the pull requests containing it.
     *
     * Updates existing links only. A check suite mentions no ticket key of its
     * own, so there is nothing here that could create a link — which is also
     * why it cannot be used to attach CI noise to an unrelated ticket.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleCheckSuite(array $payload, string $repository): int
    {
        $suite = (array) Arr::get($payload, 'check_suite', []);

        // `conclusion` is null while the suite is still running; the status is
        // the honest answer in that case.
        $status = (string) (Arr::get($suite, 'conclusion') ?? Arr::get($suite, 'status') ?? '');

        if ($status === '') {
            return 0;
        }

        $updated = $this->applyCiStatus(
            $repository,
            GithubLinkType::Commit,
            [(string) Arr::get($suite, 'head_sha', '')],
            $status,
        );

        $pullRequestNumbers = array_values(array_filter(array_map(
            fn ($pr): string => (string) (Arr::get($pr, 'number') ?? ''),
            (array) Arr::get($suite, 'pull_requests', []),
        )));

        return $updated + $this->applyCiStatus(
            $repository,
            GithubLinkType::PullRequest,
            $pullRequestNumbers,
            $status,
        );
    }

    /**
     * The older commit-status API, still used by plenty of tooling.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleStatus(array $payload, string $repository): int
    {
        $state = (string) Arr::get($payload, 'state', '');
        $sha = (string) Arr::get($payload, 'sha', '');

        if ($state === '' || $sha === '') {
            return 0;
        }

        // The commit-status vocabulary differs from the check-suite one; both
        // are normalised so the badge logic has a single set of values.
        $status = match ($state) {
            'error' => 'failure',
            default => $state,
        };

        return $this->applyCiStatus($repository, GithubLinkType::Commit, [$sha], $status);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function linkBranch(string $branch, array $payload, string $repository, Collection $repositories): int
    {
        if ($branch === '') {
            return 0;
        }

        $written = 0;

        foreach ($this->ticketsFor([$branch], $repositories) as $ticket) {
            $written += $this->upsert($ticket, $repositories, [
                'repository' => $repository,
                'type' => GithubLinkType::Branch,
                'external_id' => $branch,
                'reference' => $branch,
                'title' => null,
                'url' => $this->branchUrl($payload, $repository, $branch),
                'author_login' => Arr::get($payload, 'sender.login'),
            ]);
        }

        return $written;
    }

    /**
     * Create or update one link.
     *
     * Written attribute by attribute rather than with `updateOrCreate`, because
     * GithubLink has an empty `$fillable` on purpose: nothing about a link may
     * be set from an array that arrived over the network. The identity lookup
     * is the same set of columns as the unique index, so a race between two
     * deliveries about the same object ends in the constraint rather than in
     * two rows.
     *
     * @param  Collection<int, BoardRepository>  $repositories
     * @param  array<string, mixed>  $attributes
     * @return int 1 if a row was written, 0 if nothing changed
     */
    private function upsert(Ticket $ticket, Collection $repositories, array $attributes): int
    {
        $link = GithubLink::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('repository', $attributes['repository'])
            ->where('type', $attributes['type']->value)
            ->where('external_id', $attributes['external_id'])
            ->first() ?? new GithubLink;

        $link->ticket_id = (int) $ticket->getKey();
        $link->board_id = (int) $ticket->board_id;
        $link->board_repository_id = $this->repositoryIdFor($repositories, $ticket);
        $link->repository = $attributes['repository'];
        $link->type = $attributes['type'];
        $link->external_id = $attributes['external_id'];
        $link->reference = $attributes['reference'];
        $link->title = $attributes['title'] ?? null;
        $link->url = $attributes['url'];
        $link->author_login = $attributes['author_login'] ?? null;

        // Only overwrite state and merge time when the event carries them. A
        // push to a branch that already has a merged pull request must not
        // reset that pull request's state, and these two keys are absent from
        // exactly the events that know nothing about it.
        if (array_key_exists('state', $attributes)) {
            $link->state = $attributes['state'];
        }

        if (array_key_exists('merged_at', $attributes)) {
            $link->merged_at = $attributes['merged_at'];
        }

        // Merged rather than replaced, so a later event does not discard
        // context an earlier one recorded.
        $link->metadata = array_filter(
            array_merge((array) ($link->metadata ?? []), (array) ($attributes['metadata'] ?? [])),
            static fn ($value): bool => $value !== null,
        );

        $link->save();

        return 1;
    }

    /**
     * Set the CI status on links already pointing at these objects.
     *
     * @param  array<int, string>  $externalIds
     */
    private function applyCiStatus(string $repository, GithubLinkType $type, array $externalIds, string $status): int
    {
        $externalIds = array_values(array_filter($externalIds, static fn (string $id): bool => $id !== ''));

        if ($externalIds === []) {
            return 0;
        }

        return GithubLink::query()
            ->where('repository', $repository)
            ->where('type', $type->value)
            ->whereIn('external_id', $externalIds)
            ->update(['ci_status' => $status, 'updated_at' => now()]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Tickets mentioned in these strings, on boards this repository serves.
     *
     * @param  array<int, ?string>  $texts
     * @param  Collection<int, BoardRepository>  $repositories
     * @return Collection<int, Ticket>
     */
    private function ticketsFor(array $texts, Collection $repositories): Collection
    {
        $references = $this->resolver->extract($texts);

        if ($references === []) {
            return collect();
        }

        return $this->resolver->resolve(
            $references,
            $repositories->map(fn (BoardRepository $repository): int => (int) $repository->board_id)->all(),
        );
    }

    /**
     * Which configured repository row this ticket's board owns, if any.
     *
     * @param  Collection<int, BoardRepository>  $repositories
     */
    private function repositoryIdFor(Collection $repositories, Ticket $ticket): ?int
    {
        $match = $repositories->firstWhere('board_id', $ticket->board_id);

        return $match instanceof BoardRepository ? (int) $match->getKey() : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function branchUrl(array $payload, string $repository, string $branch): string
    {
        $base = (string) Arr::get($payload, 'repository.html_url', 'https://github.com/'.$repository);

        return rtrim($base, '/').'/tree/'.$branch;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitUrl(array $payload, string $repository, string $sha): string
    {
        $base = (string) Arr::get($payload, 'repository.html_url', 'https://github.com/'.$repository);

        return rtrim($base, '/').'/commit/'.$sha;
    }
}
