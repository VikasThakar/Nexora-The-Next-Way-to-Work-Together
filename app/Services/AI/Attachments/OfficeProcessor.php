<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use App\Services\Docs\Import\ConvertedDocument;
use App\Services\Docs\Import\Converters\DocxConverter;
use App\Services\Docs\Import\Converters\PptxConverter;
use App\Services\Docs\Import\Converters\XlsxConverter;
use App\Services\Docs\Import\DocumentImportException;
use App\Support\Ooxml\OoxmlPackage;

/**
 * Office documents: .docx, .xlsx, .pptx.
 *
 * Read by the same converters that turn a file into a documentation page, so
 * the model sees what a page would show — Markdown with the document's
 * headings, lists, checkboxes and tables — rather than a run of words with the
 * structure stripped out. A to-do list read as structure is a list of to-dos;
 * read as flat text it is a paragraph.
 *
 * "Common document formats where safely supported", and the safety is the
 * interesting half of that sentence:
 *
 *   - no macro storage is read. A .docm or .xlsm is not in the allow-list at
 *     all, and even here `vbaProject.bin` is never opened;
 *   - no external references are followed. Fields, OLE links and remote images
 *     are markup we drop, not URLs we fetch; only web and mail links are kept,
 *     as text;
 *   - nothing is executed, and no external converter is invoked. There is no
 *     LibreOffice process, so there is no LibreOffice attack surface;
 *   - XML is read with App\Support\Xml\SafeXml, which refuses a part that
 *     declares a document type, loads no DTD, substitutes no entity and
 *     fetches nothing — so the XXE class of attack against an uploaded
 *     document does not arise — and streams, so a large part does not become
 *     a large tree in memory;
 *   - archive limits are enforced while reading (App\Support\Ooxml\OoxmlPackage),
 *     which is what stops a zip bomb.
 *
 * The legacy formats — .doc, .xls, .ppt — are deliberately absent. They are
 * OLE compound documents rather than ZIPs, reading them means a real parser,
 * and that parser would be a new dependency handling untrusted binary input.
 * They are refused at upload with a message that says to save as the modern
 * format, which takes one keystroke.
 */
class OfficeProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Document;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        if (! OoxmlPackage::available()) {
            return AiAttachmentExtraction::unsupported(
                'Reading Office documents needs the PHP zip extension, which this '
                .'deployment does not have. Attaching the file as a PDF works instead.'
            );
        }

        $extension = strtolower(pathinfo($attachment->filename ?? '', PATHINFO_EXTENSION));

        try {
            $converted = $this->withLocalCopy($attachment, fn (string $path): ?ConvertedDocument => match ($extension) {
                'docx' => (new DocxConverter)->convert($path),
                'xlsx' => (new XlsxConverter(max(10, (int) config('ai.attachments.csv.max_rows_in_prompt', 300))))->convert($path),
                'pptx' => (new PptxConverter)->convert($path),
                default => null,
            });
        } catch (DocumentImportException $exception) {
            return AiAttachmentExtraction::failed($exception->getMessage());
        }

        if (! $converted instanceof ConvertedDocument) {
            return AiAttachmentExtraction::failed(
                'That document could not be opened. It may be corrupt, or saved in an older '
                .'format — re-saving it as .docx, .xlsx or .pptx will fix it.'
            );
        }

        $text = $this->normalise($converted->markdown);
        $parts = $this->parts($text, $extension);

        if (trim($text) === '') {
            return AiAttachmentExtraction::ready(
                summary: 'No text found',
                structured: ['parts' => $parts],
            );
        }

        [$text, $truncated] = $this->truncate($text);

        $words = str_word_count((string) preg_replace('/[#*_`>\[\]|\\\\-]/', ' ', $text));

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: ['parts' => $parts, 'words' => $words, 'headings' => $this->headings($text)],
            summary: number_format($words).' '.($words === 1 ? 'word' : 'words')
                .($parts > 1 ? ', '.$parts.' '.$this->partNoun($extension, $parts) : ''),
            truncated: $truncated,
        );
    }

    // -----------------------------------------------------------------

    /**
     * Slides or sheets, counted from the headings the converter wrote for
     * them; a Word document is one part.
     */
    private function parts(string $markdown, string $extension): int
    {
        return match ($extension) {
            'pptx' => max(1, preg_match_all('/^## Slide \d+/m', $markdown)),
            'xlsx' => max(1, preg_match_all('/^## /m', $markdown)),
            default => 1,
        };
    }

    /**
     * The outline, as the attachment digest shows it.
     *
     * @return list<array{level: int, text: string}>
     */
    private function headings(string $markdown): array
    {
        preg_match_all('/^(#{1,6})\s+(.+)$/m', $markdown, $matches, PREG_SET_ORDER);

        return array_slice(array_map(
            static fn (array $match): array => ['level' => strlen($match[1]), 'text' => trim($match[2])],
            $matches,
        ), 0, 60);
    }

    private function partNoun(string $extension, int $count): string
    {
        return match ($extension) {
            'pptx' => $count === 1 ? 'slide' : 'slides',
            'xlsx' => $count === 1 ? 'sheet' : 'sheets',
            default => $count === 1 ? 'part' : 'parts',
        };
    }
}
