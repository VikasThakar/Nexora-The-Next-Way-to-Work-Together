<?php

declare(strict_types=1);

namespace App\Services\AI\Git;

use App\Models\AiRun;
use App\Models\BoardRepository;
use App\Services\AI\AiRunWorkspace;
use App\Services\AI\Exceptions\GitException;

/**
 * Puts a repository on disk, inside one run's isolated directory.
 *
 * Kept separate from GitClient — which knows only how to run git — so the
 * decisions live in one readable place: whether a checkout is possible at all,
 * which branch, and how the token is applied without being stored.
 *
 * `attempt()` and `require()` are the two entry points and the distinction is
 * the point:
 *
 *   attempt()   suggest mode. A checkout is a bonus. If cloning is switched
 *               off, git is missing, or there is no token, the run continues
 *               with repository metadata only and the reason is recorded — the
 *               note then says the working tree was unavailable rather than
 *               implying the code was read.
 *   require()   apply mode. A checkout is the whole job. Anything missing is a
 *               refusal with a message naming exactly what to configure.
 */
class RepositoryCheckout
{
    public function __construct(
        private readonly GitClient $git,
        private readonly AiRunWorkspace $workspace,
    ) {}

    /**
     * Clone if everything needed is present; otherwise say why not.
     *
     * @return array{path: ?string, branch: ?string, reason: ?string}
     */
    public function attempt(AiRun $run, BoardRepository $repository): array
    {
        $blocked = $this->blocker($repository);

        if ($blocked !== null) {
            return ['path' => null, 'branch' => null, 'reason' => $blocked];
        }

        try {
            $path = $this->checkout($run, $repository);
        } catch (GitException $exception) {
            return ['path' => null, 'branch' => null, 'reason' => $exception->getMessage()];
        }

        return [
            'path' => $path,
            'branch' => $repository->default_branch ?: 'main',
            'reason' => null,
        ];
    }

    /**
     * Clone, or refuse the run.
     *
     * @throws GitException
     */
    public function require(AiRun $run, BoardRepository $repository): string
    {
        $blocked = $this->blocker($repository, requireClone: true);

        if ($blocked !== null) {
            throw new GitException($blocked);
        }

        return $this->checkout($run, $repository);
    }

    /**
     * The URL with the token embedded, used per command and never stored.
     *
     * Public because apply mode has to push with it after the checkout is
     * already on disk.
     */
    public function authenticatedUrl(BoardRepository $repository): string
    {
        $url = $repository->cloneUrl();

        if ($url === null) {
            throw GitException::noCloneUrl($repository->repository_name);
        }

        $token = (string) config('github.token');

        if ($token === '' || ! str_starts_with($url, 'https://')) {
            return $url;
        }

        // x-access-token is the username GitHub expects for a token; the same
        // form works for a PAT and for an app installation token.
        return preg_replace(
            '#^https://#',
            'https://x-access-token:'.rawurlencode($token).'@',
            $url,
            1
        ) ?? $url;
    }

    // -----------------------------------------------------------------

    private function checkout(AiRun $run, BoardRepository $repository): string
    {
        $path = $this->workspace->checkoutPathFor($run);
        $branch = $repository->default_branch ?: 'main';

        $this->git->cloneRepository(
            authenticatedUrl: $this->authenticatedUrl($repository),
            directory: $path,
            branch: $branch,
            cleanUrl: $repository->cloneUrl(),
        );

        return $path;
    }

    /**
     * Why a checkout cannot happen, or null when it can.
     *
     * One method for both entry points so the two modes cannot end up
     * disagreeing about what counts as configured.
     */
    private function blocker(BoardRepository $repository, bool $requireClone = false): ?string
    {
        if (! $requireClone && ! (bool) config('ai.repository.clone_enabled', false)) {
            return 'Repository cloning is switched off for this deployment (AI_REPOSITORY_CLONE_ENABLED).';
        }

        if ($repository->cloneUrl() === null) {
            return GitException::noCloneUrl($repository->repository_name)->getMessage();
        }

        if (blank(config('github.token'))) {
            return GitException::noCredential()->getMessage();
        }

        if (! $this->git->isAvailable()) {
            return GitException::notAvailable()->getMessage();
        }

        return null;
    }
}
