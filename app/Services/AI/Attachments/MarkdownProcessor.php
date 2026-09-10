<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;

/**
 * Markdown: .md, .markdown.
 *
 * Sent to the model as Markdown, not as rendered HTML and not as flattened
 * prose. That is the whole decision, and it is worth stating because the
 * product has a perfectly good Markdown renderer sitting right there
 * (App\Support\Markdown) which it would be natural to reach for.
 *
 * Rendering would be wrong twice over. A language model reads Markdown better
 * than it reads HTML — the syntax is shorter, so it costs fewer tokens, and the
 * structure is more legible than a tag soup. And this application's renderer
 * exists to make *untrusted* text safe to put on a page; this text is not going
 * on a page, so the sanitisation buys nothing and the conversion loses the
 * heading levels a model uses to navigate a document.
 *
 * What this processor adds over plain text is the outline: the headings, in
 * order, with their levels. That goes into the digest, so a follow-up question
 * about a document already discussed earlier in the conversation can be
 * answered — or honestly declined — without re-sending the whole file. It is
 * also what lets an answer say which section something came from.
 */
class MarkdownProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    /**
     * How many headings the outline keeps.
     *
     * Enough to describe a long specification, capped so that a generated file
     * with ten thousand headings cannot turn the digest into the document.
     */
    private const MAX_HEADINGS = 60;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Markdown;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        $contents = $this->contents($attachment);

        if ($contents === null) {
            return AiAttachmentExtraction::failed(
                'The stored file could not be read. Try attaching it again.'
            );
        }

        $text = $this->normalise($contents);

        if (trim($text) === '') {
            return AiAttachmentExtraction::ready(summary: 'Empty file');
        }

        $headings = $this->headings($text);

        [$text, $truncated] = $this->truncate($text);

        $words = str_word_count(strip_tags($text));

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: [
                'headings' => $headings,
                'words' => $words,
            ],
            summary: $this->summarise($headings, $words),
            truncated: $truncated,
        );
    }

    /**
     * The ATX headings, in document order.
     *
     * ATX only — `## Heading` — and not the underlined Setext form. Setext
     * headings are rare in written-by-hand Markdown and impossible to
     * distinguish from a table row or a horizontal rule without parsing the
     * document properly, which is not worth doing for an outline.
     *
     * @return list<array{level: int, text: string}>
     */
    private function headings(string $text): array
    {
        if (preg_match_all('/^(#{1,6})[ \t]+(.+?)[ \t]*#*$/m', $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $headings = [];

        foreach ($matches as $match) {
            $title = trim($match[2]);

            if ($title === '') {
                continue;
            }

            $headings[] = [
                'level' => strlen($match[1]),
                'text' => mb_substr($title, 0, 160),
            ];

            if (count($headings) >= self::MAX_HEADINGS) {
                break;
            }
        }

        return $headings;
    }

    /**
     * @param  list<array{level: int, text: string}>  $headings
     */
    private function summarise(array $headings, int $words): string
    {
        $parts = [number_format($words).' '.($words === 1 ? 'word' : 'words')];

        if ($headings !== []) {
            $parts[] = count($headings).' '.(count($headings) === 1 ? 'heading' : 'headings');
        }

        return implode(', ', $parts);
    }
}
