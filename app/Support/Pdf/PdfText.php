<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The text of a PDF, page by page.
 *
 * smalot/pdfparser does the reading: pure PHP, no external binary, which is
 * what makes it deployable without a custom image. Shared by the assistant's
 * attachment reader and the documentation importer, so both read a PDF the
 * same way, with the same limits and the same explanations when they cannot.
 *
 * A PDF is untrusted input and this parser is pure PHP, so the two things worth
 * bounding are memory and work: embedded images are not retained (they are not
 * needed to read text, and skipping them is the difference between reading a
 * 40 MB brochure and exhausting the worker), decoded streams are capped, and
 * pages beyond `$maxPages` are not read.
 */
final class PdfText
{
    /**
     * @return array{pages: array<int, string>, total: int} the raw text of each
     *                                                     page read, keyed by
     *                                                     zero-based page index,
     *                                                     and the page count
     *
     * @throws Throwable when the file cannot be parsed at all; see explain()
     */
    public static function pages(string $contents, int $maxPages = 300): array
    {
        $document = (new Parser([], self::config()))->parseContent($contents);
        $pages = $document->getPages();

        $texts = [];

        foreach ($pages as $index => $page) {
            if (count($texts) >= $maxPages) {
                break;
            }

            try {
                $texts[$index] = (string) $page->getText();
            } catch (Throwable) {
                // One page failing must not lose the other three hundred. A
                // malformed font table in a single page is common and local.
                $texts[$index] = '';
            }
        }

        return ['pages' => $texts, 'total' => count($pages)];
    }

    /**
     * A parser failure as something a person can act on.
     *
     * The exception's own message names offsets and object numbers and is not
     * forwarded. The case worth singling out is encryption, which the person
     * can fix; everything else they cannot.
     */
    public static function explain(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'encrypt') || str_contains($message, 'password')
            || str_contains($message, 'secured')) {
            return 'That PDF is password-protected, so its text cannot be read. '
                .'Attach an unprotected copy.';
        }

        return 'That PDF could not be read — it may be corrupt or use an unusual encoding. '
            .'Exporting it again from the application that produced it usually fixes this.';
    }

    /**
     * What a PDF with pages but no text is: a scan.
     */
    public static function noTextLayer(int $pageCount): string
    {
        return 'That PDF has '.$pageCount.' '.($pageCount === 1 ? 'page' : 'pages')
            .' but no text layer, so it is almost certainly a scan or a set of images. '
            .'Nexora does not read text out of pictures of documents.';
    }

    private static function config(): Config
    {
        $config = new Config;

        $config->setRetainImageContent(false);

        // A pathological stream must not take the worker's memory with it.
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        return $config;
    }
}
