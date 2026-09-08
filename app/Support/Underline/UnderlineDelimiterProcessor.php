<?php

declare(strict_types=1);

namespace App\Support\Underline;

use League\CommonMark\Delimiter\DelimiterInterface;
use League\CommonMark\Delimiter\Processor\CacheableDelimiterProcessorInterface;
use League\CommonMark\Node\Inline\AbstractStringContainer;

/**
 * Turns a matched pair of `++` runs into an Underline node.
 *
 * Written as a delimiter processor rather than as a regular expression, and
 * that is the whole point of the class. CommonMark's delimiter stack applies
 * the flanking rules — a run may only open if it is not followed by
 * whitespace, and may only close if it is not preceded by whitespace — which
 * is what keeps ordinary prose safe:
 *
 *   "C++ and C++"        both runs are followed by whitespace or end of line,
 *                        so neither can open. Renders literally, unchanged.
 *   "i++ then j++"       same. This matters: a naive /\+\+(.+?)\+\+/ would
 *                        underline " and C" and " then j", silently corrupting
 *                        every description in the database that mentions C++.
 *   "++really++"         opens and closes. Underlined.
 *
 * A run longer than two characters is refused outright, so `+++x+++` is left
 * alone instead of guessing which two of the three were meant.
 *
 * Modelled on League\CommonMark's own StrikethroughDelimiterProcessor, whose
 * `~~` has exactly the same shape of problem.
 */
final class UnderlineDelimiterProcessor implements CacheableDelimiterProcessorInterface
{
    private const DELIMITER = '+';

    private const LENGTH = 2;

    public function getOpeningCharacter(): string
    {
        return self::DELIMITER;
    }

    public function getClosingCharacter(): string
    {
        return self::DELIMITER;
    }

    /**
     * Two, so a single `+` is never a delimiter.
     *
     * A lone plus is ordinary punctuation — "1 + 1", "C+", a diff marker — and
     * treating it as emphasis would be far more disruptive than the feature is
     * worth.
     */
    public function getMinLength(): int
    {
        return self::LENGTH;
    }

    public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int
    {
        // Exactly two on both sides. Anything else is punctuation somebody
        // wrote on purpose, so it is left as they wrote it.
        if ($opener->getLength() !== self::LENGTH || $closer->getLength() !== self::LENGTH) {
            return 0;
        }

        return self::LENGTH;
    }

    public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void
    {
        $underline = new Underline(str_repeat(self::DELIMITER, $delimiterUse));

        $node = $opener->next();

        while ($node !== null && $node !== $closer) {
            $next = $node->next();
            $underline->appendChild($node);
            $node = $next;
        }

        $opener->insertAfter($underline);
    }

    /**
     * Every closer that is not exactly two characters long behaves identically
     * (getDelimiterUse returns 0 for all of them), so they share one bucket and
     * the delimiter stack's lower-bound cache stays bounded.
     */
    public function getCacheKey(DelimiterInterface $closer): string
    {
        return self::DELIMITER.($closer->getLength() === self::LENGTH ? '2' : 'x');
    }
}
