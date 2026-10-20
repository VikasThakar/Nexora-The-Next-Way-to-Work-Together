<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Services\Docs\Import\DocumentImporter;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\ImportFormat;
use App\Support\RichText\RichText;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Turning a file into the Markdown body of a documentation page.
 *
 * The files these tests build are real — ZIPs of WordprocessingML, PDFs with
 * text operators — so the converters run exactly as they do on an upload. The
 * point of most of them is structure: a to-do list that arrives as a paragraph
 * is a to-do list lost.
 */
class DocumentImporterTest extends TestCase
{
    private function import(UploadedFile $file): \App\Services\Docs\Import\ImportedDocument
    {
        return app(DocumentImporter::class)->fromUpload($file);
    }

    // -----------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------

    public function test_a_text_to_do_list_keeps_its_structure(): void
    {
        $document = $this->import(FakeFiles::text(implode("\n", [
            'Sprint notes',
            '',
            'Bugs:',
            '• Login button does nothing on Safari',
            '- Export cuts off the last row',
            '',
            'TO DO',
            '[ ] Write the release notes',
            '[x] Book the demo',
            '☐ Ask legal about the terms',
            '  - Nested detail',
            '1. First step',
            '2. Second step',
            '',
            'Plain line one',
            'Plain line two',
        ]), 'sprint-notes.txt'));

        $markdown = $document->markdown;

        $this->assertSame('Sprint notes', $document->title);
        $this->assertStringContainsString("## Bugs\n\n- Login button does nothing on Safari\n- Export cuts off the last row", $markdown);
        $this->assertStringContainsString('## TO DO', $markdown);
        $this->assertStringContainsString('- [ ] Write the release notes', $markdown);
        $this->assertStringContainsString('- [x] Book the demo', $markdown);
        $this->assertStringContainsString('- [ ] Ask legal about the terms', $markdown);
        $this->assertStringContainsString("\n    - Nested detail", $markdown);
        $this->assertStringContainsString("1. First step\n2. Second step", $markdown);

        // A text file's line breaks are its author's, and are kept.
        $this->assertStringContainsString("Plain line one\\\nPlain line two", $markdown);

        $this->assertSame(['words' => $document->stats['words'], 'headings' => 2, 'tasks' => 3, 'open_tasks' => 2], $document->stats);
    }

    public function test_text_that_would_read_as_markup_is_escaped(): void
    {
        $document = $this->import(FakeFiles::text("Notes\n\nPress <Enter> to continue in C++ and C++ mode.\n# not a heading? it is one", 'notes.txt'));

        $this->assertStringContainsString('Press \\<Enter> to continue in C\\+\\+ and C\\+\\+ mode.', $document->markdown);

        // Rendered, the brackets survive the HTML stripping and nothing is
        // underlined.
        $html = app(\App\Support\Markdown::class)->toHtml($document->markdown);
        $this->assertStringContainsString('&lt;Enter&gt;', $html);
        $this->assertStringNotContainsString('<u>', $html);
    }

    // -----------------------------------------------------------------
    // Markdown
    // -----------------------------------------------------------------

    public function test_markdown_is_kept_and_its_title_taken_from_the_first_heading(): void
    {
        $document = $this->import(FakeFiles::text("# Release plan\n\n# Phase one\n\n- [ ] Ship it\n\n## Detail\n\nText.", 'plan.md'));

        $this->assertSame('Release plan', $document->title);
        $this->assertStringNotContainsString('Release plan', $document->markdown);

        // The page title is the only level-one heading, so the rest move down.
        $this->assertStringContainsString("## Phase one", $document->markdown);
        $this->assertStringContainsString("### Detail", $document->markdown);
        $this->assertStringContainsString('- [ ] Ship it', $document->markdown);
        $this->assertSame(ImportFormat::Markdown, $document->format);
    }

