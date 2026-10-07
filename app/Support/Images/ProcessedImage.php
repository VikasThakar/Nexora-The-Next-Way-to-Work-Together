<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * An image this application produced.
 *
 * Returned by ImageTranscoder, never built from an upload. That is the point of
 * the type: every field here describes bytes we encoded ourselves, so the media
 * type is true by construction rather than by assertion. Nothing that arrived
 * from a browser reaches a caller through this object.
 */
readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
    ) {}
}
