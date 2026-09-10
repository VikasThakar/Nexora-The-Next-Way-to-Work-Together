<?php

declare(strict_types=1);

namespace App\Services\AI\Knowledge;

use Illuminate\Support\Str;

/**
 * One thing found outside the workspace.
 *
 * Three fields and nothing else, which is the point. A provider's own response
 * carries scores, ranks, thumbnails, favicons and whatever else its vendor
 * finds interesting; this object is the narrow gate everything has to fit
 * through before it can reach a prompt, so what a future provider can smuggle
 * in is bounded by this class rather than by that provider's discipline.
 *
 * Everything is normalised on the way in
 * --------------------------------------
 * The constructor is private and the only way to build one is from(), which
 * trims, caps and validates. That matters more here than in most value objects
 * because the input is a third party's JSON: a title that is four kilobytes of
 * a page's text, or a `url` that is actually `javascript:...`, are both things
 * a search API has been observed to return, and neither should be a problem the
 * tool that calls this has to remember to think about.
 *
 * Only http and https survive as links. Anything else keeps its text and loses
 * its URL, so the result still says what was found and offers nothing
 * clickable that should not be clicked.
 */
final readonly class ExternalKnowledgeResult
{
    /**
     * Long enough to be a real title, short enough that ten of them are not
     * the whole context window.
     */
    public const MAX_TITLE = 200;

    public const MAX_SNIPPET = 600;

    public const MAX_URL = 500;

    private function __construct(
        public string $title,
        public ?string $url,
        public string $snippet,
    ) {}

    /**
     * Build one from a provider's raw values.
     *
     * Returns null when there is nothing usable — no title and no snippet —
     * rather than a result that reads as an empty row in the assistant's
     * answer.
     */
    public static function from(mixed $title, mixed $url, mixed $snippet): ?self
    {
        $title = self::clean($title, self::MAX_TITLE);
        $snippet = self::clean($snippet, self::MAX_SNIPPET);

        if ($title === '' && $snippet === '') {
            return null;
        }

        return new self(
            title: $title === '' ? Str::limit($snippet, 80, '…') : $title,
            url: self::link($url),
            snippet: $snippet,
        );
    }

    /**
     * The result as the model reads it.
     *
     * Plain text, for the reason AiToolOutcome's material is plain text: the
     * model reads it, and a JSON blob invites it to quote structure back at the
     * person.
     */
    public function toText(): string
    {
        $lines = ['- '.$this->title];

        if ($this->url !== null) {
            $lines[] = '  Source: '.$this->url;
        }

        if ($this->snippet !== '') {
            $lines[] = '  '.$this->snippet;
        }

        return implode("\n", $lines);
    }

    /**
     * Whitespace collapsed, control characters gone, length capped.
     *
     * The whitespace collapse is not cosmetic. A snippet scraped from a page
     * arrives full of newlines, and a multi-line value inside a list the model
     * reads as structure is a value that can imitate the surrounding format —
     * which is the plain-text equivalent of the injection the rich-response
     * parser exists to prevent.
     */
    private static function clean(mixed $value, int $limit): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return Str::limit(trim($text), $limit, '…');
    }

    /**
     * A URL, or null.
     *
     * http and https only, and parsed rather than pattern-matched, so a value
     * that merely begins with "https://" while being something else does not
     * pass. Anything rejected simply has no link.
     */
    private static function link(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || mb_strlen($url) > self::MAX_URL) {
            return null;
        }

        $scheme = mb_strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) === null ? null : $url;
    }
}
