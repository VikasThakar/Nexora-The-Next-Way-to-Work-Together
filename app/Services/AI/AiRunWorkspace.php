<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiRun;
use App\Services\AI\Exceptions\AiWorkspaceException;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The isolated scratch directory one run is allowed to touch.
 *
 * Three rules, and the reason each exists:
 *
 *   one directory per run     named after the run's UUID. Two runs never share
 *                             a tree, so a retry cannot inherit half-finished
 *                             work from the attempt before it and a run on
 *                             board A cannot see board B's checkout.
 *   never the application     the path is resolved under storage/app and the
 *                             resolved result is checked to be inside it. An
 *                             agentic coding tool pointed at the application's
 *                             own directory would be free to edit this file.
 *   always cleaned up         release() runs whether the run succeeded, failed
 *                             or threw, from a finally block in the job.
 *
 * Nothing here is state. Railway replaces the container filesystem on every
 * deploy, so anything worth keeping — the analysis, the pull request URL, the
 * token counts, the error — is a column on `ai_runs` before this directory is
 * deleted. The tree is a means, not a record.
 */
class AiRunWorkspace
{
    /**
     * Create the run's directory, empty.
     *
     * @return string absolute path
     */
    public function acquire(AiRun $run): string
    {
        $path = $this->pathFor($run);

        // A retry of the same run reuses the same UUID, so an abandoned tree
        // from a previous attempt has to go before this one starts. Inheriting
        // a partial clone is exactly the kind of failure that only reproduces
        // in production.
        if (File::isDirectory($path)) {
            $this->delete($path);
        }

        if (! File::makeDirectory($path, 0700, recursive: true)) {
            throw AiWorkspaceException::notWritable($path);
        }

        return $path;
    }

    /**
     * Delete the run's directory.
     *
     * Never throws: a run that produced a good result must not be reported as
     * failed because a temporary directory would not go away. The failure is
     * recorded on the run's metadata by the caller instead.
     */
    public function release(AiRun $run, bool $succeeded = true): bool
    {
        if (! $succeeded && (bool) config('ai.workspace.keep_on_failure', false)) {
            return false;
        }

        try {
            $this->delete($this->pathFor($run));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Where a run's tree lives.
     *
     * The containment check is the security-relevant part. `path` is
     * configuration, and configuration on a hosted platform is an environment
     * variable somebody can mistype; `../..` there must not resolve to the
     * application root.
     */
    public function pathFor(AiRun $run): string
    {
        $configured = (string) config('ai.workspace.path', 'ai-runs');

        $base = $this->isAbsolute($configured)
            ? $this->normalise($configured)
            : $this->normalise(storage_path('app/'.trim($configured, '/\\')));

        $uuid = (string) $run->uuid;

        // The UUID comes from Str::uuid() when the run is created, but it is
        // read back out of the database, so it is validated rather than trusted.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw AiWorkspaceException::invalidIdentifier($uuid);
        }

        $path = $base.DIRECTORY_SEPARATOR.$uuid;

        if (! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            throw AiWorkspaceException::escapedBase($path, $base);
        }

        return $path;
    }

    /**
     * The subdirectory a repository is cloned into.
     *
     * A level below the run root so the run can also hold things that are not
     * the repository — a prompt transcript, validation output — without them
     * ending up inside somebody's checkout and being committed.
     */
    public function checkoutPathFor(AiRun $run): string
    {
        return $this->pathFor($run).DIRECTORY_SEPARATOR.'repository';
    }

    private function delete(string $path): void
    {
        if (! File::isDirectory($path)) {
            return;
        }

        // deleteDirectory rather than a shell rm: no shell, no quoting, and it
        // works the same on the Windows development machine as in the container.
        File::deleteDirectory($path);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * Resolve `.` and `..` without requiring the path to exist.
     *
     * realpath() would return false for a directory that has not been created
     * yet, which is the normal case here.
     */
    private function normalise(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $isUnixAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $segments = explode(DIRECTORY_SEPARATOR, $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        $joined = implode(DIRECTORY_SEPARATOR, $resolved);

        return $isUnixAbsolute ? DIRECTORY_SEPARATOR.$joined : $joined;
    }
}
