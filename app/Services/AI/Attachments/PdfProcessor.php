<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use App\Support\Pdf\PdfText;
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
            ['pages' => $pages, 'total' => $pageCount] = PdfText::pages($contents, self::MAX_PAGES);
        } catch (Throwable $exception) {
            return AiAttachmentExtraction::failed(PdfText::explain($exception));
        }

        if ($pageCount === 0) {
            return AiAttachmentExtraction::failed(
                'That PDF has no readable pages. It may be corrupt or password-protected.'
            );
        }

        $sections = [];
        $read = 0;
        $withText = 0;

        foreach ($pages as $index => $page) {
            $read++;

            $text = $this->normalise($page);

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
            PdfText::noTextLayer($pageCount)
            .' Attaching the pages as images instead lets a model with vision look at them.'
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
}
