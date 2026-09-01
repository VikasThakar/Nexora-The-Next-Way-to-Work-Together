<?php

declare(strict_types=1);

namespace App\Support;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\ExtensionInterface;
use Spatie\CommonMarkHighlighter\FencedCodeRenderer;
use Spatie\CommonMarkHighlighter\IndentedCodeRenderer;

/**
 * Syntax highlighting for fenced code blocks, done on the server.
 *
 * Documentation pages are full of commands and snippets, so highlighting is
 * worth having. Doing it in PHP rather than shipping a highlighter to the
 * browser is a deliberate choice:
 *
 *   - no extra JavaScript, and nothing to re-initialise every time Livewire
 *     swaps part of the DOM, which is what makes client-side highlighters
 *     awkward in a Livewire application;
 *   - the highlighted markup is part of the memoised render, so a long page is
 *     highlighted once per unique body rather than once per view;
 *   - the output is still only ever the renderer's own markup. The code text
 *     reaching the highlighter has already been escaped by CommonMark and is
 *     escaped again on the way out, so a code block containing a <script> tag
 *     stays a code block containing the text "<script>".
 *
 * An unrecognised language is not an error: highlight.php raises DomainException
 * and the package falls back to the plain escaped block.
 */
class CodeHighlightExtension implements ExtensionInterface
{
    /**
     * Languages tried when a fence names none.
     *
     * Deliberately short. Autodetection runs every listed grammar over the
     * block and picks the best score, so a long list is slow and — worse —
     * confidently wrong more often.
     *
     * @var array<int, string>
     */
    private const AUTODETECT = [
        'php', 'javascript', 'json', 'bash', 'sql', 'yaml', 'xml', 'css',
    ];

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Priority 10 so these win over CommonMark's own code renderers, which
        // are registered at 0.
        $environment->addRenderer(FencedCode::class, new FencedCodeRenderer(self::AUTODETECT), 10);
        $environment->addRenderer(IndentedCode::class, new IndentedCodeRenderer(self::AUTODETECT), 10);
    }
}
