<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiAttachmentKind;
use App\Enums\AiAttachmentStatus;
use App\Models\AiAttachment;
use App\Models\AiSession;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Attachments\AiAttachmentContext;
use App\Services\AI\Attachments\AiAttachmentPipeline;
use App\Services\AI\Attachments\ImageProcessor;
use App\Services\AI\Data\AiMedia;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * The processors, against real bytes.
 *
 * Every test here runs the actual parser over a genuine file of that format —
 * a real PDF, a real ZIP-based .docx, a real PNG that GD decodes. Stubbing the
 * parsers would leave the interesting question untested: whether this
 * application can, in fact, read the formats it says it reads.
 */
class AiAttachmentProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // -----------------------------------------------------------------
    // Text and Markdown
    // -----------------------------------------------------------------

    public function test_plain_text_keeps_its_line_structure(): void
    {
        // Indentation and blank lines are the structure of a log or a stack
        // trace; flattening them is how one becomes unreadable.
        $record = $this->attach(FakeFiles::text(
            "ERROR at 10:04\n    at Handler.php:42\n\nRetried once.",
            'app.log'
        ));

        $this->assertSame(AiAttachmentKind::Text, $record->kind);
        $this->assertStringContainsString("ERROR at 10:04\n    at Handler.php:42", (string) $record->extracted_text);
        $this->assertSame(4, $record->structure()['lines']);
    }

    public function test_windows_line_endings_and_encodings_are_normalised(): void
    {
        $record = $this->attach(FakeFiles::text(
            mb_convert_encoding("Kärnkraft\r\nSecond line\r\n", 'Windows-1252', 'UTF-8'),
            'notes.txt'
        ));

        $text = (string) $record->extracted_text;

        // Readable, valid UTF-8, and not a line ending per line of cost.
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('Kärnkraft', $text);
        $this->assertStringNotContainsString("\r", $text);
    }

    public function test_markdown_keeps_its_syntax_and_records_an_outline(): void
    {
        $record = $this->attach(FakeFiles::text(
            "# Requirements\n\nSome prose.\n\n## Scope\n\nIn scope: everything.\n\n### Out of scope\n\nNothing.",
            'requirements.md'
        ));

        $this->assertSame(AiAttachmentKind::Markdown, $record->kind);

        // Sent as Markdown, not rendered to HTML: shorter, and a model reads
        // heading levels better than tags.
        $this->assertStringContainsString('## Scope', (string) $record->extracted_text);
        $this->assertStringNotContainsString('<h2', (string) $record->extracted_text);

        $headings = $record->structure()['headings'];

        $this->assertCount(3, $headings);
        $this->assertSame(['level' => 1, 'text' => 'Requirements'], $headings[0]);
        $this->assertSame(['level' => 3, 'text' => 'Out of scope'], $headings[2]);
    }

    /**
     * The outline is what makes a digest useful.
     *
     * A follow-up question about a document already discussed can be answered —
     * or honestly declined — from its section list, without re-sending it.
     */
    public function test_a_markdown_outline_appears_in_the_digest(): void
    {
        $record = $this->attach(FakeFiles::text(
            "# Contract\n\n## Indemnity\n\n".str_repeat("filler paragraph.\n", 200),
            'contract.md'
        ));

        $record->context_sent_at = now();
        $record->save();

        $built = app(AiAttachmentContext::class)->build($record->session, $record->user, 'what next?');

        $this->assertStringContainsString('Sections:', $built['text']);
        $this->assertStringContainsString('Indemnity', $built['text']);
        $this->assertStringContainsString('ALREADY PROVIDED EARLIER', $built['text']);
    }

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    public function test_a_pdf_is_read_page_by_page(): void
    {
        $record = $this->attach(FakeFiles::pdf([
            'Quarterly requirements',
            'Risk register: latency and staffing',
        ]));

        $this->assertSame(AiAttachmentStatus::Ready, $record->status);
        $this->assertSame(AiAttachmentKind::Pdf, $record->kind);

        $text = (string) $record->extracted_text;

        $this->assertStringContainsString('Quarterly requirements', $text);
        $this->assertStringContainsString('Risk register', $text);

        /*
         * The page labels are the point.
         *
         * "The indemnity cap is on page 14" is checkable in ten seconds; the
         * same claim without a page is not, and the prompt asks the model to
         * cite exactly this.
         */
        $this->assertStringContainsString('--- Page 1 ---', $text);
        $this->assertStringContainsString('--- Page 2 ---', $text);

        $this->assertSame(2, $record->structure()['pages']);
        $this->assertSame('2 pages', $record->summary);
    }

    /**
     * A scan is reported as a scan.
     *
     * Pages, no text. Reporting that as an empty document would have the model
     * summarising nothing; reporting it as a failure would suggest something
     * broke. It is neither, and the message names the remedy.
     */
    public function test_a_pdf_with_no_text_layer_says_it_is_probably_a_scan(): void
    {
        $record = $this->attach(FakeFiles::scannedPdf());

        $this->assertSame(AiAttachmentStatus::Unsupported, $record->status);
        $this->assertStringContainsString('no text layer', (string) $record->error);
        $this->assertStringContainsString('as images', (string) $record->error);
        $this->assertNull($record->extracted_text);
    }

    public function test_a_corrupt_pdf_fails_with_prose_rather_than_an_exception(): void
    {
        $record = $this->attach(
            UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 and then nonsense')
        );

        $this->assertSame(AiAttachmentStatus::Failed, $record->status);
        $this->assertNotEmpty($record->error);

        // Written for a person: no offsets, no object numbers, no stack trace.
        $this->assertStringNotContainsString('Exception', (string) $record->error);
        $this->assertStringNotContainsString('#0', (string) $record->error);
    }

    // -----------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------

    public function test_a_csv_is_profiled_over_every_row_and_sampled_for_the_prompt(): void
    {
        config(['ai.attachments.csv.max_rows_in_prompt' => 3]);

        $rows = ["Customer,Revenue\n"];

        // Ten rows, of which only three are quoted. The profile still covers
        // all ten, which is the whole design.
        foreach (range(1, 10) as $index) {
            $rows[] = "Customer {$index},".($index * 100)."\n";
        }

        $record = $this->attach(FakeFiles::text(implode('', $rows), 'revenue.csv'));

        $this->assertSame(AiAttachmentKind::Csv, $record->kind);
        $this->assertSame('10 rows × 2 columns', $record->summary);

        $structure = $record->structure();

        $this->assertSame(['Customer', 'Revenue'], $structure['headers']);
        $this->assertTrue($structure['has_headers']);
        $this->assertTrue($structure['sampled']);
        $this->assertSame(3, $structure['sample_rows']);

        $revenue = collect($structure['profile'])->firstWhere('name', 'Revenue');

        // Sum, mean and max of all ten rows: 100..1000.
        $this->assertSame('number', $revenue['type']);
        $this->assertSame(5500, $revenue['sum']);
        $this->assertSame(550, $revenue['mean']);
        $this->assertSame(1000, $revenue['max']);

        $text = (string) $record->extracted_text;

        // And the model is told which figures are exact and which are a sample.
        $this->assertStringContainsString('COLUMN PROFILE', $text);
        $this->assertStringContainsString('These figures are exact', $text);
        $this->assertStringContainsString('This is a SAMPLE', $text);
        $this->assertStringContainsString('Do not state a total', $text);
    }

    public function test_a_csv_without_a_header_row_gets_numbered_columns(): void
    {
        $record = $this->attach(FakeFiles::text("1,2\n3,4\n5,6\n", 'raw.csv'));

        $structure = $record->structure();

        $this->assertFalse($structure['has_headers']);
        $this->assertSame(['Column 1', 'Column 2'], $structure['headers']);

        // And no row was eaten as a header: three rows in, three rows profiled.
        $this->assertSame(3, $structure['rows']);
    }

    public function test_a_semicolon_delimited_export_is_read_correctly(): void
    {
        $record = $this->attach(FakeFiles::text(
            "Name;Amount\n\"Andersson, Karin\";1 200\n\"Bergström, Nils\";900\n",
            'europe.csv'
        ));

        $structure = $record->structure();

        $this->assertSame(';', $structure['delimiter']);
        $this->assertSame(['Name', 'Amount'], $structure['headers']);

        // The quoted comma inside a name did not become a column boundary.
        $this->assertSame(2, $structure['columns']);
        $this->assertStringContainsString('Andersson, Karin', (string) $record->extracted_text);
    }

    public function test_unusual_values_are_identified_for_the_model(): void
    {
        $rows = ["Item,Cost\n"];

        foreach (range(1, 30) as $index) {
            $rows[] = "Item {$index},100\n";
        }

        $rows[] = "Item 31,99000\n";

        $record = $this->attach(FakeFiles::text(implode('', $rows), 'costs.csv'));

        $outliers = $record->structure()['outliers'];

        // "Find unusual values" is a question this can answer exactly and a
        // model cannot answer at all from a sample.
        $this->assertNotEmpty($outliers);
        $this->assertSame('Cost', $outliers[0]['column']);
        $this->assertSame(99000, $outliers[0]['value']);
        $this->assertStringContainsString('UNUSUAL VALUES', (string) $record->extracted_text);
    }

    // -----------------------------------------------------------------
    // Images
    // -----------------------------------------------------------------

    public function test_an_image_is_validated_and_profiled_but_produces_no_text(): void
    {
        $record = $this->attach(FakeFiles::png(120, 80));

        $this->assertSame(AiAttachmentKind::Image, $record->kind);
        $this->assertSame(AiAttachmentStatus::Ready, $record->status);

        // No text, and that is the right answer rather than a gap: an image is
        // sent as a picture.
        $this->assertNull($record->extracted_text);
        $this->assertTrue($record->isVisual());
        $this->assertSame('120 × 80', $record->summary);
        $this->assertSame(120, $record->structure()['width']);
    }

    public function test_a_file_that_only_claims_to_be_an_image_fails_at_upload(): void
    {
        $record = $this->attach(FakeFiles::fakeImage());

        // The refusal happens where the content is read, in front of the person
        // who just attached it — not later, mid-answer, as a provider error.
        $this->assertSame(AiAttachmentStatus::Failed, $record->status);
        $this->assertStringContainsString('not an image', (string) $record->error);
    }

    /**
     * A picture is sent to a model that declares vision, and not to one that
     * does not.
     */
    public function test_an_image_reaches_a_vision_model_as_re_encoded_bytes(): void
    {
        $record = $this->attach(FakeFiles::png(60, 40));

        $session = $record->session;
        $session->model = 'claude-opus-5';
        $session->save();

        $built = app(AiAttachmentContext::class)->build($session, $record->user, 'what is this?');

        $this->assertCount(1, $built['media']);

        $media = $built['media'][0];

        $this->assertInstanceOf(AiMedia::class, $media);
        $this->assertSame('image/png', $media->mediaType);
        $this->assertStringContainsString('ATTACHED AS AN IMAGE', $built['text']);

        // Re-encoded rather than forwarded: what leaves is a file this
        // application wrote, so appended bytes and EXIF are gone.
        $decoded = base64_decode($media->base64, true);

        $this->assertNotFalse($decoded);
        $this->assertNotFalse(@getimagesizefromstring((string) $decoded));
    }

    public function test_an_image_is_not_sent_to_a_model_without_vision(): void
    {
        config(['ai.models.test-blind' => [
            'label' => 'Blind model',
            'provider' => 'anthropic',
            'vision' => false,
        ]]);

        $record = $this->attach(FakeFiles::png());

        $session = $record->session;
        $session->model = 'test-blind';
        $session->save();

        $built = app(AiAttachmentContext::class)->build($session, $record->user, 'what is this?');

        $this->assertSame([], $built['media']);

        // Honest, and it names the remedy rather than leaving the model to
        // describe a picture it never received.
        $this->assertStringContainsString('does not accept images', $built['text']);
        $this->assertStringContainsString('Do not describe or guess', $built['text']);
    }

    public function test_a_large_image_is_scaled_down_before_it_is_sent(): void
    {
        // 200 rather than a smaller figure: ImageProcessor floors the box at
        // 200px, because a box below that produces a picture no model can
        // read anything from.
        config(['ai.attachments.image.max_dimension' => 200]);

        $attachment = app(AttachmentStorage::class)->store(
            FakeFiles::png(400, 300, 'big.png'),
            $session = $this->conversation(),
            $session->board,
            $session->user,
        );

        $media = app(ImageProcessor::class)->prepare($attachment);

        $this->assertInstanceOf(AiMedia::class, $media);

        $size = @getimagesizefromstring((string) base64_decode($media->base64, true));

        // More pixels stop buying accuracy and start buying tokens.
        $this->assertSame(200, $size[0]);
        $this->assertSame(150, $size[1]);
    }

    // -----------------------------------------------------------------
    // Office documents
    // -----------------------------------------------------------------

    public function test_a_word_document_is_read_without_an_external_converter(): void
    {
        $record = $this->attach(FakeFiles::docx("Scope of work\nDeliverables are listed below."));

        $this->assertSame(AiAttachmentKind::Document, $record->kind);
        $this->assertSame(AiAttachmentStatus::Ready, $record->status);

        $text = (string) $record->extracted_text;

        $this->assertStringContainsString('Scope of work', $text);
        $this->assertStringContainsString('Deliverables are listed below.', $text);

        // Tags are gone, and no XML parser was involved — so no external
        // entity could have been resolved.
        $this->assertStringNotContainsString('<w:t>', $text);
    }

    public function test_a_spreadsheet_document_is_read_from_its_shared_strings(): void
    {
        $record = $this->attach(FakeFiles::xlsx(['Revenue', 'Costs', 'Margin']));

        $text = (string) $record->extracted_text;

        $this->assertStringContainsString('Revenue', $text);
        $this->assertStringContainsString('Margin', $text);
    }

    /**
     * An XXE payload in an uploaded document does not resolve.
     *
     * The reason OfficeProcessor strips tags with a regular expression rather
     * than a parser: there is no configuration of a regular expression that
     * fetches a URL or reads a file.
     */
    public function test_an_external_entity_in_a_document_is_not_resolved(): void
    {
        $hostile = '<?xml version="1.0"?>'
            .'<!DOCTYPE t [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'nexora-xxe-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $hostile);
        $zip->close();

        $record = $this->attach(UploadedFile::fake()->createWithContent(
            'hostile.docx',
            (string) file_get_contents($path)
        ));

        @unlink($path);

        $text = (string) $record->extracted_text;

        $this->assertStringNotContainsString('root:', $text);
        $this->assertStringNotContainsString('/bin/', $text);
    }

    public function test_a_legacy_office_format_is_refused_with_advice(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $session = app(AiSessionManager::class)->start(AiContextScope::board($board), $team);

        try {
            app(AiAttachmentPipeline::class)->attach(
                UploadedFile::fake()->create('old.doc', 8, 'application/msword'),
                $session,
                $team,
            );

            $this->fail('A .doc should not be accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('.doc', $exception->getMessage());
        }

        $this->assertSame(0, AiAttachment::query()->count());
    }

    // -----------------------------------------------------------------
    // Audio
    // -----------------------------------------------------------------

    /**
     * With no transcription credential, the file is stored and the card says so.
     *
     * The explicit requirement: a configuration message, not a silent failure.
     */
    public function test_audio_without_a_transcription_key_is_stored_and_explained(): void
    {
        config(['ai.openai.api_key' => null]);

        $record = $this->attach(UploadedFile::fake()->createWithContent('standup.mp3', 'not really audio'));

        $this->assertSame(AiAttachmentKind::Audio, $record->kind);
        $this->assertSame(AiAttachmentStatus::Unsupported, $record->status);

        $this->assertStringContainsString('OpenAI API key', (string) $record->error);
        $this->assertStringContainsString('has been stored', (string) $record->error);
        $this->assertStringContainsString('global AI settings', (string) $record->error);

        // Stored, so it is there when the key arrives.
        $this->assertNotNull($record->attachment);
        Storage::disk($record->attachment->disk)->assertExists($record->attachment->path);
    }

    public function test_audio_is_transcribed_when_a_key_is_configured(): void
    {
        config(['ai.openai.api_key' => 'sk-test-not-real']);

        Http::fake([
            '*/audio/transcriptions' => Http::response([
                'text' => 'We agreed to ship on Friday.',
                'language' => 'english',
                'duration' => 252.0,
            ]),
        ]);

        $record = $this->attach(UploadedFile::fake()->createWithContent('standup.mp3', 'audio bytes'));

        $this->assertSame(AiAttachmentStatus::Ready, $record->status);

        // Labelled a transcript, so an answer can say it is quoting a recording
        // rather than a written document.
        $this->assertStringContainsString('TRANSCRIPT', (string) $record->extracted_text);
        $this->assertStringContainsString('We agreed to ship on Friday.', (string) $record->extracted_text);
        $this->assertSame('4m 12s, English, 6 words', $record->summary);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/audio/transcriptions')
                && $request->hasHeader('Authorization');
        });
    }

    public function test_a_failed_transcription_reports_prose_rather_than_a_status_code(): void
    {
        config(['ai.openai.api_key' => 'sk-test-not-real']);

        Http::fake(['*/audio/transcriptions' => Http::response(['error' => 'too long'], 413)]);

        $record = $this->attach(UploadedFile::fake()->createWithContent('long.mp3', 'audio bytes'));

        $this->assertSame(AiAttachmentStatus::Failed, $record->status);
        $this->assertStringContainsString('could not be transcribed', (string) $record->error);
        $this->assertStringNotContainsString('413', (string) $record->error);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function conversation(): AiSession
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        return app(AiSessionManager::class)->start(AiContextScope::board($board), $team);
    }

    /**
     * Attach a file to a fresh conversation and return the processed row.
     *
     * The queue is `sync` in tests, so ProcessAiAttachment has already run by
     * the time this returns — which is why every assertion here can read the
     * finished extraction.
     */
    private function attach(UploadedFile $file): AiAttachment
    {
        $session = $this->conversation();

        return app(AiAttachmentPipeline::class)
            ->attach($file, $session, $session->user)
            ->refresh();
    }
}
