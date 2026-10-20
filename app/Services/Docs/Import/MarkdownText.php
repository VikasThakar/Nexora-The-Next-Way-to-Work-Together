<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * Writing imported text as the Markdown a documentation page stores.
 *
 * Two rules come from how pages are rendered, and both are easy to break:
 *
 *   - App\Support\Markdown strips raw HTML, so a literal `<Enter>` or
 *     `<script>` in a document would silently vanish from the page. `<` is
 *     escaped so it shows as written.
 *   - The renderer reads `++text++` as underline, so `C++ and C++` must not
 *     become an underlined " and C". `++` is escaped.
 *
 * The forms written are the editor's own (`-` bullets, `*` italic, `**` bold),
 * so an imported page opened and saved in the editor does not churn.
 */
final class MarkdownText
{
    /**
     * Text that must read literally, inside a paragraph, heading or list item.
     *
     * Only what would change the meaning is escaped — a page full of
     * backslashes is its own kind of unreadable.
     */
    public static function inline(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);

        $text = (string) preg_replace('/([*_`\[\]<])/', '\\\\$1', $text);

        return str_replace('++', '\\+\\+', $text);
    }

    /**
     * A line of plain text, escaped so that nothing at its start is read as
     * structure it was not — a heading, a quote, a list, a rule.
     */
    public static function line(string $text): string
    {
        $text = self::inline($text);

        // "# 1 priority", "> 5 users", "- not a list", "1. not a list", "---".
        return (string) preg_replace('/^(\s*)(#{1,6}\s|>|[-+]\s|\d+[.)]\s|={3,}$|-{3,}$)/', '$1\\\\$2', $text);
    }

    /**
     * A GitHub table.
     *
     * Every row padded to the widest, cells flattened to one line and their
     * pipes escaped, because one stray `|` shifts every column after it.
     *
     * `$markdown` says the cells are inline Markdown already (a converter kept
     * their bold and links) and only need flattening and their pipes escaped;
     * otherwise they are plain text and escaped in full.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public static function table(array $headers, array $rows, bool $markdown = false): string
    {
        $width = max(count($headers), ...array_map('count', $rows ?: [[]]));

        if ($width === 0) {
            return '';
        }

        $cell = static function (string $value) use ($markdown): string {
            // A hard line break has no place in a table row.
            $value = str_replace("\\\n", ' ', $value);
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));

            if (! $markdown) {
                return str_replace('|', '\\|', self::inline($value));
            }

            // Pipes not already escaped.
            return (string) preg_replace('/(?<!\\\\)\|/', '\\|', $value);
        };

        $pad = static fn (array $row): array => array_pad(array_map($cell, $row), $width, '');

        $headers = $pad($headers);

        // A header row of blanks renders as a table with no header; give each
        // column a name instead.
        foreach ($headers as $index => $header) {
            if ($header === '') {
                $headers[$index] = 'Column '.($index + 1);
            }
        }

        $lines = [
            '| '.implode(' | ', $headers).' |',
            '|'.str_repeat(' --- |', $width),
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $pad($row)).' |';
        }

        return implode("\n", $lines);
    }
}
