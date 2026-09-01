<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommentStream;
use App\Enums\UserRole;
use App\Models\Board;
use App\Models\User;
use App\Support\HtmlText;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds and resolves @mentions.
 *
 * Syntax is deliberately plain: an `@` followed by a handle. A handle is either
 * the dotted form of a person's name (`@team.member`) or the local part of
 * their email address (`@vikas`). No picker, no stored mention table, no second
 * syntax to teach — the body of the comment stays readable Markdown.
 *
 * The security rule lives in candidates():
 *
 *   Mentions only ever resolve against members of the board, and for an
 *   internal note only against staff members.
 *
 * That single restriction gives two properties at once. A mention cannot be
 * used to probe which accounts exist in the workspace, because a handle that is
 * not on this board simply does not resolve. And a customer can never be
 * notified about an internal note, because their handle is not a candidate when
 * the stream is internal — there is no separate "do not notify customers"
 * check to forget.
 */
class MentionParser
{
    /**
     * `@` not preceded by a word character (so an email address in prose is not
     * a mention), followed by a handle of letters, digits, dots, hyphens and
     * underscores.
     */
    private const PATTERN = '/(?<![\w@.])@([A-Za-z0-9][A-Za-z0-9._-]{0,39})/';

    /** @var array<string, Collection<int, User>> */
    private array $memoisedCandidates = [];

    /**
     * The people who may be mentioned in this context.
     *
     * @return Collection<int, User>
     */
    public function candidates(Board $board, CommentStream|bool $streamOrCustomerFacing = true): Collection
    {
        $customerFacing = $streamOrCustomerFacing instanceof CommentStream
            ? $streamOrCustomerFacing->isCustomerFacing()
            : $streamOrCustomerFacing;

        $key = $board->getKey().':'.($customerFacing ? 'c' : 'i');

        return $this->memoisedCandidates[$key] ??= $board->members()
            ->whereNull('users.deactivated_at')
            ->when(
                ! $customerFacing,
                // Internal notes: staff only. A customer is not a candidate, so
                // their handle cannot resolve and they cannot be notified.
                fn ($query) => $query->where('users.role', '!=', UserRole::Customer->value)
            )
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Every handle written in a body, in order, without duplicates.
     *
     * @return array<int, string>
     */
    public function handlesIn(?string $body): array
    {
        if (trim((string) $body) === '') {
            return [];
        }

        preg_match_all(self::PATTERN, (string) $body, $matches);

        return array_values(array_unique(array_map(
            static fn (string $handle): string => Str::lower($handle),
            $matches[1] ?? []
        )));
    }

    /**
     * Resolve the handles written in a body to actual people.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function resolve(?string $body, Collection $candidates): Collection
    {
        $handles = $this->handlesIn($body);

        if ($handles === []) {
            return collect();
        }

        $index = $this->index($candidates);

        return collect($handles)
            ->map(fn (string $handle): ?User => $index[$handle] ?? null)
            ->filter()
            ->unique(fn (User $user): int => $user->getKey())
            ->values();
    }

    /**
     * The handles a person answers to, most specific first.
     *
     * @return array<int, string>
     */
    public function handlesFor(User $user): array
    {
        $handles = [];

        $local = Str::before((string) $user->email, '@');

        if ($local !== '') {
            $handles[] = Str::lower($local);
        }

        $slug = Str::slug((string) $user->name, '.');

        if ($slug !== '') {
            $handles[] = $slug;
        }

        return array_values(array_unique($handles));
    }

    /**
     * The handle to show people in the composer hint.
     */
    public function primaryHandleFor(User $user): string
    {
        return $this->handlesFor($user)[0] ?? '';
    }

    /**
     * Wrap resolved handles in rendered HTML so a mention is visible as one.
     *
     * Unresolved handles are left as plain text: an `@something` that matches
     * nobody on this board must look no different from any other word, or the
     * styling itself becomes a way to test whether an account exists.
     *
     * @param  Collection<int, User>  $candidates
     */
    public function highlight(string $html, Collection $candidates): string
    {
        if ($html === '' || $candidates->isEmpty()) {
            return $html;
        }

        $index = $this->index($candidates);

        return HtmlText::mapText($html, function (string $text) use ($index): string {
            return (string) preg_replace_callback(
                self::PATTERN,
                function (array $m) use ($index): string {
                    $user = $index[Str::lower($m[1])] ?? null;

                    if (! $user instanceof User) {
                        return $m[0];
                    }

                    return '<span class="mention" title="'.e($user->email).'">@'.e($user->name).'</span>';
                },
                $text
            );
        });
    }

    /**
     * handle => user, with earlier candidates winning a collision.
     *
     * @param  Collection<int, User>  $candidates
     * @return array<string, User>
     */
    private function index(Collection $candidates): array
    {
        $index = [];

        foreach ($candidates as $user) {
            foreach ($this->handlesFor($user) as $handle) {
                $index[$handle] ??= $user;
            }
        }

        return $index;
    }
}