    public function test_front_matter_gives_the_title_and_is_not_imported(): void
    {
        $document = $this->import(FakeFiles::text("---\ntitle: \"Runbook\"\nowner: ops\n---\nBody <b>bold</b>.", 'runbook.md'));

        $this->assertSame('Runbook', $document->title);
        $this->assertStringNotContainsString('owner: ops', $document->markdown);
        $this->assertStringContainsString('Body \\<b>bold\\</b>.', $document->markdown);
    }

    public function test_a_text_files_opening_line_on_its_own_is_its_title(): void
    {
        $document = $this->import(FakeFiles::text("Known issues\n\n- Search ignores accents", 'bug-list.txt'));

        $this->assertSame('Known issues', $document->title);
        $this->assertSame('- Search ignores accents', $document->markdown);
    }

    public function test_without_a_heading_the_title_comes_from_the_file_name(): void
    {
        $document = $this->import(FakeFiles::text('Just a line.', 'sprint_notes-v2.txt'));

        $this->assertSame('Sprint notes v2', $document->title);
    }

    // -----------------------------------------------------------------
    // Word
    // -----------------------------------------------------------------

    public function test_a_word_document_keeps_headings_lists_checkboxes_tables_and_links(): void
    {
        $styles = '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Rubrik2"><w:name w:val="heading 2"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Custom"><w:name w:val="My heading"/><w:basedOn w:val="Rubrik2"/></w:style>';

        $numbering = '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/></w:lvl>'
            .'<w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/><w:lvlText w:val="o"/></w:lvl></w:abstractNum>'
            .'<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/></w:lvl></w:abstractNum>'
            .'<w:abstractNum w:abstractNumId="2"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val="☐"/></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
            .'<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
            .'<w:num w:numId="3"><w:abstractNumId w:val="2"/></w:num>';

        $relationships = '<Relationship Id="rId1" Type="hyperlink" Target="https://example.com/spec" TargetMode="External"/>'
            .'<Relationship Id="rId2" Type="hyperlink" Target="javascript:alert(1)" TargetMode="External"/>';

        $p = static fn (string $text, string $props = '', string $runProps = ''): string => '<w:p>'.($props !== '' ? '<w:pPr>'.$props.'</w:pPr>' : '')
            .'<w:r>'.($runProps !== '' ? '<w:rPr>'.$runProps.'</w:rPr>' : '').'<w:t xml:space="preserve">'.$text.'</w:t></w:r></w:p>';
        $list = static fn (int $num, int $level, string $text): string => '<w:p><w:pPr><w:numPr><w:ilvl w:val="'.$level.'"/><w:numId w:val="'.$num.'"/></w:numPr></w:pPr><w:r><w:t>'.$text.'</w:t></w:r></w:p>';

        $body = $p('Sprint 14 plan', '<w:pStyle w:val="Title"/>')
            .$p('Bugs', '<w:pStyle w:val="Heading1"/>')
            .$list(1, 0, 'Login fails on Safari')
            .$list(1, 1, 'Only on iOS 17')
            .$list(1, 0, 'Export loses rows')
            .$p('Steps', '<w:pStyle w:val="Custom"/>')
            .$list(2, 0, 'Open the board')
            .$list(2, 0, 'Press export')
            .$p('To do', '<w:pStyle w:val="Rubrik2"/>')
            .$list(3, 0, 'Write tests')
            .'<w:p><w:sdt><w:sdtPr><w14:checkbox><w14:checked w14:val="1"/></w14:checkbox></w:sdtPr><w:sdtContent><w:r><w:t>☒</w:t></w:r></w:sdtContent></w:sdt><w:r><w:t xml:space="preserve"> Book the demo</w:t></w:r></w:p>'
            .'<w:p><w:r><w:sym w:font="Wingdings" w:char="F0A8"/></w:r><w:r><w:t xml:space="preserve"> Ask legal</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">This is </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>important</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve"> and </w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>urgent</w:t></w:r>'
            .'<w:del><w:r><w:delText>removed words</w:delText></w:r></w:del>'
            .'<w:r><w:t xml:space="preserve">. See </w:t></w:r><w:hyperlink r:id="rId1"><w:r><w:t>the spec</w:t></w:r></w:hyperlink>'
            .'<w:r><w:t xml:space="preserve"> and </w:t></w:r><w:hyperlink r:id="rId2"><w:r><w:t>this</w:t></w:r></w:hyperlink><w:r><w:t>.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:drawing>picture</w:drawing></w:r></w:p>'
            .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Owner</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Task</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Alex</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Review | sign off</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

        $document = $this->import(FakeFiles::docxDocument($body, $styles, $numbering, $relationships));
        $markdown = $document->markdown;

        $this->assertSame('Sprint 14 plan', $document->title);
        $this->assertStringNotContainsString('Sprint 14 plan', $markdown);

        $this->assertStringContainsString("## Bugs\n\n- Login fails on Safari\n    - Only on iOS 17\n- Export loses rows", $markdown);

        // A custom style built on a localised heading style is a heading too.
        $this->assertStringContainsString("### Steps\n\n1. Open the board\n1. Press export", $markdown);

        // A list whose bullet is a box is a list of to-dos, and so are
        // checkbox controls and box symbols.
        $this->assertStringContainsString('- [ ] Write tests', $markdown);
        $this->assertStringContainsString('- [x] Book the demo', $markdown);
        $this->assertStringContainsString('- [ ] Ask legal', $markdown);

        $this->assertStringContainsString('This is **important** and *urgent*. See [the spec](https://example.com/spec) and this.', $markdown);
        $this->assertStringNotContainsString('removed words', $markdown);
        $this->assertStringNotContainsString('javascript', $markdown);

        $this->assertStringContainsString("| Owner | Task |\n| --- | --- |\n| Alex | Review \\| sign off |", $markdown);

        $this->assertContains('A picture in the document was not imported; it is in the attached original.', $document->warnings);
        $this->assertSame(3, $document->stats['tasks']);
        $this->assertSame(2, $document->stats['open_tasks']);
    }

