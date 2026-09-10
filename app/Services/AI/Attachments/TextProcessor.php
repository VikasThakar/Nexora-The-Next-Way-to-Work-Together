<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;

/**
 * Plain text: .txt, .log, .json, .yml, .yaml.
 *
 * The simplest processor, and the one that establishes what "read the file"
 * means for every other one. Three things happen, and all three are about
 * being honest to the model rather than about parsing.
 *
 * Encoding is normalised. A log file from a Windows machine is very often
 * Windows-1252 or UTF-16, and handing those bytes to a JSON-encoded API request
 * produces either mojibake or an encoding error halfway through the payload.
 * The text is converted to UTF-8 and invalid sequences are dropped.
 *
 * Line endings are normalised, because a document whose every line ends CRLF
 * spends a token per line on a carriage return that carries no meaning.
 *
 * Structure is preserved, not stripped. This is a *text* processor: line breaks,
 * indentation and blank lines are the structure of a log or a YAML file, and
 * flattening them is how a stack trace becomes unreadable. Only trailing
 * whitespace goes.
 */
class TextProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Text;
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
            return AiAttachmentExtraction::ready(
                summary: 'Empty file',
            );
        }

        [$text, $truncated] = $this->truncate($text);

        $lines = substr_count($text, "\n") + 1;

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: ['lines' => $lines],
            summary: number_format($lines).' '.($lines === 1 ? 'line' : 'lines'),
            truncated: $truncated,
        );
    }
}
