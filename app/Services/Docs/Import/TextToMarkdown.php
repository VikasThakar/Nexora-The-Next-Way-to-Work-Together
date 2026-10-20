<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * Plain text — a .txt file, or the text a PDF gave up — as Markdown.
 *
 * Plain text has structure, just not markup: a line starting with a bullet is a
 * list item, "[ ]" and "☐" are open to-dos, "Bugs:" on a line of its own is a
 * heading. Those are what a to-do list or a bug list is made of, and they are
 * exactly what an import that pasted the text in one paragraph would lose.
 *
 * Recognised:
 *
 *   bullets       • ◦ ▪ ‣ · * - – —, indented for nesting
 *   numbering     1.  1)  a)  i.
 *   checkboxes    [ ] [x] [X] ( ) (x) ☐ □ ☑ ☒ ✓ ✔ ✅ ✗ — open or done
 *   headings      `# Heading`, a line underlined with === or ---, a short line
 *                 in capitals, a short line ending in a colon before a list
 *
 * Everything else is a paragraph. A .txt file's line breaks are its author's
 * and are kept (`$reflow` false). A PDF's line breaks are where the page ran
 * out of width, so with `$reflow` the lines of a paragraph are joined back up,
 * and a word hyphenated across two lines is mended.
 */
final class TextToMarkdown
{
    private const BULLETS = '•◦▪‣·*\-–—●○■□';

    public static function convert(string $text, bool $reflow = false): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $count = count($lines);

        $blocks = [];
        $paragraph = [];

        // The indentation of each open list level, so nesting follows the
        // file's own indents however wide they are: two spaces, four, or a
        // tab — an item indented further than the one above it is nested in it.
        $indents = [];

        $flush = function () use (&$paragraph, &$blocks, $reflow): void {
            if ($paragraph === []) {
                return;
            }

            $blocks[] = ['type' => 'paragraph', 'text' => self::paragraph($paragraph, $reflow)];
            $paragraph = [];
        };

        for ($index = 0; $index < $count; $index++) {
            $raw = rtrim($lines[$index]);
            $trimmed = trim($raw);
            $next = $index + 1 < $count ? trim($lines[$index + 1]) : '';

            if ($trimmed === '') {
                $flush();
                $blocks[] = ['type' => 'blank'];

                continue;
            }

            // Setext: "Title" underlined with === or ---.
            if ($paragraph === [] && $next !== '' && preg_match('/^(=+|-+)$/', $next) === 1 && mb_strlen($next) >= 3
                && self::item($raw) === null) {
                $blocks[] = ['type' => 'heading', 'level' => str_starts_with($next, '=') ? 1 : 2, 'text' => MarkdownText::inline($trimmed)];
                $index++;

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $trimmed, $match) === 1) {
                $flush();
                $blocks[] = ['type' => 'heading', 'level' => strlen($match[1]), 'text' => MarkdownText::inline($match[2])];

                continue;
            }

            $item = self::item($raw);

            if ($item !== null) {
                $flush();

                while ($indents !== [] && end($indents) >= $item['indent']) {
                    array_pop($indents);
                }

                $depth = min(5, count($indents));
                $indents[] = $item['indent'];

                $blocks[] = [
                    'type' => 'item',
                    'depth' => $depth,
                    'ordered' => $item['ordered'],
                    'number' => $item['number'],
                    'task' => $item['task'],
                    'text' => MarkdownText::inline($item['text']),
                ];

                continue;
            }

            $indents = [];

            if ($paragraph === [] && self::looksLikeHeading($trimmed, $next)) {
                $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => MarkdownText::inline(rtrim($trimmed, ': '))];

                continue;
            }

            $paragraph[] = $trimmed;
        }

        $flush();

        return MarkdownBlocks::render($blocks);
    }

    /**
     * A list item, or null: its indentation, marker and text.
     *
     * @return array{indent: int, ordered: bool, number: int, task: bool|null, text: string}|null
     */
    private static function item(string $line): ?array
    {
        if (preg_match('/^([ \t]*)(.*)$/u', $line, $parts) !== 1) {
            return null;
        }

        $indent = strlen(str_replace("\t", '    ', $parts[1]));
        $rest = $parts[2];

        // A checkbox, with or without a bullet before it.
        $box = '/^(?:['.self::BULLETS.']\s*)?(\[\s?\]|\[[xX✓✔]\]|\(\s?\)|\([xX]\)|☐|□|☑|☒|✓|✔|✅|✗|❌)\s+(.+)$/u';

        if (preg_match($box, $rest, $match) === 1) {
            $done = preg_match('/[xX✓✔☑☒✅✗❌]/u', $match[1]) === 1;

            return ['indent' => $indent, 'ordered' => false, 'number' => 0, 'task' => $done, 'text' => $match[2]];
        }

        // A bullet. "-" and "*" need the space after them; "--- " and "**bold"
        // are not list items.
        if (preg_match('/^['.self::BULLETS.']\s+(.+)$/u', $rest, $match) === 1
            && preg_match('/^(-{2,}|\*{2,})/', $rest) !== 1) {
            return ['indent' => $indent, 'ordered' => false, 'number' => 0, 'task' => null, 'text' => $match[1]];
        }

        // "1." and "1)": an ordered item that keeps its number.
        if (preg_match('/^(\d{1,4})[.)]\s+(.+)$/u', $rest, $match) === 1) {
            return ['indent' => $indent, 'ordered' => true, 'number' => (int) $match[1], 'task' => null, 'text' => $match[2]];
        }

        // "a)" and "iv." have no Markdown form; they stay visible as the label.
        if (preg_match('/^([a-z]|[ivx]{1,4})[.)]\s+(.+)$/u', $rest, $match) === 1) {
            return ['indent' => $indent, 'ordered' => false, 'number' => 0, 'task' => null, 'text' => $match[1].') '.$match[2]];
        }

        return null;
    }

    /**
     * A heading that was written without markup.
     *
     * "BUGS" and "Known issues:" on lines of their own, before a list. Kept
     * short and strict, because a sentence promoted to a heading is worse than
     * a heading left as a sentence.
     */
    private static function looksLikeHeading(string $line, string $next): bool
    {
        $length = mb_strlen($line);

        if ($length < 2 || $length > 60) {
            return false;
        }

        $letters = (string) preg_replace('/[^\p{L}]/u', '', $line);

        // Capitals: at least three letters, all upper case, no full stop.
        if (mb_strlen($letters) >= 3 && $letters === mb_strtoupper($letters) && $letters !== mb_strtolower($letters)
            && ! str_ends_with($line, '.')) {
            return true;
        }

        // "Bugs:" — a label, then a list on the next line.
        return str_ends_with($line, ':') && $length <= 40 && $next !== '' && self::item($next) !== null;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function paragraph(array $lines, bool $reflow): string
    {
        if (! $reflow) {
            // A backslash at the end of a line is a hard break in CommonMark,
            // and survives trailing-space trimming where two spaces would not.
            return implode("\\\n", array_map([MarkdownText::class, 'line'], $lines));
        }

        $text = '';

        foreach ($lines as $line) {
            if ($text === '') {
                $text = $line;

                continue;
            }

            // A word hyphenated across the break: "imple-" + "mentation".
            if (preg_match('/\p{Ll}-$/u', $text) === 1 && preg_match('/^\p{Ll}/u', $line) === 1) {
                $text = mb_substr($text, 0, -1).$line;

                continue;
            }

            $text .= ' '.$line;
        }

        return MarkdownText::line($text);
    }
}
