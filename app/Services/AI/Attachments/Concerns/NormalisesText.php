<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments\Concerns;

/**
 * Making extracted text fit to send.
 *
 * Shared by every processor that produces text, because all of them have the
 * same three problems and none of them should be solving them differently.
 *
 * Encoding
 * --------
 * Uploaded text is very often not UTF-8. A log from a Windows machine is
 * Windows-1252; an export from an older tool is UTF-16. Those bytes in a
 * JSON-encoded API request produce mojibake at best and a serialisation failure
 * at worst, halfway through a large payload, with the file's name nowhere in
 * the error. Converting once, here, is cheaper than diagnosing that twice.
 *
 * Control characters
 * ------------------
 * Stripped, except tab and newline. A NUL or an escape sequence in the middle
 * of a prompt is at best noise the model has to skip and at worst a terminal
 * escape somebody pasted in — and uploaded content is untrusted input by
 * definition, so the narrow allow-list is the right shape.
 *
 * Truncation
 * ----------
 * By characters, at a configured per-file budget, on a line boundary where
 * there is one nearby. The *fact* of truncation is returned rather than
 * swallowed, and every caller is expected to carry it into the prompt: a model
 * handed the first forty thousand characters of a contract and not told will
 * summarise it as though it read the end. That is the single most important
 * line in this trait.
 */
trait NormalisesText
{
    /**
     * Bytes from a file, as text that can go in a prompt.
     */
    protected function normalise(string $raw): string
    {
        $text = $this->toUtf8($raw);

        // CRLF and lone CR to LF, so a line break costs one character.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Everything below space except tab and newline.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Trailing whitespace per line, and a runaway stack of blank lines.
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);
        $text = (string) preg_replace('/\n{4,}/', "\n\n\n", $text);

        return trim($text);
    }

    /**
     * Convert to UTF-8.
     *
     * The order here is load-bearing, and getting it wrong is not obvious: a
     * conversion *from* UTF-16 almost never fails, because any even number of
     * bytes is a valid sequence of UTF-16 code units. So trying UTF-16 as a
     * guess turns a Windows-1252 log file into valid, well-formed nonsense —
     * CJK characters where the words used to be — and every later check passes.
     *
     * UTF-16 is therefore only ever *detected*, never guessed:
     *
     *   1. text that is already valid UTF-8 is returned untouched. Most of it;
     *   2. a byte-order mark is proof, so it is honoured;
     *   3. so is the shape of UTF-16 without a mark — Latin text encoded that
     *      way is half NUL bytes, which no single-byte encoding produces;
     *   4. everything else is Windows-1252, which is what a Windows text editor
     *      writes and a superset of ISO-8859-1. It maps every byte to some
     *      character, so it cannot fail, which is precisely why it goes last.
     */
    private function toUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $utf16 = $this->utf16Variant($raw);

        if ($utf16 !== null) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $utf16);

            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }

        /*
         * Nothing claimed it. Drop the invalid sequences rather than refusing
         * the file: some readable text is more useful than none, and the
         * alternative is an upload that fails for a reason nobody can act on.
         */
        return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }

    /**
     * Is this UTF-16, and which way round?
     *
     * Null unless there is real evidence. A byte-order mark is conclusive.
     * Without one, the test is the density of NUL bytes: Latin text in UTF-16
     * is one NUL per character, and no single-byte encoding of readable text
     * contains any at all — NormalisesText strips them from the text it keeps
     * precisely because they are not something a text file has.
     *
     * Which byte of each pair is the NUL says which endianness it is.
     */
    private function utf16Variant(string $raw): ?string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return 'UTF-16LE';
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        $length = strlen($raw);

        if ($length < 4 || $length % 2 !== 0) {
            return null;
        }

        $sample = min($length, 2048);
        $evenNuls = 0;
        $oddNuls = 0;

        for ($index = 0; $index < $sample; $index += 2) {
            $evenNuls += $raw[$index] === "\0" ? 1 : 0;
            $oddNuls += $raw[$index + 1] === "\0" ? 1 : 0;
        }

        $pairs = (int) ($sample / 2);

        // A clear majority, so a stray NUL in an otherwise single-byte file
        // does not tip the decision.
        if ($oddNuls > $pairs * 0.6 && $evenNuls < $pairs * 0.2) {
            return 'UTF-16LE';
        }

        if ($evenNuls > $pairs * 0.6 && $oddNuls < $pairs * 0.2) {
            return 'UTF-16BE';
        }

        return null;
    }

    /**
     * Cut text to the per-file budget, and say whether it was cut.
     *
     * @return array{0: string, 1: bool} the text, and whether anything was lost
     */
    protected function truncate(string $text, ?int $limit = null): array
    {
        $limit = $limit ?? max(1000, (int) config('ai.attachments.context.per_file_characters', 40000));

        if (mb_strlen($text) <= $limit) {
            return [$text, false];
        }

        $cut = mb_substr($text, 0, $limit);

        /*
         * Back up to the last line break in the final tenth, so a document is
         * not cut mid-sentence when a clean break is close by. Only the final
         * tenth: searching the whole string would happily discard most of a
         * one-line file.
         */
        $lastBreak = mb_strrpos($cut, "\n");

        if ($lastBreak !== false && $lastBreak > (int) ($limit * 0.9)) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        return [rtrim($cut), true];
    }
}
