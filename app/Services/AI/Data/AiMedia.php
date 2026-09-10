<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * A picture attached to a message.
 *
 * The one non-text thing this application will send to a provider, and it is
 * kept as narrow as that sentence suggests. It carries a media type and
 * base64-encoded bytes — the intersection every current vision API agrees on —
 * so an adapter turns it into whichever content block its vendor wants without
 * anything upstream knowing what that block looks like.
 *
 * Why bytes and not a URL
 * -----------------------
 * Because the alternative would be handing a provider a URL to a private file.
 * Making that work would mean either a publicly reachable link — which is the
 * thing the attachment system exists to avoid — or a signed one, which is a
 * public link with an expiry. Sending the bytes on the request means the file
 * is never addressable from outside this application at all.
 *
 * The bytes are re-encoded, not forwarded
 * ---------------------------------------
 * What arrives here has been decoded and re-written by GD in
 * App\Services\AI\Attachments\ImageProcessor, so it is an image this
 * application produced rather than one an uploader produced. That matters: it
 * strips whatever a crafted file had appended to it, drops EXIF — which can
 * carry the photographer's location — and guarantees the declared type matches
 * the actual bytes.
 *
 * There is deliberately no `document` kind. Anthropic can take a PDF natively,
 * but this application extracts PDF text instead, which is provider-neutral,
 * cheaper, and lets an answer cite a page. Adding a second path that sends the
 * same document a second way would double the tokens and halve the clarity.
 */
final readonly class AiMedia
{
    public function __construct(
        public string $mediaType,
        public string $base64,
        public ?string $filename = null,
    ) {}

    /**
     * The `data:` URI form, which is what OpenAI's chat API takes.
     */
    public function dataUri(): string
    {
        return 'data:'.$this->mediaType.';base64,'.$this->base64;
    }

    /**
     * Roughly how many bytes this will add to the request.
     *
     * Base64 is four characters per three bytes. Used for the size guard, not
     * for anything reported as usage.
     */
    public function approximateBytes(): int
    {
        return (int) (strlen($this->base64) * 3 / 4);
    }
}
