<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Support\Markdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Code blocks in documentation and comments are highlighted on the server.
 *
 * The last test is the one that matters: highlighting takes the escaped code
 * back apart and re-emits it as markup, which is exactly the shape of operation
 * that reintroduces an injection if the library gets it wrong.
 */
class SyntaxHighlightingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fenced_block_with_a_named_language_is_highlighted(): void
    {
        $html = app(Markdown::class)->toHtml("```php\n<?php \$x = 'hello';\n```");

        $this->assertStringContainsString('class="language-php hljs php"', $html);
        $this->assertStringContainsString('hljs-string', $html);
    }

    public function test_an_unknown_language_falls_back_to_a_plain_block(): void
    {
        $html = app(Markdown::class)->toHtml("```notalanguage\nsome text\n```");

        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('some text', $html);
    }

    public function test_a_fence_with_no_language_still_renders(): void
    {
        $html = app(Markdown::class)->toHtml("```\nplain text\n```");

        $this->assertStringContainsString('plain text', $html);
    }

    public function test_highlighted_documentation_reaches_the_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $page = $this->docPageOn($board, $team, [
            'title' => 'Commands',
            'body_md' => "Run this:\n\n```bash\nphp artisan migrate\n```\n",
        ]);

        $this->actingAs($team)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('hljs', escape: false)
            ->assertSee('php artisan migrate', escape: false);
    }

    /**
     * Highlighting decodes the escaped source and re-emits it wrapped in spans.
     * If that round trip ever stopped escaping, a code block would become an
     * injection point — and code blocks are exactly where somebody pastes a
     * script tag legitimately.
     */
    public function test_markup_inside_a_code_block_stays_inert(): void
    {
        $markdown = app(Markdown::class);

        foreach ([
            "```html\n<script>alert(1)</script>\n```",
            "```\n<script>alert(1)</script>\n```",
            "```php\necho '<img src=x onerror=alert(1)>';\n```",
        ] as $source) {
            $html = $markdown->toHtml($source);

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('&lt;', $html);
        }
    }

    public function test_an_indented_code_block_is_also_handled(): void
    {
        $html = app(Markdown::class)->toHtml("Some text\n\n    <script>alert(1)</script>\n");

        // The highlighter wraps the tag name in its own span, so the escaped
        // form is not one contiguous string. What matters is that no live tag
        // survives and that the text reads back as it was written.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;', $html);
        $this->assertStringContainsString('script', html_entity_decode(strip_tags($html)));
        $this->assertStringContainsString('alert(1)', $html);
    }
}
