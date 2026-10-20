<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * A document's blocks — headings, paragraphs, list items, tables — written out
 * as Markdown, with the spacing and indentation CommonMark needs.
 *
 * Every converter produces the same block shapes, so lists nest and headings
 * space the same way whichever kind of file a page came from. The text in a
 * block is already inline Markdown: escaped by the converter, and formatted
 * (bold, links) where the source had formatting.
 *
 *   ['type' => 'heading',   'level' => 1–6, 'text' => …]
 *   ['type' => 'paragraph', 'text' => …]
 *   ['type' => 'item',      'depth' => 0–5, 'ordered' => bool, 'number' => int,
 *                           'task' => true (done) | false (open) | null, 'text' => …]
 *   ['type' => 'table',     'markdown' => …]   (already a complete table)
 *   ['type' => 'quote',     'text' => …]
 *   ['type' => 'blank']                          (ends a list)
 */
final class MarkdownBlocks
{
    /** Nesting deeper than this reads as noise and risks the renderer's own limit. */
    private const MAX_DEPTH = 5;

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function render(array $blocks): string
    {
        $out = [];
        $previous = null;

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? 'paragraph');

            if ($type === 'blank') {
                $previous = 'blank';

                continue;
            }

            if ($type === 'item') {
                $depth = min(self::MAX_DEPTH, max(0, (int) ($block['depth'] ?? 0)));

                // Four spaces a level: deep enough to belong to a numbered
                // parent as well as a bulleted one, and what the page editor
                // itself writes, so an imported list is not rewritten the
                // first time somebody edits the page.
                $indent = 4 * $depth;

                $task = $block['task'] ?? null;

                $marker = match (true) {
                    $task === true => '- [x]',
                    $task === false => '- [ ]',
                    (bool) ($block['ordered'] ?? false) => max(1, (int) ($block['number'] ?? 1)).'.',
                    default => '-',
                };

                if ($previous !== null && $previous !== 'item') {
                    $out[] = '';
                }

                $out[] = str_repeat(' ', $indent).$marker.' '.trim((string) $block['text']);
                $previous = 'item';

                continue;
            }

            if ($previous !== null) {
                $out[] = '';
            }

            $out[] = match ($type) {
                'heading' => str_repeat('#', min(6, max(1, (int) ($block['level'] ?? 2)))).' '.trim((string) $block['text']),
                'table' => (string) $block['markdown'],
                'quote' => '> '.str_replace("\n", "\n> ", trim((string) $block['text'])),
                default => (string) $block['text'],
            };

            $previous = $type;
        }

        return trim(implode("\n", $out));
    }
}
