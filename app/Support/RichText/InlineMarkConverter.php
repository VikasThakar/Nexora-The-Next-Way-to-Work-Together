<?php

declare(strict_types=1);

namespace App\Support\RichText;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Strikethrough and underline, which the library has no converters for.
 *
 * Without these, `<s>` and `<u>` fall through to DefaultConverter. That keeps
 * the *text* but drops the formatting, so a user who struck a line through and
 * pressed save would watch the strike quietly disappear — the worst kind of
 * data loss, because it looks like it worked.
 *
 * Both delimiters round-trip through App\Support\Markdown: `~~` is GitHub
 * Flavoured Markdown, and `++` is this application's one addition to that
 * flavour (see App\Support\Underline\Underline for why it has to exist).
 */
class InlineMarkConverter implements ConverterInterface
{
    /**
     * The element names that mean each delimiter.
     *
     * `<b>` and `<i>` are absent: the library already converts those.
     *
     * @var array<string, string>
     */
    private const DELIMITERS = [
        's' => '~~',
        'del' => '~~',
        'strike' => '~~',
        'u' => '++',
        'ins' => '++',
    ];

    public function convert(ElementInterface $element): string
    {
        $delimiter = self::DELIMITERS[strtolower($element->getTagName())] ?? '';

        $value = $element->getValue();

        /*
         * Whitespace is moved outside the delimiters rather than wrapped.
         *
         * Emphasis delimiters in CommonMark may not be followed by whitespace
         * when opening, nor preceded by it when closing, so `~~ text ~~` is not
         * emphasis at all — it renders as literal tildes. An editor selection
         * that happens to include a trailing space is completely normal, so
         * without this the round trip would turn a struck phrase into visible
         * punctuation.
         */
        $trimmed = trim($value);

        if ($trimmed === '' || $delimiter === '') {
            return $value;
        }

        $leading = substr($value, 0, strlen($value) - strlen(ltrim($value)));
        $trailing = substr($value, strlen(rtrim($value)));

        return $leading.$delimiter.$trimmed.$delimiter.$trailing;
    }

    /** @return list<string> */
    public function getSupportedTags(): array
    {
        return array_keys(self::DELIMITERS);
    }
}
