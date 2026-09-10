<?php

declare(strict_types=1);

namespace App\Services\AI\Voice;

use App\Services\AI\Exceptions\VoiceException;

/**
 * The voice provider for a deployment that has not configured one.
 *
 * A real implementation rather than a null check scattered through the callers,
 * for the same reason App\Services\AI\CodeGeneration\
 * UnavailableCodeChangeGenerator is one: every surface then has exactly one
 * question to ask — isConfigured() — and exactly one sentence to show, and the
 * refusal cannot be forgotten at a new call site because the container returned
 * something that refuses.
 *
 * The two operations throw rather than returning something empty. An empty
 * transcript would be indistinguishable from a silent recording, and silent
 * audio would be indistinguishable from a working feature nobody can hear —
 * which is precisely the "do not fake a working voice conversation" failure
 * this class exists to prevent.
 */
class UnavailableVoiceProvider implements VoiceProviderInterface
{
    public function __construct(private readonly string $reason) {}

    public function name(): string
    {
        return 'unavailable';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    public function transcribe(string $bytes, string $mimeType, string $filename): string
    {
        throw VoiceException::notConfigured($this->reason);
    }

    public function speak(string $text, ?string $voice = null): AiSpeech
    {
        throw VoiceException::notConfigured($this->reason);
    }

    /**
     * @return array<string, string>
     */
    public function voices(): array
    {
        return [];
    }
}
