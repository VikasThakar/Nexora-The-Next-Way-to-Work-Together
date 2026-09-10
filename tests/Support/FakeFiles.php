<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Real file bytes for the attachment tests.
 *
 * The point of this class is that the attachment tests exercise the actual
 * parsers rather than a mock of them. A PDF test that stubs the PDF parser
 * proves nothing about whether this application can read a PDF; these produce
 * genuine PDF, DOCX, XLSX and PNG bytes, so PdfProcessor, OfficeProcessor and
 * ImageProcessor run for real against them.
 *
 * A note on detected types
 * ------------------------
 * Illuminate\Http\Testing\File::getMimeType() answers from the *filename*
 * rather than by reading the bytes, which is the one place a test diverges from
 * production — where App\Services\AI\Attachments\AiAttachmentClassifier gets a
 * type detected from content. That divergence is useful rather than a problem:
 * passing an explicit type to UploadedFile::fake()->create() is how a test
 * expresses "a file whose bytes disagree with its name", which is exactly the
 * case the classifier exists to refuse.
 */
class FakeFiles
{
    /**
     * A valid, uncompressed PDF with one page per string given.
     *
     * Built by hand so the suite needs neither a binary fixture nor a PDF
     * library to write one. The xref offsets are computed rather than
     * hard-coded — a hand-counted offset would break the moment anybody edited
     * a line of the content stream, and it would break as "the parser cannot
     * read our PDFs", which is a bad afternoon.
     *
     * @param  list<string>  $pages  the text of each page
     */
    public static function pdf(array $pages, string $name = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::pdfBytes($pages));
    }

    /**
     * A PDF with pages but no text at all: what a scan looks like.
     *
     * The page exists and is a valid page; it simply has no text operators. The
     * product reports that honestly rather than as an empty document — see
     * PdfProcessor::noTextLayer().
     */
    public static function scannedPdf(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::pdfBytes(['']));
    }

    /**
     * @param  list<string>  $pages
     */
    public static function pdfBytes(array $pages): string
    {
        $objects = [];
        $kids = [];
        $nextId = 4;

        foreach ($pages as $text) {
            $contentId = $nextId++;
            $pageId = $nextId++;

            $lines = [];
            $y = 700;

            foreach (explode("\n", $text) as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $lines[] = "BT /F1 12 Tf 72 {$y} Td ({$escaped}) Tj ET";
                $y -= 18;
            }

            $stream = implode("\n", $lines);

            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                ."/Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";

            $kids[] = "{$pageId} 0 R";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";

        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        return $pdf."trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    /**
     * A real .docx: a ZIP with the parts Word writes and OfficeProcessor reads.
     */
    public static function docx(string $text, string $name = 'brief.docx'): UploadedFile
    {
        $paragraphs = implode('', array_map(
            static fn (string $line): string => '<w:p><w:r><w:t>'
                .htmlspecialchars($line, ENT_QUOTES | ENT_XML1, 'UTF-8')
                .'</w:t></w:r></w:p>',
            explode("\n", $text)
        ));

        return self::zip($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />',
            'word/document.xml' => '<?xml version="1.0"?><w:document '
                .'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:body>'.$paragraphs.'</w:body></w:document>',
        ]);
    }

    /**
     * A real .xlsx, with its text in the shared string table as Excel writes it.
     *
     * @param  list<string>  $values
     */
    public static function xlsx(array $values, string $name = 'figures.xlsx'): UploadedFile
    {
        $strings = implode('', array_map(
            static fn (string $value): string => '<si><t>'
                .htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                .'</t></si>',
            $values
        ));

        return self::zip($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst '
                .'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .$strings.'</sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet '
                .'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData><row><c><v>42</v></c></row></sheetData></worksheet>',
        ]);
    }

    /**
     * A PNG that GD can actually decode, at the size given.
     *
     * Real pixels rather than UploadedFile::fake()->image(), which produces a
     * file whose header is right and whose body GD may refuse — and the whole
     * point of ImageProcessor is that it decodes and re-encodes.
     */
    public static function png(int $width = 40, int $height = 30, string $name = 'shot.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        $background = imagecolorallocate($image, 20, 40, 80);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * A file named like an image whose bytes are not one.
     *
     * The classifier accepts it — the name and the declared type agree — and
     * ImageProcessor is then the layer that refuses, which is the behaviour
     * worth pinning: content validation happens where the content is read.
     */
    public static function fakeImage(string $name = 'not-really.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'this is not a picture');
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function zip(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nexora-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);

        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * A plain text upload with content, of any extension.
     */
    public static function text(string $contents, string $name = 'notes.txt'): File
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
