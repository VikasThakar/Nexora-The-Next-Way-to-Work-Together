<?php

declare(strict_types=1);

namespace App\Services\AI\Voice;

/**
 * Spoken audio, on its way to a browser.
 *
 * Bytes rather than a URL, and never written to disk. A generated answer read
 * aloud is as private as the answer, and the cheapest way to keep it that way
 * is for there to be no file: the controller streams these bytes to the person
 * who asked, in the response to their own authorized request, and nothing is
 * left behind for a signed URL to leak or a retention policy to forget.
 *
 * That is also why there is no cache. The same question asked twice costs the
 * synthesis twice, which is a real cost and a deliberate one.
 */
final readonly class AiSpeech
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $voice,
    ) {}

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * The extension a browser expects for this audio, for a filename.
     *
     * Derived rather than stored, so a provider that switches format cannot
     * leave the two disagreeing.
     */
    public function extension(): string
    {
        return match ($this->mimeType) {
            'audio/mpeg' => 'mp3',
            'audio/opus', 'audio/ogg' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            default => 'mp3',
        };
    }
}
