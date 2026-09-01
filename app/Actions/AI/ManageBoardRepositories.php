<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\Board;
use App\Models\BoardRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attach, edit, reorder and detach a board's repositories.
 *
 * One class rather than four, because all four operations share one invariant
 * that has to be maintained together: **at most one primary repository per
 * board**. MySQL cannot express that without a functional unique index that
 * would also forbid two non-primary rows, so it is maintained here, inside a
 * transaction, by clearing the flag on the siblings whenever it is set.
 *
 * The first repository attached to a board becomes its primary automatically. A
 * board with exactly one repository and no primary flag would work — the
 * selector's first rule covers it — but the flag would then appear the moment a
 * second one arrived and change which repository runs use, silently. Setting it
 * up front makes that stable.
 *
 * No credentials are accepted anywhere here. The token used to clone and push is
 * one deployment-wide secret in the environment, so a board administrator can
 * never enter one through a form and can never read one back out.
 */
class ManageBoardRepositories
{
    /**
     * @param  array{
     *     repository_name: string,
     *     repository_url?: ?string,
     *     default_branch?: ?string,
     *     description?: ?string,
     *     configuration?: array<string, mixed>|null,
     *     is_primary?: bool
     * }  $attributes
     */
    public function create(Board $board, array $attributes): BoardRepository
    {
        return DB::transaction(function () use ($board, $attributes): BoardRepository {
            $name = $this->normaliseName($attributes['repository_name'] ?? '');

            if ($board->repositories()->where('repository_name', $name)->exists()) {
                throw new RuntimeException('That repository is already attached to this board.');
            }

            $repository = new BoardRepository([
                'repository_name' => $name,
                'repository_url' => $this->url($attributes['repository_url'] ?? null),
                'default_branch' => $this->branch($attributes['default_branch'] ?? null),
                'description' => $this->text($attributes['description'] ?? null),
                'configuration' => $this->configuration($attributes['configuration'] ?? null),
            ]);

            // Not mass assignable: which board a repository belongs to is
            // identity, not form input.
            $repository->board_id = $board->getKey();

            // The first one is the primary, so the selector's answer does not
            // change the day a second repository is attached.
            $isFirst = ! $board->repositories()->exists();
            $repository->is_primary = $isFirst || (bool) ($attributes['is_primary'] ?? false);

            $repository->save();

            if ($repository->is_primary) {
                $this->clearOtherPrimaries($board, $repository);
            }

            return $repository;
        });
    }

    /**
     * @param  array{
     *     repository_name?: string,
     *     repository_url?: ?string,
     *     default_branch?: ?string,
     *     description?: ?string,
     *     configuration?: array<string, mixed>|null
     * }  $attributes
     */
    public function update(BoardRepository $repository, array $attributes): BoardRepository
    {
        return DB::transaction(function () use ($repository, $attributes): BoardRepository {
            if (array_key_exists('repository_name', $attributes)) {
                $name = $this->normaliseName($attributes['repository_name']);

                $clash = BoardRepository::query()
                    ->where('board_id', $repository->board_id)
                    ->where('repository_name', $name)
                    ->whereKeyNot($repository->getKey())
                    ->exists();

                if ($clash) {
                    throw new RuntimeException('Another repository on this board already has that name.');
                }

                $repository->repository_name = $name;
            }

            if (array_key_exists('repository_url', $attributes)) {
                $repository->repository_url = $this->url($attributes['repository_url']);
            }

            if (array_key_exists('default_branch', $attributes)) {
                $repository->default_branch = $this->branch($attributes['default_branch']);
            }

            if (array_key_exists('description', $attributes)) {
                $repository->description = $this->text($attributes['description']);
            }

            if (array_key_exists('configuration', $attributes)) {
                $repository->configuration = $this->configuration($attributes['configuration']);
            }

            $repository->save();

            return $repository;
        });
    }

    public function makePrimary(BoardRepository $repository): BoardRepository
    {
        return DB::transaction(function () use ($repository): BoardRepository {
            $repository->is_primary = true;
            $repository->save();

            $repository->loadMissing('board');
            $this->clearOtherPrimaries($repository->board, $repository);

            return $repository;
        });
    }

    /**
     * Detach a repository, keeping a primary if one is still possible.
     *
     * Removing the primary would otherwise leave a board where the selector
     * falls through to "several repositories, nothing to distinguish them" and
     * apply mode stops working — a surprising consequence of deleting an
     * unrelated row.
     */
    public function delete(BoardRepository $repository): void
    {
        DB::transaction(function () use ($repository): void {
            $wasPrimary = (bool) $repository->is_primary;
            $boardId = (int) $repository->board_id;

            $repository->delete();

            if (! $wasPrimary) {
                return;
            }

            $successor = BoardRepository::query()
                ->where('board_id', $boardId)
                ->orderBy('repository_name')
                ->first();

            if ($successor instanceof BoardRepository) {
                $successor->is_primary = true;
                $successor->save();
            }
        });
    }

    // -----------------------------------------------------------------

    private function clearOtherPrimaries(Board $board, BoardRepository $keep): void
    {
        BoardRepository::query()
            ->where('board_id', $board->getKey())
            ->whereKeyNot($keep->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }

    /**
     * `owner/name`, trimmed of decoration people paste.
     *
     * A URL pasted into the name field, a trailing `.git`, a leading slash: all
     * of them are what actually gets typed, and all of them would otherwise
     * produce a name the GitHub API cannot resolve.
     */
    private function normaliseName(string $name): string
    {
        $name = trim($name);

        // A full URL in the name field: keep the last two path segments.
        if (preg_match('#^(?:https?://|git@)[^/:]+[/:](.+)$#i', $name, $matches) === 1) {
            $name = $matches[1];
        }

        $name = preg_replace('/\.git$/i', '', $name) ?? $name;
        $name = trim($name, '/ ');

        if ($name === '') {
            throw new RuntimeException('A repository needs a name.');
        }

        return $name;
    }

    private function url(mixed $url): ?string
    {
        $url = $this->text($url);

        if ($url === null) {
            return null;
        }

        // Only http(s). An ssh:// or file:// remote would be cloned by a worker
        // that has no key and no business reading the local filesystem.
        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private function branch(mixed $branch): string
    {
        $branch = $this->text($branch);

        if ($branch === null) {
            return 'main';
        }

        // A branch name reaches git as an argument, so anything outside the safe
        // set is stripped rather than escaped.
        $branch = preg_replace('#[^A-Za-z0-9._/\-]#', '', $branch) ?? '';

        return $branch === '' ? 'main' : mb_substr($branch, 0, 100);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configuration(mixed $configuration): ?array
    {
        if (! is_array($configuration)) {
            return null;
        }

        $clean = [];

        foreach ($configuration as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $key = mb_substr(trim($key), 0, 60);
            $value = mb_substr(trim((string) $value), 0, 500);

            if ($key !== '' && $value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