    /**
     * Opening the page in the editor and saving it again does not rewrite it:
     * the importer writes the editor's own Markdown forms.
     */
    public function test_an_imported_body_survives_a_round_trip_through_the_editor(): void
    {
        $document = $this->import(FakeFiles::text("Notes\n\nBugs:\n- One\n    - Nested\n- Two\n\n[ ] Open\n[x] Done", 'notes.txt'));

        $richText = app(RichText::class);

        $this->assertSame($document->markdown, $richText->toMarkdown($richText->toEditorHtml($document->markdown)));
    }

    public function test_a_word_part_that_declares_a_doctype_is_refused(): void
    {
        $file = FakeFiles::docxDocument('<w:p><w:r><w:t>x</w:t></w:r></w:p>', extra: [
            'word/styles.xml' => '<?xml version="1.0"?><!DOCTYPE s [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:styles>&e;</w:styles>',
        ]);

        $this->expectException(DocumentImportException::class);

        $this->import($file);
    }

    public function test_a_zip_that_is_not_a_word_document_is_refused_with_advice(): void
    {
        $this->expectException(DocumentImportException::class);
        $this->expectExceptionMessage('could not be opened');

        $this->import(UploadedFile::fake()->createWithContent('broken.docx', 'not a zip at all'));
    }

    // -----------------------------------------------------------------
    // Spreadsheets, decks, CSV
    // -----------------------------------------------------------------

    public function test_a_workbook_becomes_a_table_per_sheet(): void
    {
        $document = $this->import(FakeFiles::xlsx(['Item', 'Cost', 'Owner']));

        $this->assertStringContainsString("## Budget\n\n| Item | Cost | Owner |\n| --- | --- | --- |\n| 42 | 43 | 44 |", $document->markdown);
        $this->assertSame('Figures', $document->title);
    }

