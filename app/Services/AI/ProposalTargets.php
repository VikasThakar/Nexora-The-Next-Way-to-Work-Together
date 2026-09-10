<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Turning the words somebody said into the rows a write needs.
 *
 * "Assign it to Alex", "move it to Done", "label it bug" — three requests that
 * arrive as strings and have to become a user id, a column id and a label id
 * before any action can run. This class is the whole of that step, and it is
 * separate from App\Actions\AI\ExecuteChatAction for one reason: resolution is
 * where a natural-language request is most likely to be *nearly* right, and
 * nearly right is the dangerous case. Two people called Alex, two columns with
 * "review" in the name, a label that does not exist on this board — each has to
 * end in a question rather than a guess.
 *
 * Three rules, and they are the point of the class.
 *
 * Exact before partial, never partial alone
 * -----------------------------------------
 * An exact case-insensitive match wins outright, so a board with columns "Done"
 * and "Nearly Done" resolves "Done" to the right one instead of complaining.
 * Only when nothing matches exactly is a contains-match tried, and then a
 * single hit is required. This ordering is what makes the common case work
 * without making the ambiguous case guess.
 *
 * Ambiguity is a question, not a choice
 * -------------------------------------
 * Two matches is a refusal that names both, so the assistant can ask which was
 * meant — the brief's "I found two tickets matching 'login issue'. Which one do
 * you mean?", applied to people, columns and labels as well. Picking the first
 * match would assign somebody else's work to the wrong Alex, silently, and the
 * person would find out later.
 *
 * The board is the boundary
 * -------------------------
 * Every lookup is scoped to the board the change lands on, so an assignee has
 * to be a *member* and a label has to belong to it. That is not a convenience:
 * assigning a non-member is how a ticket ends up owned by somebody who cannot
 * open it, and the refusal here is the sentence the brief asks for by name —
 * "I couldn't assign NL-18 to Alex because Alex is not a member of this board."
 *
 * What this class is NOT
 * ----------------------
 * It is not authorization. Resolving "Alex Round" to a user proves only that
 * such a member exists; whether the person asking may assign anybody at all is
 * TicketPolicy::assign's decision, taken afterwards by the executor. Nothing
 * here consults a policy and nothing here grants anything, exactly as
 * BoardAccess::query is a lookup rather than a permission.
 *
 * Failures are RuntimeException carrying prose, because that is what
 * ExecuteChatAction already reports into the conversation. The messages are
 * written for the person reading them, not for a log.
 */
class ProposalTargets
{
    /**
     * Who a change should be assigned to.
     *
     * Returns null for an explicit unassign — "nobody", "no one", "unassigned"
     * — which is a real request and distinct from "leave it alone" (the caller
     * expresses that by not calling this at all).
     *
     * @throws RuntimeException when the name matches nobody on the board, or more than one person
     */
    public function assignee(Board $board, string $name, User $asker): ?User
    {
        $name = trim($name);
        $lowered = mb_strtolower($name);

        if ($name === '' || in_array($lowered, ['nobody', 'no one', 'noone', 'none', 'unassigned'], true)) {
            return null;
        }

        /*
         * "me" is the asker, resolved here rather than left to a name match.
         *
         * People say it constantly ("assign it to me") and it is the one
         * reference that cannot be got wrong — but it still has to clear the
         * membership check below, because a workspace administrator talking
         * about a board they are not a member of saying "assign it to me"
         * should be told so rather than quietly assigned.
         */
        if (in_array($lowered, ['me', 'myself', 'i'], true)) {
            $name = (string) $asker->name;
            $lowered = mb_strtolower($name);
        }

        $members = $board->members()->get();

        $matches = $this->narrow(
            $members,
            static fn (User $user): array => [(string) $user->name, (string) $user->email],
            $lowered,
        );

        if ($matches->isEmpty()) {
            throw new RuntimeException(
                'I could not assign that: '.$this->quote($name).' is not a member of '
                .$board->name.'. Somebody has to be added to the board before work can be assigned to them.'
            );
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                'More than one member of '.$board->name.' matches '.$this->quote($name).': '
                .$this->prose($matches->map(static fn (User $user): string => (string) $user->name)->all())
                .'. Nothing was changed — say which one you mean.'
            );
        }

