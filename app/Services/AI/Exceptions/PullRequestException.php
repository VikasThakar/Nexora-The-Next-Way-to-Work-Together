<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * A pull request could not be opened.
 *
 * Reached only after a branch has already been pushed, so every message here
 * says what exists and what does not: the work is not lost, it is on a branch,
 * and somebody can open the pull request by hand.
 */
class PullRequestException extends RuntimeException
{
    public static function noCredential(): self
    {
        return new self(
            'No GITHUB_TOKEN is configured, so no pull request could be opened. '
            .'Set it on the queue worker service with contents: write and pull_requests: write.'
        );
    }

    public static function unresolvableRepository(string $name): self
    {
        return new self(
            'The repository "'.$name.'" is not spelled as owner/name, so no pull request could '
            .'be opened for it. The branch was pushed and can be turned into a pull request by hand.'
        );
    }

    public static function unreachable(string $message): self
    {
        return new self(
            'Could not reach GitHub to open the pull request ('.trim($message).'). '
            .'The branch was pushed, so it can be opened by hand.'
        );
    }

    public static function rejected(int $status, string $message, string $details): self
    {
        return new self(trim(sprintf(
            'GitHub refused to open the pull request (HTTP %d): %s%s The branch was pushed, so '
            .'it can be opened by hand.',
            $status,
            trim($message),
            $details === '' ? '' : ' — '.$details.'.',
        )));
    }
}
