<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use App\Services\Docs\Import\Converters\CsvConverter;
use App\Services\Docs\Import\Converters\DocxConverter;
use App\Services\Docs\Import\Converters\PdfConverter;
use App\Services\Docs\Import\Converters\PptxConverter;
use App\Services\Docs\Import\Converters\XlsxConverter;
use App\Support\Ooxml\OoxmlPackage;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * A file — PDF, Word, text, Markdown, CSV, Excel, PowerPoint — as the Markdown
 * body of a documentation page.
 *
 * The one place a file becomes a page, used by the Import button and drop area
 * on the documentation screen and by the assistant when it imports a file it
 * was given. Both therefore produce the same page from the same file.
 *
 * What every page gets, whatever the format:
 *
 *   a title       the document's own — a leading `# Heading`, Word's Title
 *                 paragraph, YAML front matter — and otherwise the file name,
 *                 tidied ("sprint_notes-v2.docx" is "Sprint notes v2");
 *   headings      that start at level two, because the page's title is the
 *                 page's only level-one heading;
 *   a length      that fits a page: past `attachments.imports.body_max_characters`
 *                 the body is cut at a paragraph boundary — never inside a
 *                 table or a code block — and says the rest is in the
 *                 original, which is always attached to the page.
 *
 * Nothing here writes anything. App\Actions\Docs\ImportDocument creates the
 * page; this only reads.
 */
class DocumentImporter
{
    use NormalisesText;
    use ReadsStoredFiles;

    /**
     * An uploaded file, checked against its own contents first.
     *
     * @throws DocumentImportException
     */
    public function fromUpload(UploadedFile $file): ImportedDocument
    {
        $filename = $file->getClientOriginalName();
        $format = ImportFormat::fromFilename($filename);

        if ($format === null) {
            throw new DocumentImportException(ImportFormat::refusal($filename));
        }

        // The extension says what the file claims to be; the bytes say what it
        // is. A mismatch is refused, so a renamed binary is not read as text.
        if (! $format->acceptsDetectedType($file->getMimeType())) {
            throw new DocumentImportException(
                'That file does not look like a '.$format->label().' file, whatever its name says.'
            );
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new DocumentImportException('That upload could not be read. Try again.');
        }

        return $this->fromPath($path, $filename, $format);
    }

    /**
     * A stored attachment — the original of a page, or a file given to the
     * assistant.
     *
     * @throws DocumentImportException
     */
    public function fromAttachment(Attachment $attachment): ImportedDocument
    {
        $filename = (string) $attachment->filename;
        $format = ImportFormat::fromFilename($filename);

        if ($format === null) {
            throw new DocumentImportException(ImportFormat::refusal($filename));
        }

        $result = $this->withLocalCopy($attachment, fn (string $path): ImportedDocument => $this->fromPath($path, $filename, $format));

        if (! $result instanceof ImportedDocument) {
            throw new DocumentImportException('The stored file is no longer available.');
        }

        return $result;
    }

    /**
     * @throws DocumentImportException
     */
    public function fromPath(string $path, string $filename, ?ImportFormat $format = null): ImportedDocument
    {
        $format ??= ImportFormat::fromFilename($filename);

        if ($format === null) {
            throw new DocumentImportException(ImportFormat::refusal($filename));
        }

        if (in_array($format, [ImportFormat::Docx, ImportFormat::Xlsx, ImportFormat::Pptx], true) && ! OoxmlPackage::available()) {
            throw new DocumentImportException(
                'Reading Office files needs the PHP zip extension, which this server does not have. Import it as a PDF instead.'
            );
        }

        $maxRows = max(10, (int) config('attachments.imports.max_table_rows', 500));

        try {
            $converted = match ($format) {
                ImportFormat::Markdown => $this->markdown($path),
                ImportFormat::Text => new ConvertedDocument(TextToMarkdown::convert($this->text($path))),
                ImportFormat::Csv => (new CsvConverter($maxRows))->convert($path, $filename, fn (string $raw): string => $this->normalise($raw)),
                ImportFormat::Pdf => (new PdfConverter((int) config('attachments.imports.pdf_max_pages', 300)))->convert($path),
                ImportFormat::Docx => (new DocxConverter)->convert($path),
                ImportFormat::Xlsx => (new XlsxConverter($maxRows))->convert($path),
                ImportFormat::Pptx => (new PptxConverter)->convert($path),
            };
        } catch (DocumentImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new DocumentImportException(
                'That file could not be read. It may be corrupt, or not really a '.$format->label().' file.'
            );
        }

        return $this->finish($converted, $filename, $format);
    }

    // -----------------------------------------------------------------

    private function text(string $path): string
    {
        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            throw new DocumentImportException('That file could not be read.');
        }

