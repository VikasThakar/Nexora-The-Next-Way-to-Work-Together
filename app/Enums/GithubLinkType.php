<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of GitHub activity that can reference a ticket.
 *
 * Three, and deliberately no more. A ticket's engineering trail is "somebody
 * started a branch, commits landed on it, a pull request was opened" — anything
 * further (reviews, comments, deployments) belongs in GitHub, and mirroring it
 * here would make the panel a worse copy of a better tool.
 */
enum GithubLinkType: string
{
    case Branch = 'branch';
    case Commit = 'commit';
    case PullRequest = 'pull_request';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Branch',
            self::Commit => 'Commit',
            self::PullRequest => 'Pull request',
        };
    }

    /**
     * Order in the panel: the pull request is the headline, the branch is
     * context, and the commits are detail.
     */
    public function weight(): int
    {
        return match ($this) {
            self::PullRequest => 0,
            self::Branch => 1,
            self::Commit => 2,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
