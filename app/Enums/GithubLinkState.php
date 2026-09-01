<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What state a linked pull request is in.
 *
 * `Merged` is not a state GitHub sends — its payload says `state: closed` with
 * `merged: true`. Collapsing that into one field here is worth the translation
 * because "closed" and "merged" mean opposite things to somebody reading a
 * ticket, and a panel that shows a merged pull request as "closed" is actively
 * misleading.
 *
 * Branches and commits carry no state and store null.
 */
enum GithubLinkState: string
{
    case Open = 'open';
    case Draft = 'draft';
    case Merged = 'merged';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Draft => 'Draft',
            self::Merged => 'Merged',
            self::Closed => 'Closed',
        };
    }

    /** Maps to the x-ui.badge variants so state reads the same everywhere. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'emerald',
            self::Draft => 'slate',
            self::Merged => 'brand',
            self::Closed => 'rose',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Merged || $this === self::Closed;
    }

    /**
     * Translate a GitHub pull-request payload into one state.
     *
     * Order matters: merged wins over closed, and draft over open.
     */
    public static function fromPullRequest(?string $state, bool $merged = false, bool $draft = false): self
    {
        if ($merged) {
            return self::Merged;
        }

        if ($state === 'closed') {
            return self::Closed;
        }

        return $draft ? self::Draft : self::Open;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
