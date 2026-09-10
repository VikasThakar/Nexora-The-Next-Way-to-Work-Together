<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Services\AI\Exceptions\VoiceException;
use App\Services\AI\Voice\SpokenAnswer;
use App\Services\AI\Voice\VoiceProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two halves of a spoken conversation.
 *
 * `listen` takes a recording and returns the words in it. `speak` takes an
 * answer already in the transcript and returns it as audio. Between the two the
 * ordinary assistant runs, unchanged and unaware — which is what makes a spoken
 * question inherit sessions, attachments, capability modes, the audit trail and
 * the customer boundary rather than needing its own copy of each.
 *
 * Why a controller and not Livewire
 * ---------------------------------
 * Audio is bytes in and bytes out, and neither belongs in a component's
 * property bag. Livewire's file upload would also put the recording on the
 * attachment disk on its way past, which for a voice turn is a file nobody
 * asked to keep — see App\Services\AI\Voice\AiSpeech for why nothing here
 * touches storage.
 *
 * Authorization
 * -------------
 * `listen` is allowed to anybody the assistant panel is allowed to: transcribing
 * reads nothing from the workspace, so the only thing to establish is that this
 * person is entitled to be talking to the assistant at all. A customer passes,
 * which is deliberate — their assistant is read-only, not silent.
 *
 * `speak` is stricter, because it reads a stored answer: the turn is resolved
 * through `visibleTo` and `ownedBy`, so it cannot reach a colleague's or an
 * administrator's conversation, and only an assistant turn can be spoken. A
 * miss is a 404, never a 403.
 *
 * Both are rate limited by the route definition. Speech synthesis costs money
 * per call and is the one AI surface in this product a browser can trigger in a
 * loop without a person typing anything.
 */
class AiVoiceController extends Controller
{
    /**
     * Turn a recording into text.
     */
    public function listen(Request $request, VoiceProviderInterface $voice): JsonResponse
    {
        $this->assertMayTalk();

        if (! $voice->isConfigured()) {
            /*
             * 503, and the reason.
             *
             * Not a silent empty transcript, and not a 500: this is a
             * deployment that has not been given a key, which is a
             * configuration state with a remedy, and the panel shows the
             * sentence verbatim.
             */
            return response()->json([
                'message' => $voice->unavailableReason(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/aac,video/webm,video/mp4'],
        ], [], ['audio' => 'recording']);

        $file = $validated['audio'];

        try {
            $text = $voice->transcribe(
                bytes: (string) file_get_contents($file->getRealPath()),
                // The detected type, never the browser's claim: it reaches a
                // multipart header on the way to the vendor.
                mimeType: (string) ($file->getMimeType() ?: 'audio/webm'),
                /*
                 * A generated name.
                 *
                 * The vendor requires a filename with a usable extension, and
                 * it lands in a header. A name from a client is how a header
                 * injection starts, so this one is built here from a ULID and
                 * an extension derived from the detected type.
                 */
                filename: Str::ulid().'.'.$this->extensionFor((string) $file->getMimeType()),
            );
        } catch (VoiceException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * Read one stored answer aloud.
     */
    public function speak(int $message, Request $request, VoiceProviderInterface $voice): Response
    {
        $this->assertMayTalk();

        $user = auth()->user();

        $stored = AiChatMessage::query()
            ->visibleTo($user)
            ->ownedBy($user)
            ->whereNotNull('ai_chat_messages.ai_session_id')
            ->whereKey($message)
            ->first();

        abort_unless($stored instanceof AiChatMessage, 404);

        // Only an answer is spoken. Reading somebody their own question back
        // is not a feature, and allowing it would double the surface for no
        // reason.
        abort_unless($stored->role->isAssistant(), 404);

        if (! $voice->isConfigured()) {
            return response()->json([
                'message' => $voice->unavailableReason(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Tables and charts become a sentence saying they are on screen. See
        // SpokenAnswer.
        $text = SpokenAnswer::from((string) $stored->content);

        try {
            $speech = $voice->speak($text, $this->requestedVoice($request, $voice));
        } catch (VoiceException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response($speech->bytes, Response::HTTP_OK, [
            'Content-Type' => $speech->mimeType,
            'Content-Length' => (string) $speech->size(),
            /*
             * Never cached, anywhere.
             *
             * The bytes are a private answer read aloud. A shared cache holding
             * them would be a copy of somebody's conversation outside every
             * check above.
             */
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="answer.'.$speech->extension().'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * May this person be talking to the assistant at all?
     *
     * The same question the layout asks before it mounts the panel, so the two
     * cannot disagree — and a 404 rather than a 403, because somebody with no
     * assistant should not learn that these endpoints exist.
     */
    private function assertMayTalk(): void
    {
        abort_unless(Assistant::eligibleFor(auth()->user()), 404);
    }

    /**
     * The voice asked for, if it is one this deployment offers.
     *
     * Matched against the allow-list by the provider itself; this only reads
     * the parameter. A value that is not offered becomes the default rather
     * than an error, because a stale choice in somebody's browser should not
     * break speech.
     */
    private function requestedVoice(Request $request, VoiceProviderInterface $voice): ?string
    {
        $requested = $request->query('voice');

        if (! is_string($requested) || $requested === '') {
            return null;
        }

        return array_key_exists($requested, $voice->voices()) ? $requested : null;
    }

    /**
     * A file extension for a detected audio type.
     *
     * The vendor decides how to decode from the container, and it needs the
     * extension to do it. Anything unrecognised becomes .webm, which is what
     * every browser's MediaRecorder produces by default.
     */
    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'video/mp4' => 'mp4',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/aac' => 'aac',
            default => 'webm',
        };
    }
}