        return $matches->first();
    }

    /**
     * Which status column a ticket should sit in.
     *
     * The board's columns *are* its statuses in this product, so "move it to
     * Done", "put it in review" and "change the status to In Progress" all
     * arrive here.
     *
     * @throws RuntimeException when the name matches no column, or more than one
     */
    public function column(Board $board, string $name): BoardColumn
    {
        $name = trim($name);
        $columns = $board->columns()->get();

        $matches = $this->narrow(
            $columns,
            static fn (BoardColumn $column): array => [(string) $column->name],
            mb_strtolower($name),
        );

        if ($matches->isEmpty()) {
            throw new RuntimeException(
                $board->name.' has no column called '.$this->quote($name).'. Its columns are '
                .$this->prose($columns->map(static fn (BoardColumn $column): string => (string) $column->name)->all())
                .'. Nothing was changed.'
            );
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                'More than one column on '.$board->name.' matches '.$this->quote($name).': '
                .$this->prose($matches->map(static fn (BoardColumn $column): string => (string) $column->name)->all())
                .'. Nothing was changed — say which one you mean.'
            );
        }

        return $matches->first();
    }

    /**
     * The label ids for a set of label names.
     *
     * Every name has to resolve. A request for three labels where one does not
     * exist is refused whole rather than applied in part, because
     * SyncTicketLabels replaces the set: applying the two that matched would
     * silently drop the third from what the person believes they asked for, and
     * they would have no way to tell from the result.
     *
     * This deliberately cannot create a label. A label is a board-wide
     * taxonomy decision with a colour and a name that shows up on everybody
     * else's cards, and inventing one to satisfy a passing request is how that
     * taxonomy fills up with near-duplicates.
     *
     * @param  list<string>  $names
     * @return list<int>
     *
     * @throws RuntimeException when a name matches no label on the board, or more than one
     */
    public function labelIds(Board $board, array $names): array
    {
        $available = $board->labels()->get();
        $ids = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $matches = $this->narrow(
                $available,
                static fn (Label $label): array => [(string) $label->name],
                mb_strtolower($name),
            );

            if ($matches->isEmpty()) {
                throw new RuntimeException(
                    $board->name.' has no label called '.$this->quote($name).'. Its labels are '
                    .$this->prose($available->map(static fn (Label $label): string => (string) $label->name)->all())
                    .'. Nothing was changed — labels have to be created on the board first.'
                );
            }

            if ($matches->count() > 1) {
                throw new RuntimeException(
                    'More than one label on '.$board->name.' matches '.$this->quote($name).': '
                    .$this->prose($matches->map(static fn (Label $label): string => (string) $label->name)->all())
                    .'. Nothing was changed — say which one you mean.'
                );
            }

            $ids[] = (int) $matches->first()->getKey();
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------

    /**
     * Exact matches if there are any, otherwise the partial ones.
     *
     * The single ordering rule the whole class rests on, written once. A
     * candidate offers one or more strings to match against — a user offers a
     * name and an email — and matches if any of them qualifies.
     *
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $candidates
     * @param  callable(mixed): list<string>  $haystacks
     * @return Collection<int, covariant \Illuminate\Database\Eloquent\Model>
     */
    private function narrow(Collection $candidates, callable $haystacks, string $needle): Collection
    {
        if ($needle === '') {
            return $candidates->take(0);
        }

        $exact = $candidates->filter(static function ($candidate) use ($haystacks, $needle): bool {
            foreach ($haystacks($candidate) as $haystack) {
                if (mb_strtolower(trim($haystack)) === $needle) {
                    return true;
                }
            }

            return false;
        });

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        return $candidates->filter(static function ($candidate) use ($haystacks, $needle): bool {
            foreach ($haystacks($candidate) as $haystack) {
                if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * A value quoted for a sentence a person reads.
     */
    private function quote(string $value): string
    {
        return '"'.mb_substr($value, 0, 80).'"';
    }

    /**
     * A list in prose: "Idea, In Progress and Done".
     *
     * Capped, because a board with forty labels should not produce a refusal
     * nobody can read.
     *
     * @param  list<string>  $values
     */
    private function prose(array $values): string
    {
        $values = array_values(array_filter($values, static fn (string $value): bool => trim($value) !== ''));

        if ($values === []) {
            return 'none';
        }

        $overflow = count($values) - 12;

        if ($overflow > 0) {
            $values = array_slice($values, 0, 12);
            $values[] = 'and '.$overflow.' more';

            return implode(', ', $values);
        }

        if (count($values) === 1) {
            return $values[0];
        }

        $last = array_pop($values);

        return implode(', ', $values).' and '.$last;
    }
}
