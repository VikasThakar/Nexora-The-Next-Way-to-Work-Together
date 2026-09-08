<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads and ticks the checklist items inside a Markdown document.
 *
 * This is what makes a checklist in a ticket description *usable* rather than
 * decorative. CommonMark already renders `- [ ] item` as a checkbox, but it
 * renders it disabled, so before this the boxes were a picture of a checklist.
 * Ticking one now rewrites the stored Markdown.
 *
 * Everything here works on the source text and addresses items by their
 * position in it, which is deliberate:
 *
 *   the browser sends an index, never a line number and never any text. So the
 *   worst a tampered request can do is tick a different box on a ticket the
 *   person is already authorized to edit — there is nothing to inject, no
 *   pattern to escape, and no way to reach another ticket.
 *
 *   only the marker is rewritten. The line's indentation, its bullet
 *   character and its wording are left byte-for-byte alone, so ticking a box
 *   cannot reformat somebody's document.
 *
 * Fenced code blocks are skipped, because `- [ ] not a task` inside a code
 * sample is a code sample. Missing that would make the indices disagree with
 * what the reader sees, and tick the wrong line.
 */
class TaskList
{
    /**
     * A GFM task list item: indentation, bullet, then the box.
     *
     * Ordered items (`1. [ ] x`) are matched too — CommonMark accepts them,
     * so a document can contain them and they have to be counted or every
     * index after one would be wrong.
     */
    private const ITEM = '/^(?<indent>[ \t]*)(?<bullet>[-*+]|\d+[.)])(?<gap>[ \t]+)\[(?<state>[ xX])\](?<rest>\s)/';

    /** Opens or closes a fenced code block. */
    private const FENCE = '/^[ \t]*(?:```|~~~)/';

    /**
     * Every checklist item in the document, in reading order.
     *
     * @return list<array{index: int, line: int, checked: bool, label: string}>
     */
    public function items(?string $markdown): array
    {
        $items = [];

        foreach ($this->scan($markdown) as $line => $match) {
            $items[] = [
                'index' => count($items),
                'line' => $line,
                'checked' => strtolower($match['state']) === 'x',
                'label' => trim($match['rest_of_line']),
            ];
        }

        return $items;
    }

    /**
     * Flip the item at the given index.
     *
     * Returns the document unchanged when the index does not exist, rather
     * than throwing: an index that has gone stale — because somebody else
     * edited the description a moment ago — is a race, not an attack, and
     * losing the edit is a better outcome than a 500 on a checkbox.
     */
    public function toggle(?string $markdown, int $index): string
    {
        return $this->write($markdown, $index, null);
    }

    /**
     * Set the item at the given index to a specific state.
     */
    public function set(?string $markdown, int $index, bool $checked): string
    {
        return $this->write($markdown, $index, $checked);
    }

    public function count(?string $markdown): int
    {
        return count($this->items($markdown));
    }

    public function completed(?string $markdown): int
    {
        return count(array_filter($this->items($markdown), static fn (array $item): bool => $item['checked']));
    }

    /**
     * Render items as the Markdown for a checklist.
     *
     * Used by the migration off the standalone checklist feature — see
     * App\Actions\Tickets\ConvertSubtasksToChecklist.
     *
     * @param  list<array{title: string, completed: bool}>  $items
     */
    public function toMarkdown(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $title = trim((string) $item['title']);

            if ($title === '') {
                continue;
            }

            /*
             * A newline inside a title would end the list item and turn the
             * remainder into a paragraph, silently splitting one checklist item
             * into two things. Titles are `string(200)` in the database with no
             * newline validation, so this cannot be assumed away.
             */
            $title = (string) preg_replace('/\s+/u', ' ', $title);

            $lines[] = ($item['completed'] ? '- [x] ' : '- [ ] ').$title;
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------

    private function write(?string $markdown, int $index, ?bool $checked): string
    {
        $source = (string) $markdown;

        if ($index < 0) {
            return $source;
        }

        $lines = preg_split('/\R/', $source) ?: [];
        $seen = 0;

        foreach ($this->scan($source) as $line => $match) {
            if ($seen++ !== $index) {
                continue;
            }

            $now = $checked ?? strtolower($match['state']) !== 'x';

            $lines[$line] = $match['indent'].$match['bullet'].$match['gap']
                .'['.($now ? 'x' : ' ').']'
                .$match['rest'].$match['rest_of_line'];

            /*
             * The original line endings are not preserved.
             *
             * A document written on Windows arrives with CRLF, and rejoining
             * with "\n" normalises the whole thing. That is the same
             * normalisation App\Support\RichText\RichText already applies on
             * every save, so this does not introduce a new behaviour — and a
             * mixture would be worse.
             */
            return implode("\n", $lines);
        }

        return $source;
    }

    /**
     * Walk the document, yielding one match per checklist item.
     *
     * Keyed by line number, so a caller can rewrite in place.
     *
     * @return \Generator<int, array{indent: string, bullet: string, gap: string, state: string, rest: string, rest_of_line: string}>
     */
    private function scan(?string $markdown): \Generator
    {
        $lines = preg_split('/\R/', (string) $markdown) ?: [];
        $inFence = false;

        foreach ($lines as $number => $line) {
            if (preg_match(self::FENCE, $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match(self::ITEM, $line, $match) !== 1) {
                continue;
            }

            yield $number => [
                'indent' => $match['indent'],
                'bullet' => $match['bullet'],
                'gap' => $match['gap'],
                'state' => $match['state'],
                'rest' => $match['rest'],
                // Everything after the box and its following whitespace.
                'rest_of_line' => substr($line, strlen($match[0])),
            ];
        }
    }
}