    public function test_a_deck_becomes_a_heading_per_slide_with_its_points_and_notes(): void
    {
        $document = $this->import(FakeFiles::pptx([
            ['title' => 'Roadmap', 'points' => ['Q1: search', [1, 'Typo tolerance'], 'Q2: billing'], 'notes' => 'Mention the beta.'],
            ['title' => 'Risks', 'points' => ['Hiring']],
        ]));

        $markdown = $document->markdown;

        $this->assertStringContainsString("## Slide 1: Roadmap\n\n- Q1: search\n    - Typo tolerance\n- Q2: billing\n\n> **Notes:** Mention the beta.", $markdown);
        $this->assertStringContainsString("## Slide 2: Risks\n\n- Hiring", $markdown);
        $this->assertStringNotContainsString('> **Notes:** 1', $markdown);
    }

    public function test_a_csv_becomes_a_table_with_its_delimiter_detected(): void
    {
        $document = $this->import(FakeFiles::text("Ticket;Status\nLogin;Open\n\"Export; CSV\";Done", 'status.csv'));

        $this->assertStringContainsString("| Ticket | Status |\n| --- | --- |\n| Login | Open |\n| Export; CSV | Done |", $document->markdown);
    }

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    public function test_a_pdf_loses_its_running_header_and_page_numbers_and_keeps_its_lists(): void
    {
        $document = $this->import(FakeFiles::pdf([
            "Acme Ltd - Internal\nRelease checklist\n- Freeze the branch\n[ ] Run the migrations\nPage 1 of 3",
            "Acme Ltd - Internal\n[x] Tell support\nThe deploy window is the\nusual Tuesday slot.\nPage 2 of 3",
            "Acme Ltd - Internal\nKnown issues\nNone yet.\nPage 3 of 3",
        ], 'checklist.pdf'));

        $markdown = $document->markdown;

        $this->assertStringNotContainsString('Acme Ltd', $markdown);
        $this->assertStringNotContainsString('Page 2 of 3', $markdown);
        $this->assertStringContainsString('- Freeze the branch', $markdown);
        $this->assertStringContainsString('- [ ] Run the migrations', $markdown);
        $this->assertStringContainsString('- [x] Tell support', $markdown);

        // Lines a page wrapped are one paragraph again.
        $this->assertStringContainsString('The deploy window is the usual Tuesday slot.', $markdown);
    }

    public function test_a_scanned_pdf_is_refused_with_a_reason(): void
    {
        $this->expectException(DocumentImportException::class);
        $this->expectExceptionMessage('no text layer');

        $this->import(FakeFiles::scannedPdf());
    }

    // -----------------------------------------------------------------
    // Limits and refusals
    // -----------------------------------------------------------------

    public function test_a_body_longer_than_a_page_is_cut_at_a_paragraph_and_says_so(): void
    {
        config(['attachments.imports.body_max_characters' => 10000]);

        $paragraphs = implode("\n\n", array_map(static fn (int $i): string => 'Paragraph '.$i.' '.str_repeat('word ', 40), range(1, 200)));

        $document = $this->import(FakeFiles::text($paragraphs, 'long.md'));

        $this->assertTrue($document->truncated);
        $this->assertLessThanOrEqual(10000, mb_strlen($document->markdown));
        $this->assertStringEndsWith('*The rest of this file did not fit on one page. The whole original is attached to this page.*', $document->markdown);
        $this->assertMatchesRegularExpression('/word\s*\n\n---\n\n\*The rest/', $document->markdown);
    }

    public function test_a_legacy_office_file_is_refused_with_what_to_do(): void
    {
        $this->expectException(DocumentImportException::class);
        $this->expectExceptionMessage('Save it as .docx');

        $this->import(UploadedFile::fake()->create('old.doc', 8, 'application/msword'));
    }

    public function test_a_file_whose_bytes_disagree_with_its_name_is_refused(): void
    {
        $this->expectException(DocumentImportException::class);
        $this->expectExceptionMessage('does not look like a PDF file');

        $this->import(UploadedFile::fake()->create('report.pdf', 8, 'application/x-msdownload'));
    }
}
