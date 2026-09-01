<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Walks the text between tags of an already-rendered HTML fragment.
 *
 * Both post-processors that run over rendered Markdown — @mentions and ticket
 * references — need the same thing: rewrite words in prose, and never touch
 * anything inside a tag, a code span, a code block or an existing link.
 *
 * Doing that with one regex over the whole document is where this kind of
 * feature usually goes wrong: `<a href="...AQD-1...">` gets rewritten and the
 * markup breaks. Splitting on tags first makes the unsafe case unreachable,
 * because a callback never sees a character that is part of a tag.
 *
 * The input is trusted to be the output of App\Support\Markdown, which strips
 * raw HTML, so the tag structure here is the renderer's own.
 */
class HtmlText
{
    /**
     * Elements whose text content is left exactly as written.
     *
     * `code`/`pre` because a ticket key in a code sample is a code sample, and
     * `a` because rewriting the text of an existing link would nest anchors.
     */
    private const OPAQUE = ['code', 'pre', 'a'];

    /**
     * Apply a callback to every text run outside a tag and outside an opaque
     * element.
     *
     * The callback receives HTML-escaped text and must return valid HTML.
     *
     * @param  callable(string): string  $callback
     */
    public static function mapText(string $html, callable $callback): string
    {
        if ($html === '') {
            return '';
        }

        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $opaqueDepth = 0;
        $out = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                $out .= $part;

                if (preg_match('/^<\s*\/\s*([a-z0-9]+)/i', $part, $m) === 1) {
                    if (in_array(strtolower($m[1]), self::OPAQUE, true)) {
                        $opaqueDepth = max(0, $opaqueDepth - 1);
                    }
                } elseif (preg_match('/^<\s*([a-z0-9]+)/i', $part, $m) === 1) {
                    // Self-closing tags never wrap text, so they cannot open a
                    // region; none of the opaque elements is void anyway.
                    if (in_array(strtolower($m[1]), self::OPAQUE, true) && ! str_ends_with(rtrim($part), '/>')) {
                        $opaqueDepth++;
                    }
                }

                continue;
            }

            $out .= $opaqueDepth > 0 ? $part : $callback($part);
        }

        return $out;
    }
}
