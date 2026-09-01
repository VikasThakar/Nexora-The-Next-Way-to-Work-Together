<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders user-written Markdown to HTML.
 *
 * Ticket descriptions are written by customers as well as staff, so the output
 * is untrusted by definition. Two settings do the security work:
 *
 *   html_input => 'strip'   removes raw HTML entirely, so a description cannot
 *                           smuggle a <script> or an onerror attribute through.
 *   allow_unsafe_links      false blocks javascript:, data: and vbscript: hrefs.
 *
 * Because of those, the result is safe to echo unescaped — and it must be
 * echoed unescaped, which is exactly why the escaping decision lives here in
 * one reviewed place rather than at each {!! !!} in a Blade file.
 */
class Markdown
{
    /** @var array<string, string> */
    private array $memo = [];

    public function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        $key = md5($markdown);

        return $this->memo[$key] ??= Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 25,
        ], [
            // Code blocks are highlighted here rather than in the browser; see
            // App\Support\CodeHighlightExtension for why.
            new CodeHighlightExtension,
        ]);
    }

    /**
     * Plain-text preview for cards and search results.
     */
    public function toExcerpt(?string $markdown, int $characters = 160): string
    {
        $text = trim(strip_tags($this->toHtml($markdown)));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return Str::limit($text, $characters);
    }
}
