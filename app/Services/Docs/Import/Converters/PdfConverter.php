<?php

declare(strict_types=1);

namespace App\Services\Docs\Import\Converters;

use App\Services\Docs\Import\ConvertedDocument;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\TextToMarkdown;
use App\Support\Pdf\PdfText;
use Throwable;

/**
 * A PDF as Markdown.
 *
 * A PDF holds positioned text, not paragraphs: lines end where the page ran
 * out of width, every page repeats its header and footer, and page numbers sit
 * among the words. Those are taken out — a line that recurs at the top or the
 * bottom of most pages is a running header or footer, and a line that is only
 * a page number is a page number — and what is left is read as plain text with
 * its paragraphs joined back up (TextToMarkdown, reflowed), which is what turns
 * "• Fix login" into a list item and "☐ Write tests" into a to-do.
 *
 * A scan has no text to read, and the import says so rather than making an
 * empty page.
 */
final class PdfConverter
{
    public function __construct(private readonly int $maxPages = 300) {}

    public function convert(string $path): ConvertedDocument
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            throw new DocumentImportException('That PDF is empty.');
        }

        try {
            ['pages' => $pages, 'total' => $total] = PdfText::pages($contents, $this->maxPages);
        } catch (Throwable $exception) {
            throw new DocumentImportException(PdfText::explain($exception));
        }

        if ($total === 0) {
            throw new DocumentImportException('That PDF has no readable pages. It may be corrupt or password-protected.');
        }

        $pages = array_map(static fn (string $text): array => self::lines($text), $pages);

        if (array_sum(array_map('count', $pages)) === 0) {
            throw new DocumentImportException(PdfText::noTextLayer($total)
                .' Export it with its text, or paste the text into a new page.');
        }

        $pages = $this->withoutRunningLines($pages);

        $markdown = TextToMarkdown::convert(implode("\n\n", array_map(
            static fn (array $lines): string => implode("\n", $lines),
            array_filter($pages),
        )), reflow: true);

        $warnings = [];

        if (count($pages) < $total) {
            $warnings[] = 'Only the first '.number_format(count($pages)).' of '.number_format($total)
                .' pages were imported; the whole PDF is attached.';
        }

        return new ConvertedDocument($markdown, null, $warnings);
    }

    /**
     * A page's lines, tidied: tabs to spaces, runs of spaces collapsed.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        $lines = array_map(
            static fn (string $line): string => rtrim((string) preg_replace('/ {2,}/u', ' ', $line)),
            explode("\n", $text),
        );

        // Blank lines at either end of a page carry nothing.
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Pages without their running headers, footers and page numbers.
     *
     * @param  array<int, list<string>>  $pages
     * @return array<int, list<string>>
     */
    private function withoutRunningLines(array $pages): array
    {
        $count = count($pages);
        $running = [];

        // A running line needs enough pages to be told from content.
        if ($count >= 3) {
            $seen = [];

            foreach ($pages as $lines) {
                $edges = array_unique(array_map(
                    [self::class, 'normalised'],
                    array_merge(array_slice($lines, 0, 2), array_slice($lines, -2)),
                ));

                foreach ($edges as $edge) {
                    if ($edge !== '') {
                        $seen[$edge] = ($seen[$edge] ?? 0) + 1;
                    }
                }
            }

            foreach ($seen as $line => $times) {
                if ($times >= max(3, (int) ceil($count * 0.6))) {
                    $running[$line] = true;
                }
            }
        }

        foreach ($pages as $index => $lines) {
            $last = count($lines) - 1;

            $pages[$index] = array_values(array_filter(
                $lines,
                function (string $line, int $position) use ($running, $last): bool {
                    $edge = $position < 2 || $position > $last - 2;

                    if (! $edge) {
                        return true;
                    }

                    return ! isset($running[self::normalised($line)]) && ! self::isPageNumber($line);
                },
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return $pages;
    }

    /**
     * A line compared without its page number, so "Report — page 3" and
     * "Report — page 4" are recognised as the same running footer.
     */
    private static function normalised(string $line): string
    {
        return trim((string) preg_replace('/\d+/', '#', mb_strtolower($line)));
    }

    private static function isPageNumber(string $line): bool
    {
        return preg_match('/^\s*(?:page\s+)?[-–—]?\s*\d{1,4}\s*(?:(?:of|\/)\s*\d{1,4})?\s*[-–—]?\s*$/iu', $line) === 1;
    }
}
