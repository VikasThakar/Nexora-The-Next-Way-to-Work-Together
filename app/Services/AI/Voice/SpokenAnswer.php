<?php

declare(strict_types=1);

namespace App\Services\AI\Voice;

/**
 * An answer, prepared for being read aloud.
 *
 * A stored answer is Markdown that may carry a fenced ```nexora-table or
 * ```nexora-chart block, and reading either of those aloud produces a minute of
 * somebody spelling out JSON. So the spoken form is not the written form, and
 * this is the one place that difference is expressed.
 *
 * The rules, in order:
 *
 *   - fenced blocks are replaced by a sentence saying what is on screen. That
 *     is more useful than silence: somebody listening needs to know a table
 *     exists, not to hear it;
 *   - inline formatting is removed, because asterisks and backticks are read
 *     as words by some voices and as nothing by others;
 *   - links keep their text and lose their URL, since a spoken URL is noise;
 *   - list markers become nothing, and headings become plain sentences.
 *
 * What is deliberately NOT done is summarising. This is a transformation of the
 * text, not a second model call: an answer that is long is read as far as the
 * provider's limit and then handed off to the screen. Summarising would mean
 * the spoken answer and the written answer could differ in substance, and
 * somebody would eventually act on the wrong one.
 */
final class SpokenAnswer
{
    public static function from(string $markdown): string
    {
        $text = str_replace("\r\n", "\n", trim($markdown));

        if ($text === '') {
            return '';
        }

        // Fenced blocks, whatever their language tag, including the two
        // namespaced ones this product emits.
        $text = (string) preg_replace(
            '/```[a-zA-Z0-9_-]*\n.*?(?:```|\z)/s',
            "\n".'There is a table or chart in the answer on screen.'."\n",
            $text
        );

        // A Markdown pipe table renders as a real table on screen too.
        $text = self::replaceTables($text);

        // Images before links, so an image's alt text is not left dangling.
        $text = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);

        // Headings become sentences; list markers and quote markers go.
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
        $text = (string) preg_replace('/^\s{0,3}[-*+]\s+/m', '', $text);
        $text = (string) preg_replace('/^\s{0,3}>\s?/m', '', $text);

        // Emphasis, strong, inline code, strikethrough.
        $text = (string) preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text);
        $text = (string) preg_replace('/(\*|_)(.*?)\1/s', '$2', $text);
        $text = (string) preg_replace('/`([^`]*)`/', '$1', $text);
        $text = (string) preg_replace('/~~(.*?)~~/s', '$1', $text);

        // Horizontal rules carry nothing spoken.
        $text = (string) preg_replace('/^\s{0,3}([-*_]\s*){3,}$/m', '', $text);

        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        return trim($text);
    }

    /**
     * Replace a pipe table with one sentence.
     *
     * Detected by its delimiter row, exactly as the rich-response parser
     * detects one, so the two agree about what counts as a table: a run of
     * lines where the second is dashes and pipes.
     */
    private static function replaceTables(string $text): string
    {
        $lines = explode("\n", $text);
        $output = [];
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $isHeader = str_contains($lines[$index], '|');
            $delimiter = $index + 1 < $count
                && preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $lines[$index + 1]) === 1
                && str_contains($lines[$index + 1], '-');

            if (! ($isHeader && $delimiter)) {
                $output[] = $lines[$index];

                continue;
            }

            // Skip the header, the delimiter and every body row.
            $index += 2;

            while ($index < $count && str_contains($lines[$index], '|')) {
                $index++;
            }

            $index--;

            $output[] = 'There is a table in the answer on screen.';
        }

        return implode("\n", $output);
    }
}
