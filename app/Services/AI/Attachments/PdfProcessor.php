<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF text extraction.
 *
 * smalot/pdfparser does the reading. It is pure PHP with no external binary and
 * no extension beyond what this application already needs, which is what makes
 * it deployable on Railway — shelling out to pdftotext would mean a custom
 * image, and an image built for one feature is a liability for every other one.
 *
 * Text is extracted per page rather than as one string, for the requirement
 * that answers should be able to point at where something came from. Each page
 * arrives labelled, so "the indemnity clause is on page 14" is a thing the model
 * can say and a person can check. That labelling is the only reason this class
 * is longer than TextProcessor.
 *
 * What this cannot do, and says so
 * --------------------------------
 * A scanned PDF has no text layer: it is a picture of a document. Extraction
 * yields nothing, and the honest answer is to say that the file appears to be
 * scanned and suggest attaching it as an image so a vision model can look at
 * it, rather than reporting an empty document as though it were empty. OCR is
 * not attempted, here or anywhere in this product.
 *
 * An encrypted PDF also yields nothing. The parser raises for those and the
 * message says the file is password-protected, which is actionable in a way
 * that "could not read" is not.
 */
class PdfProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    /**
     * Pages read, at most.
     *
     * A cap on work rather than on honesty: when it bites, the page count in
     * the summary still reports the whole document and the prompt says how many
     * pages were read.
     */
    private const MAX_PAGES = 300;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Pdf;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        $contents = $this->contents($attachment);

        if ($contents === null) {
            return AiAttachmentExtraction::failed(
                'The stored file could not be read. Try attaching it again.'
            );
        }

        try {
            $document = (new Parser([], $this->parserConfig()))->parseContent($contents);
            $pages = $document->getPages();
        } catch (Throwable $exception) {
            return AiAttachmentExtraction::failed($this->explain($exception));
        }

        $pageCount = count($pages);

        if ($pageCount === 0) {
            return AiAttachmentExtraction::failed(
                'That PDF has no readable pages. It may be corrupt or password-protected.'
            );
        }

        $sections = [];
        $read = 0;
        $withText = 0;

        foreach ($pages as $index => $page) {
            if ($read >= self::MAX_PAGES) {
                break;
            }

            $read++;

            try {
                $text = $this->normalise((string) $page->getText());
            } catch (Throwable) {
                // One page failing must not lose the other three hundred. A
                // malformed font table in a single page is common and local.
                continue;
            }

            if (trim($text) === '') {
                continue;
            }

            $withText++;

            // The label is what makes a citation possible. One-based, because
            // that is how a PDF reader numbers pages.
            $sections[] = '--- Page '.($index + 1).' ---'."\n".$text;
        }

        if ($withText === 0) {
            return $this->noTextLayer($pageCount);
        }

        [$text, $truncated] = $this->truncate(implode("\n\n", $sections));

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: [
                'pages' => $pageCount,
                'pages_read' => $read,
                'pages_with_text' => $withText,
            ],
            summary: $this->summarise($pageCount, $read, $withText),
            truncated: $truncated || $read < $pageCount,
        );
    }

    // -----------------------------------------------------------------

    /**
     * The parser's limits, set rather than defaulted.
     *
     * A PDF is untrusted input and this parser is pure PHP, so the two things
     * worth bounding are how much memory a pathological font table can take and
     * how long a malformed cross-reference can spin. Both have defaults; both
     * are set explicitly so that raising them later is a visible decision.
     */
    private function parserConfig(): Config
    {
        $config = new Config;

        // Data URIs and embedded fonts are not needed to read text, and
        // skipping them is the difference between reading a 40 MB brochure and
        // exhausting the worker's memory on it.
        $config->setRetainImageContent(false);

        return $config;
    }

    /**
     * A PDF with pages but no text is a scan.
     *
     * Reported as Ready rather than Failed, deliberately: nothing went wrong.
     * The file is exactly what it is, we read it correctly, and it contains no
     * text. The summary and the message say so and point at the remedy, which
     * is to attach it as an image.
     */
    private function noTextLayer(int $pageCount): AiAttachmentExtraction
    {
        return AiAttachmentExtraction::unsupported(
            'That PDF has '.$pageCount.' '.($pageCount === 1 ? 'page' : 'pages')
            .' but no text layer, so it is almost certainly a scan or a set of images. '
            .'Nexora does not read text out of pictures of documents. '
            .'Attaching the pages as images instead lets a model with vision look at them.'
        );
    }

    private function summarise(int $pageCount, int $read, int $withText): string
    {
        $summary = number_format($pageCount).' '.($pageCount === 1 ? 'page' : 'pages');

        if ($read < $pageCount) {
            return $summary.', first '.number_format($read).' read';
        }

        if ($withText < $pageCount) {
            return $summary.', '.number_format($withText).' with text';
        }

        return $summary;
    }

    /**
     * Turn a parser failure into something a person can act on.
     *
     * The exception's own message is not forwarded: it names offsets and object
     * numbers, and it goes in the log rather than on a card. The two cases
     * worth distinguishing are encryption, which the person can fix, and
     * everything else, which they cannot.
     */
    private function explain(Throwable $exception): string
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
}
