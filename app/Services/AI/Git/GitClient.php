<?php

declare(strict_types=1);

namespace App\Services\AI\Git;

use App\Services\AI\Exceptions\GitException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs git, inside one directory, without a shell.
 *
 * Every invocation goes through run(), which takes an argument *array*. There
 * is no string command anywhere in this class, so a branch name derived from a
 * ticket title — text a customer wrote — cannot become a shell metacharacter.
 * That is the single most important property here.
 *
 * Credentials
 * -----------
 * The clone URL is built with the token embedded, because git has no other
 * portable way to authenticate a one-off HTTPS clone in a container. Two things
 * follow, and both are implemented:
 *
 *   - the token is never written to disk. `git remote add` would store it in
 *     .git/config inside a tree an agentic coding tool is about to be pointed
 *     at, so the authenticated URL is passed per command instead and the stored
 *     remote is the clean one.
 *   - the token never reaches an error message. redact() strips it from stdout
 *     and stderr before either is used, because git prints the remote URL in
 *     several of its own failure messages.
 */
class GitClient
{
    /**
     * Clone a repository into a directory that does not yet exist.
     *
     * Shallow and single-branch by default: history is rarely what the model
     * needs and a full clone of a large repository will not finish inside a job
     * timeout.
     */
    public function cloneRepository(
        string $authenticatedUrl,
        string $directory,
        string $branch,
        ?string $cleanUrl = null,
    ): void {
        $depth = max(0, (int) config('ai.repository.clone_depth', 1));

        $arguments = ['clone', '--single-branch', '--branch', $branch];

        if ($depth > 0) {
            $arguments[] = '--depth';
            $arguments[] = (string) $depth;
        }

        $arguments[] = $authenticatedUrl;
        $arguments[] = $directory;

        // Run from the parent: the target does not exist yet.
        $this->run($arguments, dirname($directory));

        // Replace the authenticated remote with the clean one so no token is
        // left in .git/config for anything else in this tree to read.
        if ($cleanUrl !== null) {
            $this->run(['remote', 'set-url', 'origin', $cleanUrl], $directory);
        }
    }

    /**
     * Configure the identity commits are made under.
     *
     * Local to the checkout, never global: the worker container may be shared
     * and a global identity would outlive this run.
     */
    public function configureIdentity(string $directory, string $name, string $email): void
    {
        $this->run(['config', 'user.name', $name], $directory);
        $this->run(['config', 'user.email', $email], $directory);
    }

    public function createBranch(string $directory, string $branch): void
    {
        $this->run(['checkout', '-b', $branch], $directory);
    }

    public function currentBranch(string $directory): string
    {
        return trim($this->run(['rev-parse', '--abbrev-ref', 'HEAD'], $directory));
    }

    /**
     * Is there anything to commit?
     *
     * Asked before committing so an apply run that changed nothing fails with
     * "the model made no changes" rather than opening an empty pull request.
     */
    public function hasChanges(string $directory): bool
    {
        return trim($this->run(['status', '--porcelain'], $directory)) !== '';
    }

    /**
     * Files touched, relative to the repository root.
     *
     * @return list<string>
     */
    public function changedFiles(string $directory): array
    {
        $output = $this->run(['status', '--porcelain'], $directory);

        $files = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($output)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            // Porcelain v1: two status characters, a space, then the path. A
            // rename is "R  old -> new"; the new name is the interesting one.
            $path = trim(mb_substr($line, 3));

            if (str_contains($path, ' -> ')) {
                $path = substr($path, (int) strpos($path, ' -> ') + 4);
            }

            $files[] = trim($path, '"');
        }

        return $files;
    }

    /**
     * The diff of the working tree, truncated.
     *
     * Used for the internal note. Truncated because a note is read by a person:
     * a 4,000-line diff in a ticket comment is not review material, it is a
     * wall, and the pull request is where the real diff lives.
     */
    public function diff(string $directory, int $maxBytes = 20000): string
    {
        $diff = $this->run(['diff', '--stat', 'HEAD'], $directory);

        return mb_strlen($diff) > $maxBytes
            ? mb_substr($diff, 0, $maxBytes)."\n… truncated"
            : $diff;
    }

    public function commitAll(string $directory, string $message): void
    {
        $this->run(['add', '--all'], $directory);

        // -m with the message as its own argument: a commit message built from
        // a ticket title never reaches a shell.
        $this->run(['commit', '-m', $message], $directory);
    }

    /**
     * Push a branch.
     *
     * The refspec is written out in full — `refs/heads/x:refs/heads/x` — so a
     * branch name that happens to look like an option or another ref cannot be
     * reinterpreted by git.
     */
    public function pushBranch(string $directory, string $authenticatedUrl, string $branch): void
    {
        $this->run(
            ['push', $authenticatedUrl, 'refs/heads/'.$branch.':refs/heads/'.$branch],
            $directory
        );
    }

    public function isAvailable(): bool
    {
        try {
            $this->run(['--version'], sys_get_temp_dir());

            return true;
        } catch (GitException) {
            return false;
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, string $workingDirectory): string
    {
        $binary = (string) config('ai.repository.git_binary', 'git');

        $process = new Process(
            command: array_merge([$binary], $arguments),
            cwd: $workingDirectory,
            env: [
                // Never stop for a credential prompt: a worker has no terminal,
                // and without this git can hang until the job times out.
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_ASKPASS' => '',
                'GCM_INTERACTIVE' => 'never',
            ],
            timeout: (float) config('ai.repository.git_timeout', 300),
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw GitException::timedOut($this->describe($arguments));
        }

        if (! $process->isSuccessful()) {
            throw GitException::failed(
                $this->describe($arguments),
                $this->redact($process->getErrorOutput().$process->getOutput()),
            );
        }

        return $process->getOutput();
    }

    /**
     * A safe description of the command for an error message.
     *
     * Only the subcommand and its flags: a URL argument would carry the token.
     *
     * @param  list<string>  $arguments
     */
    private function describe(array $arguments): string
    {
        $safe = array_filter(
            $arguments,
            static fn (string $argument): bool => ! str_contains($argument, '://')
        );

        return 'git '.implode(' ', array_slice(array_values($safe), 0, 3));
    }

    /**
     * Strip anything that looks like credentials in a URL.
     *
     * git prints the remote URL in several of its own failure messages, so this
     * runs over every byte of output before it can reach a note or a log.
     */
    private function redact(string $output): string
    {
        $output = (string) preg_replace('#(https?://)[^/@\s]+@#i', '$1***@', $output);

        $token = (string) config('github.token');

        if ($token !== '') {
            $output = str_replace($token, '***', $output);
        }

        return trim($output);
    }
}
