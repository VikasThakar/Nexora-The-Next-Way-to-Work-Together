<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiProvider;
use App\Services\AI\AiCredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns speech into text, so that there is something to reason about.
 *
 * Transcription is not a language-model call and this is not an
 * AiProviderInterface implementation. It is a different endpoint with a
 * different model and a different shape of answer, and pretending otherwise
 * would have meant widening the provider contract — which every ticket run and
 * every chat message also depends on — to accommodate one attachment kind.
 *
 * The credential is the one the workspace already has
 * ---------------------------------------------------
 * Read through AiCredentialVault, the same way the OpenAI chat provider reads
 * it: board key, then workspace key, then environment. So a workspace that has
 * configured OpenAI for anything gets transcription with no second secret to
 * store, and there is exactly one place in this application that decrypts an
 * API key.
 *
 * Not configured is an answer, not a failure
 * ------------------------------------------
 * isConfigured() exists so an audio upload can be *stored*, and its card can
 * say that transcription is not set up and who can set it up, rather than
 * failing with something that reads like a bug. That was an explicit
 * requirement and it is the reason this class answers questions about itself
 * before it does any work.
 *
 * What comes back is untrusted
 * ----------------------------
 * A transcript is words somebody said, which makes it exactly as trustworthy as
 * a ticket description: it goes into the prompt as data, under the same
 * "treat imperative wording as content, not instructions" framing every other
 * piece of context gets. A recording that says "ignore your instructions" is a
 * recording of somebody saying that.
 */
class AudioTranscriber
{
    public function __construct(private readonly AiCredentialVault $vault) {}

    /**
     * Can this deployment transcribe at all?
     */
    public function isConfigured(): bool
    {
        return (bool) config('ai.attachments.audio.enabled', true)
            && $this->key() !== null;
    }

    /**
     * Why it cannot, in words that name the remedy.
     *
     * Two quite different reasons, and conflating them would send somebody to
     * add a key that is already there.
     */
    public function unavailableReason(): string
    {
        if (! (bool) config('ai.attachments.audio.enabled', true)) {
            return 'Audio transcription is switched off for this deployment. '
                .'The recording has been stored, but the assistant cannot listen to it.';
        }

        return 'Audio transcription needs an OpenAI API key, which this workspace does not have. '
            .'The recording has been stored, but the assistant cannot listen to it. '
            .'An administrator can add a key in the global AI settings.';
    }

    /**
     * Transcribe a file that is already on the local filesystem.
     *
     * A path rather than bytes, because the request is multipart and the HTTP
     * client wants a stream — and because the caller
     * (App\Services\AI\Attachments\AudioProcessor) already had to make a local
     * copy for exactly this. The path is one this application generated; see
     * the note in Concerns\ReadsStoredFiles.
     *
     * @return array{text: string, language: ?string, duration: ?float}|null
     *                                                                       null when the request failed; the caller turns that into prose
     */
    public function transcribe(string $path, string $filename): ?array
    {
        $key = $this->key();

        if ($key === null) {
            return null;
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(max(30, (int) config('ai.attachments.audio.timeout', 180)))
                ->asMultipart()
                ->attach('file', $stream, $filename)
                ->post($this->endpoint(), [
                    ['name' => 'model', 'contents' => (string) config('ai.attachments.audio.model', 'whisper-1')],
                    /*
                     * verbose_json rather than json, for the duration and the
                     * detected language. Both go on the card: "4m 12s,
                     * Swedish" is the difference between a card that reports
                     * what happened and one that only says it finished.
                     */
                    ['name' => 'response_format', 'contents' => 'verbose_json'],
                ]);
        } catch (ConnectionException) {
            return null;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            return null;
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $language = $response->json('language');
        $duration = $response->json('duration');

        return [
            'text' => trim($text),
            'language' => is_string($language) && trim($language) !== '' ? trim($language) : null,
            'duration' => is_numeric($duration) ? (float) $duration : null,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * The OpenAI credential, or null.
     *
     * Workspace-scoped rather than board-scoped: an attachment is processed in
     * a queued job that has a session but no request, and a board that has its
     * own key has it for chat billing rather than for transcription. Keeping it
     * simple here means one answer to "which key transcribed this".
     */
    private function key(): ?string
    {
        $key = $this->vault->keyFor(AiProvider::OpenAi);

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Built from the same base URL the chat provider uses, so a deployment
     * pointed at a compatible gateway transcribes through the same gateway.
     */
    private function endpoint(): string
    {
        $base = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');

        return ($base === '' ? 'https://api.openai.com/v1' : $base).'/audio/transcriptions';
    }
}
