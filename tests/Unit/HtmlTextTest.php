<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlText;
use PHPUnit\Framework\TestCase;

/**
 * The walker both prose post-processors are built on.
 *
 * Its job is narrow and its failure mode is severe: if a callback ever sees a
 * character that belongs to a tag, a rewrite can break the markup or, worse,
 * inject an attribute. Everything below is a way of getting it to.
 */
class HtmlTextTest extends TestCase
{
    private function shout(string $html): string
    {
        return HtmlText::mapText($html, fn (string $text): string => str_replace('cat', 'DOG', $text));
    }

    public function test_it_rewrites_prose(): void
    {
        $this->assertSame('<p>a DOG</p>', $this->shout('<p>a cat</p>'));
    }

    public function test_it_never_touches_the_inside_of_a_tag(): void
    {
        // "cat" appears in an attribute value and in a class name. Neither is
        // prose, so neither may be rewritten — while the text between the tags
        // still is. A span rather than an anchor, because anchors are skipped
        // wholesale (see below) and would not prove the point.
        $this->assertSame(
            '<span data-x="/cat" class="cat">a DOG</span>',
            $this->shout('<span data-x="/cat" class="cat">a cat</span>')
        );
    }

    public function test_it_skips_code_spans_and_blocks(): void
    {
        $this->assertSame('<p><code>cat</code> and DOG</p>', $this->shout('<p><code>cat</code> and cat</p>'));
        $this->assertSame('<pre><code>cat</code></pre>', $this->shout('<pre><code>cat</code></pre>'));
    }

    public function test_it_skips_the_text_of_an_existing_link(): void
    {
        // Rewriting here would nest anchors.
        $this->assertSame(
            '<p><a href="#">cat</a> DOG</p>',
            $this->shout('<p><a href="#">cat</a> cat</p>')
        );
    }

    public function test_nesting_is_tracked_so_the_skip_ends_where_it_should(): void
    {
        $this->assertSame(
            '<p><code><em>cat</em></code> DOG</p>',
            $this->shout('<p><code><em>cat</em></code> cat</p>')
        );
    }

    public function test_an_empty_document_is_returned_unchanged(): void
    {
        $this->assertSame('', HtmlText::mapText('', fn (string $t): string => 'x'));
    }

    public function test_plain_text_with_no_tags_is_still_processed(): void
    {
        $this->assertSame('DOG', $this->shout('cat'));
    }

    public function test_an_unbalanced_closing_tag_does_not_unbalance_the_walker(): void
    {
        // A stray </code> must not leave the counter negative and start
        // skipping everything that follows.
        $this->assertSame('</code>DOG', $this->shout('</code>cat'));
    }
}
