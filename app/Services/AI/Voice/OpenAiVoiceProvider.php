<?php

declare(strict_types=1);

namespace App\Services\AI\Voice;

use App\Enums\AiProvider;
use App\Services\AI\AiCredentialVault;
use App\Services\AI\Exceptions\VoiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Speech in and speech out, through OpenAI's audio endpoints.
 *
 * The credential is the one the workspace already has
 * ---------------------------------------------------
 * Read through AiCredentialVault — the same path the OpenAI chat provider and
 * the attachment transcriber use, and the only place in this application that
 * decrypts an API key. So a workspace that has configured OpenAI for anything
 * has voice with no second secret to store, and there is nothing to hardcode.
 *
 * The key is never handed to the browser. Both operations happen server-side:
 * the browser uploads a recording and receives audio back, and at no point does
 * it hold a token, a signed vendor URL or a socket to the vendor. That is the
 * whole reason this is not a realtime client-side integration — a browser
 * talking directly to a speech vendor needs an ephemeral credential, and an
 * ephemeral credential is still a credential in a place we do not control.
 *
 * What is deliberately absent
 * ---------------------------
 * There is no streaming duplex mode, no server-side audio storage and no
 * caching. A recording is transcribed and discarded; an answer is synthesised
 * and streamed to the person who asked. Nothing is written to disk, so there is
 * no audio file to authorize, expire or leak.
 */
class OpenAiVoiceProvider implements VoiceProviderInterface
{
    /**
     * Recording bytes accepted for one turn.
     *
     * A spoken turn is a question, not a lecture: a minute of Opus is a few
     * hundred kilobytes, and this is generous for that while bounding what a
     * crafted request can push through the transcription endpoint.
     */
    private const MAX_RECORDING_BYTES = 10 * 1024 * 1024;

    /** Characters read aloud at most, so one answer cannot cost minutes of audio. */
    private const MAX_SPOKEN_CHARACTERS = 4000;

    public function __construct(private readonly AiCredentialVault $vault) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if (! (bool) config('ai.voice.enabled', true)) {
            return 'Voice conversation is switched off for this deployment. An administrator can '
                .'enable it by setting AI_VOICE_ENABLED=true.';
        }

        if ($this->key() === null) {
            return 'Voice conversation needs an OpenAI API key, which this workspace does not have. '
                .'An administrator can add one in the global AI settings, and voice will work '
                .'immediately afterwards — no other configuration is required.';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function voices(): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('ai.voice.voices', ''))
        )));

        if ($configured === []) {
            return [];
        }

        $voices = [];

        foreach ($configured as $voice) {
            // Labelled by their own name. These are vendor voice ids and this
            // deployment has no editorial description of how each one sounds;
            // inventing one ("warm", "authoritative") would be a claim.
            $voices[$voice] = ucfirst($voice);
        }

        return $voices;
    }

    public function transcribe(string $bytes, string $mimeType, string $filename): string
    {
        $this->assertConfigured();

        if (trim($bytes) === '') {
            throw VoiceException::nothingHeard();
        }

        if (strlen($bytes) > self::MAX_RECORDING_BYTES) {
            throw VoiceException::recordingTooLarge((int) (self::MAX_RECORDING_BYTES / 1024 / 1024));
        }

        try {
            $response = Http::withToken((string) $this->key())
                ->timeout(max(15, (int) config('ai.voice.timeout', 120)))
                ->asMultipart()
                /*
                 * The bytes and a generated name.
                 *
                 * The filename is never one a client supplied: it reaches a
                 * multipart header, and a header built from client input is
                 * how a header injection starts. The caller generates it; see
                 * App\Http\Controllers\AiVoiceController.
                 */
                ->attach('file', $bytes, $filename, ['Content-Type' => $mimeType])
                ->post($this->endpoint('audio/transcriptions'), [
                    ['name' => 'model', 'contents' => (string) config('ai.voice.transcription_model', 'whisper-1')],
                    ['name' => 'response_format', 'contents' => 'json'],
                ]);
        } catch (ConnectionException) {
            throw VoiceException::unreachable();
        } catch (Throwable) {
            throw VoiceException::unreachable();
        }

        if (! $response->successful()) {
            throw VoiceException::rejected($response->status());
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            throw VoiceException::nothingHeard();
        }

        return trim($text);
    }

    public function speak(string $text, ?string $voice = null): AiSpeech
    {
        $this->assertConfigured();

        $text = trim($text);

        if ($text === '') {
            throw VoiceException::nothingToSay();
        }

        if (mb_strlen($text) > self::MAX_SPOKEN_CHARACTERS) {
            /*
             * Truncated rather than refused, with a spoken hand-off.
             *
             * A long answer is usually a table or a list, and the useful
             * behaviour is to read the beginning and say plainly that the rest
             * is on screen — refusing to read a long answer at all would be a
             * worse conversation than an honest partial one.
             */
            $text = mb_substr($text, 0, self::MAX_SPOKEN_CHARACTERS)
                .' … The rest of the answer is on screen.';
        }

        $voice = $this->resolveVoice($voice);

        try {
            $response = Http::withToken((string) $this->key())
                ->timeout(max(15, (int) config('ai.voice.timeout', 120)))
                ->post($this->endpoint('audio/speech'), [
                    'model' => (string) config('ai.voice.speech_model', 'gpt-4o-mini-tts'),
                    'voice' => $voice,
                    'input' => $text,
                    'response_format' => 'mp3',
                ]);
        } catch (ConnectionException) {
            throw VoiceException::unreachable();
        } catch (Throwable) {
            throw VoiceException::unreachable();
        }

        if (! $response->successful()) {
            throw VoiceException::rejected($response->status());
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw VoiceException::unreachable();
        }

        return new AiSpeech(bytes: $bytes, mimeType: 'audio/mpeg', voice: $voice);
    }

    // -----------------------------------------------------------------

    /**
     * A voice the deployment actually offers, or the configured default.
     *
     * Never the value as supplied. It is interpolated into a vendor request
     * body and chosen in a browser, so it is matched against the allow-list
     * rather than trusted — the same rule the model picker follows.
     */
    private function resolveVoice(?string $requested): string
    {
        $available = $this->voices();
        $default = (string) config('ai.voice.default_voice', 'alloy');

        if ($requested === null || $requested === '') {
            return $default;
        }

        return array_key_exists($requested, $available) ? $requested : $default;
    }

    /**
     * @throws VoiceException
     */
    private function assertConfigured(): void
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw VoiceException::notConfigured($reason);
        }
    }

    private function key(): ?string
    {
        $key = $this->vault->keyFor(AiProvider::OpenAi);

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Built from the same base URL the chat provider uses, so a deployment
     * pointed at a compatible gateway speaks through the same gateway.
     */
    private function endpoint(string $path): string
    {
        $base = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');

        return ($base === '' ? 'https://api.openai.com/v1' : $base).'/'.$path;
    }
}
