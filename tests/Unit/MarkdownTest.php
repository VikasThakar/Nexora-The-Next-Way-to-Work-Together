<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Markdown;
use Tests\TestCase;

/**
 * Ticket descriptions are written by customers as well as staff, so the
 * renderer output is untrusted input that gets echoed unescaped. These tests
 * pin the two settings that make that safe.
 */
class MarkdownTest extends TestCase
{
    private Markdown $markdown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markdown = new Markdown;
    }

    public function test_it_renders_ordinary_markdown(): void
    {
        $this->assertStringContainsString('<h2>Heading</h2>', $this->markdown->toHtml('## Heading'));
        $this->assertStringContainsString('<strong>bold</strong>', $this->markdown->toHtml('**bold**'));
        $this->assertStringContainsString('<li>one</li>', $this->markdown->toHtml("- one\n- two"));
        $this->assertStringContainsString('<code>', $this->markdown->toHtml('`code`'));
    }

    public function test_raw_html_is_stripped(): void
    {
        $rendered = $this->markdown->toHtml('<script>alert(1)</script><img src=x onerror=alert(2)>');

        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('onerror', $rendered);
    }

    public function test_unsafe_link_schemes_are_refused(): void
    {
        foreach (['javascript:alert(1)', 'vbscript:msgbox(1)', 'data:text/html;base64,PHNjcmlwdD4='] as $href) {
            $rendered = $this->markdown->toHtml('[click]('.$href.')');

            $this->assertStringNotContainsString('href="'.$href, $rendered, $href.' must not become a live link.');
        }
    }

    public function test_ordinary_links_still_work(): void
    {
        $rendered = $this->markdown->toHtml('[docs](https://example.test/page)');

        $this->assertStringContainsString('href="https://example.test/page"', $rendered);
    }

    public function test_empty_input_renders_nothing(): void
    {
        $this->assertSame('', $this->markdown->toHtml(null));
        $this->assertSame('', $this->markdown->toHtml('   '));
    }

    public function test_excerpts_are_plain_text_and_truncated(): void
    {
        $excerpt = $this->markdown->toExcerpt("## Title\n\nSome **bold** body text here.", 20);

        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringNotContainsString('**', $excerpt);

        // Str::limit keeps $characters of text and appends an ellipsis.
        $this->assertLessThanOrEqual(23, mb_strlen($excerpt));
        $this->assertStringStartsWith('Title Some bold', $excerpt);
    }
}
