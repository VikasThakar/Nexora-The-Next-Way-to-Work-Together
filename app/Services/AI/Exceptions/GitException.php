<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * A git command failed.
 *
 * Messages here are assembled from a redacted description and redacted output
 * (see GitClient::describe and GitClient::redact), never from a raw command
 * line, because a clone or push argument carries the access token.
 */
class GitException extends RuntimeException
{
    public static function failed(string $command, string $output): self
    {
        return new self(
            $command.' failed'.($output === '' ? '.' : ': '.mb_substr($output, 0, 1000))
        );
    }

    public static function timedOut(string $command): self
    {
        return new self($command.' did not finish within the configured git timeout.');
    }

    public static function notAvailable(): self
    {
        return new self(
            'git is not available on this worker, so no repository could be checked out. '
            .'Install git in the worker image or set AI_GIT_BINARY.'
        );
    }

    public static function noCloneUrl(string $repository): self
    {
        return new self(
            'No clone URL could be derived for '.$repository
            .'. Set the repository URL, or name it as owner/name.'
        );
    }

    public static function noCredential(): self
    {
        return new self(
            'No GITHUB_TOKEN is configured, so the repository cannot be cloned or pushed. '
            .'Set it on the queue worker service.'
        );
    }

    public static function protectedBranch(string $branch): self
    {
        return new self(
            'Refusing to work directly on the protected branch "'.$branch.'". '
            .'Apply mode always commits to a new branch.'
        );
    }

    public static function nothingChanged(): self
    {
        return new self('The run produced no file changes, so no branch was pushed and no pull request was opened.');
    }
}
