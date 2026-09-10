<?php

declare(strict_types=1);

namespace App\Services\AI\Voice;

use App\Services\AI\Exceptions\VoiceException;

/**
 * The whole of the application's dependency on a speech vendor.
 *
 * Two operations, and they are deliberately separate from AiProviderInterface.
 * Speech-to-text and text-to-speech are different endpoints with different
 * models and different shapes of answer; folding them into the chat provider
 * contract would have meant widening an interface that every ticket run and
 * every chat message depends on, to serve one feature.
 *
 * A spoken conversation in this product is therefore three ordinary steps
 * rather than a fourth kind of session: transcribe what was said, ask the
 * existing assistant, speak the answer. The transcript is a normal question and
 * the answer is a normal turn, so voice inherits sessions, attachments,
 * capability modes, the audit trail and the customer boundary without any of
 * them being taught about it. A vendor's realtime duplex socket would have
 * bypassed all of that, which is the reason this is not one.
 *
 * Not configured is an answer, not a failure
 * ------------------------------------------
 * isConfigured() and unavailableReason() exist so the interface can be
 * unavailable *out loud*. A deployment with no key shows the voice control
 * disabled with a sentence naming the remedy; it does not hide the feature, and
 * it does not pretend to listen. That was an explicit requirement, and it is
 * why UnavailableVoiceProvider exists as a real implementation rather than as a
 * null check at every call site.
 */
interface VoiceProviderInterface
{
    /**
     * A short name for diagnostics, e.g. "openai".
     */
    public function name(): string;

    /**
     * Can this deployment hold a spoken conversation right now?
     */
    public function isConfigured(): bool;

    /**
     * Why not, in words that name the remedy — or null when it can.
     */
    public function unavailableReason(): ?string;

    /**
     * Turn recorded speech into text.
     *
     * @param  string  $bytes  the recording, as uploaded
     * @param  string  $mimeType  as declared by the browser; advisory only
     * @param  string  $filename  a generated name, never one from a client
     * @return string the transcript, trimmed
     *
     * @throws VoiceException when unconfigured, unreachable, or the recording
     *                        cannot be transcribed
     */
    public function transcribe(string $bytes, string $mimeType, string $filename): string;

    /**
     * Turn an answer into audio.
     *
     * @param  string|null  $voice  one of voices(), or null for the configured default
     *
     * @throws VoiceException on the same conditions as transcribe()
     */
    public function speak(string $text, ?string $voice = null): AiSpeech;

    /**
     * The voices a person may choose from.
     *
     * @return array<string, string> value => label
     */
    public function voices(): array;
}
