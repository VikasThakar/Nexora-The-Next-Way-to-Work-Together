<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of thing an uploaded file is, as far as the assistant is concerned.
 *
 * This is the extension point the whole attachment feature turns on. A file
 * type is not handled by a branch somewhere in the chat service; it is
 * classified into one of these kinds, and each kind names exactly one
 * processor (see App\Services\AI\Attachments\AiAttachmentPipeline). Supporting
 * a new format is therefore a new case here plus a new processor, and nothing
 * upstream changes.
 *
 * The distinction between Text, Markdown and Document is not cosmetic even
 * though all three end up as text: they differ in how the text is *derived*
 * (read, read-and-keep-structure, unzip-and-strip-XML) and in how much of the
 * original survives, which is what the digest has to be honest about.
 *
 * Unsupported is a real case rather than an error. A file whose type is
 * allowed by upload validation but which no processor claims must still get a
 * row, a card and a message — "we stored it but cannot read it" is a true and
 * useful thing to tell somebody, where a silent failure is not.
 */
enum AiAttachmentKind: string
{
    case Text = 'text';

    case Markdown = 'markdown';

    case Csv = 'csv';

    case Pdf = 'pdf';

    case Image = 'image';

    case Audio = 'audio';

    case Document = 'document';

    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Markdown => 'Markdown',
            self::Csv => 'Spreadsheet',
            self::Pdf => 'PDF',
            self::Image => 'Image',
            self::Audio => 'Audio',
            self::Document => 'Document',
            self::Unsupported => 'Unreadable',
        };
    }

    /**
     * The glyph on the attachment card.
     *
     * Emoji rather than an icon set, because that is what the rest of this
     * product already does in AI surfaces and adding an icon library for eight
     * glyphs would not earn its weight.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Text => '📃',
            self::Markdown => '📝',
            self::Csv => '📊',
            self::Pdf => '📄',
            self::Image => '🖼️',
            self::Audio => '🎧',
            self::Document => '📑',
            self::Unsupported => '📁',
        };
    }

    /**
     * Does processing this kind produce text for the prompt?
     *
     * False for an image, which is sent to the model as a picture instead, and
     * false for an unreadable file, which is sent as nothing at all.
     */
    public function producesText(): bool
    {
        return match ($this) {
            self::Image, self::Unsupported => false,
            default => true,
        };
    }

    /**
     * Is this looked at rather than read?
     *
     * An image reaches the model as a content block, which only a model with
     * vision can accept — see App\Support\AiModel::supportsVision(). Nothing
     * else in the product needs to ask, which is why the question is a method
     * on the kind rather than a check on the MIME type at each call site.
     */
    public function isVisual(): bool
    {
        return $this === self::Image;
    }

    /**
     * Does this kind need a service beyond the language model itself?
     *
     * Audio does: it has to be transcribed before there is anything to read.
     * That is why an audio upload can be stored and still honestly report
     * "transcription is not configured" rather than failing — see
     * App\Services\AI\Attachments\AudioProcessor.
     */
    public function needsTranscription(): bool
    {
        return $this === self::Audio;
    }
}
