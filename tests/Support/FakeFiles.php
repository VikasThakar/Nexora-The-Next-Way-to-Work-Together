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

        // One header row naming each value by its shared-string index, as
        // Excel writes it, then a row of numbers.
        $header = '';
        $numbers = '';

        foreach (array_keys($values) as $index) {
            $column = chr(65 + $index);
            $header .= '<c r="'.$column.'1" t="s"><v>'.$index.'</v></c>';
            $numbers .= '<c r="'.$column.'2"><v>'.(42 + $index).'</v></c>';
        }

        return self::zip($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst '
                .'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .$strings.'</sst>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook '
                .'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet '
                .'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData><row r="1">'.$header.'</row><row r="2">'.$numbers.'</row></sheetData></worksheet>',
        ]);
    }

    /**
     * A .docx with a body written as WordprocessingML, and optionally its
     * styles, numbering and relationships — for testing structure: headings,
     * lists, checkboxes, tables and links.
     *
     * @param  array<string, string>  $extra  further parts, by name
     */
    public static function docxDocument(
        string $body,
        ?string $styles = null,
        ?string $numbering = null,
        ?string $relationships = null,
        string $name = 'notes.docx',
        array $extra = [],
    ): UploadedFile {
        $namespaces = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document '.$namespaces.'>'
                .'<w:body>'.$body.'</w:body></w:document>',
        ];

        if ($styles !== null) {
            $parts['word/styles.xml'] = '<?xml version="1.0"?><w:styles '.$namespaces.'>'.$styles.'</w:styles>';
        }

        if ($numbering !== null) {
            $parts['word/numbering.xml'] = '<?xml version="1.0"?><w:numbering '.$namespaces.'>'.$numbering.'</w:numbering>';
        }

        if ($relationships !== null) {
            $parts['word/_rels/document.xml.rels'] = '<?xml version="1.0"?><Relationships '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
        }

        return self::zip($name, $parts + $extra);
    }

    /**
     * A .pptx with one slide per entry: a title and its bullet points, each a
     * string or [level, text].
     *
     * @param  list<array{title: string, points: list<string|array{0: int, 1: string}>, notes?: string}>  $slides
     */
    public static function pptx(array $slides, string $name = 'deck.pptx'): UploadedFile
    {
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $parts = ['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />'];
        $ids = '';
        $rels = '';

        foreach ($slides as $index => $slide) {
            $number = $index + 1;

            $points = '';

            foreach ($slide['points'] as $point) {
                [$level, $text] = is_array($point) ? $point : [0, $point];
                $points .= '<a:p><a:pPr lvl="'.$level.'"/><a:r><a:t>'.$escape($text).'</a:t></a:r></a:p>';
            }

            $parts['ppt/slides/slide'.$number.'.xml'] = '<?xml version="1.0"?><p:sld '.$ns.'><p:cSld><p:spTree>'
                .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$escape($slide['title']).'</a:t></a:r></a:p></p:txBody></p:sp>'
                .'<p:sp><p:nvSpPr><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr><p:txBody>'.$points.'</p:txBody></p:sp>'
                .'</p:spTree></p:cSld></p:sld>';

            if (isset($slide['notes'])) {
                $parts['ppt/slides/_rels/slide'.$number.'.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide'.$number.'.xml"/>'
                    .'</Relationships>';
                $parts['ppt/notesSlides/notesSlide'.$number.'.xml'] = '<?xml version="1.0"?><p:notes '.$ns.'><p:cSld><p:spTree>'
                    .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="sldNum"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$number.'</a:t></a:r></a:p></p:txBody></p:sp>'
                    .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$escape($slide['notes']).'</a:t></a:r></a:p></p:txBody></p:sp>'
                    .'</p:spTree></p:cSld></p:notes>';
            }

            $ids .= '<p:sldId id="'.(255 + $number).'" r:id="rId'.$number.'"/>';
            $rels .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$number.'.xml"/>';
        }

        $parts['ppt/presentation.xml'] = '<?xml version="1.0"?><p:presentation '.$ns.'><p:sldIdLst>'.$ids.'</p:sldIdLst></p:presentation>';
        $parts['ppt/_rels/presentation.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';

        return self::zip($name, $parts);
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