        return $this->normalise($raw);
    }

    /**
     * Markdown is kept as written, less YAML front matter — whose `title:` is
     * the document's title.
     */
    private function markdown(string $path): ConvertedDocument
    {
        $text = $this->text($path);
        $title = null;

        if (preg_match('/\A---\n(.*?)\n(?:---|\.\.\.)\n/s', $text, $match) === 1) {
            if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/mi', $match[1], $found) === 1) {
                $title = $found[1];
            }

            $text = substr($text, strlen($match[0]));
        }

        // Raw HTML is stripped when a page is shown; escaping the angle
        // brackets of anything that looks like a tag keeps it visible.
        $text = (string) preg_replace('/<(?=[a-zA-Z\/!])/', '\\<', $text);

        return new ConvertedDocument(trim($text), $title);
    }

    private function finish(ConvertedDocument $converted, string $filename, ImportFormat $format): ImportedDocument
    {
        $markdown = trim($converted->markdown);
        $title = $converted->title !== null ? trim($converted->title) : null;

        // A leading level-one heading is the title, and not repeated below it.
        if (preg_match('/\A#\s+(.+?)\s*#*\s*(?:\n|\z)/u', $markdown, $match) === 1) {
            $title ??= $this->unescape($match[1]);

            if ($this->unescape($match[1]) === $title) {
                $markdown = ltrim(substr($markdown, strlen($match[0])));
            }
        }

        if ($title === null || $title === '') {
            $title = $this->titleFromFilename($filename);
        }

        $markdown = $this->shiftHeadings($markdown);

        [$markdown, $truncated] = $this->fit($markdown);

        $warnings = $converted->warnings;

        if ($truncated) {
            $warnings[] = 'The file was longer than one page can hold, so the end of it is not on the page; the whole file is attached.';
        }

        if (trim($markdown) === '') {
            $warnings[] = 'No text was found in the file. The page has been created with the file attached.';
        }

        return new ImportedDocument(
            title: mb_substr($title, 0, 200),
            markdown: $markdown,
            format: $format,
            truncated: $truncated,
            warnings: $warnings,
            stats: $this->stats($markdown),
        );
    }

    /**
     * Headings start at level two when the body has any at level one.
     */
    private function shiftHeadings(string $markdown): string
    {
        if (preg_match('/^#\s/m', $this->outsideCode($markdown)) !== 1) {
            return $markdown;
        }

        $inFence = false;

        $lines = array_map(function (string $line) use (&$inFence): string {
            if (preg_match('/^(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;

                return $line;
            }

            if ($inFence || preg_match('/^(#{1,6})(\s.*)$/', $line, $match) !== 1) {
                return $line;
            }

            return str_repeat('#', min(6, strlen($match[1]) + 1)).$match[2];
        }, explode("\n", $markdown));

        return implode("\n", $lines);
    }

    /**
     * The body cut to what a page holds, at a paragraph boundary outside any
     * code block, with a note saying so.
     *
     * @return array{0: string, 1: bool}
     */
    private function fit(string $markdown): array
    {
        $limit = max(10000, (int) config('attachments.imports.body_max_characters', 200000)) - 300;

        if (mb_strlen($markdown) <= $limit) {
            return [$markdown, false];
        }

        $cut = mb_substr($markdown, 0, $limit);
        $boundary = mb_strrpos($cut, "\n\n");

        if ($boundary !== false && $boundary > $limit * 0.5) {
            $cut = mb_substr($cut, 0, $boundary);
        }

        // Never leave a code block open: cut before the fence that opened it.
        if (preg_match_all('/^(```|~~~)/m', $cut) % 2 === 1) {
            $cut = mb_substr($cut, 0, (int) mb_strrpos($cut, "\n```") ?: (int) mb_strrpos($cut, "\n~~~"));
        }

        return [rtrim($cut)."\n\n---\n\n*The rest of this file did not fit on one page. The whole original is attached to this page.*", true];
    }

    private function outsideCode(string $markdown): string
    {
        return (string) preg_replace('/^(```|~~~).*?^\1/ms', '', $markdown);
    }

    /**
     * @return array{words: int, headings: int, tasks: int, open_tasks: int}
     */
    private function stats(string $markdown): array
    {
        return [
            'words' => str_word_count((string) preg_replace('/[#*_`>\[\]|\\\\-]/', ' ', $markdown)),
            'headings' => preg_match_all('/^#{1,6}\s/m', $markdown),
            'tasks' => preg_match_all('/^\s*- \[[ xX]\]\s/m', $markdown),
            'open_tasks' => preg_match_all('/^\s*- \[ \]\s/m', $markdown),
        ];
    }

    /**
     * "sprint_notes-v2.final.docx" as "Sprint notes v2.final".
     */
    private function titleFromFilename(string $filename): string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[_\s]+|(?<=\S)-(?=\S)/u', ' ', $stem));

        if ($stem === '') {
            return 'Imported document';
        }

        return mb_strtoupper(mb_substr($stem, 0, 1)).mb_substr($stem, 1);
    }

    private function unescape(string $text): string
    {
        return trim((string) preg_replace('/\\\\(.)/u', '$1', strip_tags(str_replace(['**', '__'], '', $text))));
    }
}
