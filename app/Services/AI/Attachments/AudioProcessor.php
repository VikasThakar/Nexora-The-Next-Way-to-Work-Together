<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;

/**
 * Recordings: .mp3, .m4a, .wav, .ogg, .webm, .mp4.
 *
 * The architecture is here and the capability is optional, which was the
 * requirement and is also the honest position: transcription needs a service
 * this deployment may not have, and pretending otherwise would mean either
 * failing an upload for a reason the person cannot act on, or accepting it and
 * quietly doing nothing.
 *
 * So there are three outcomes and each one tells the truth:
 *
 *   no credential   Unsupported, with a card that says transcription needs an
 *                   OpenAI key and that an administrator can add one. The file
 *                   is still stored, so it is there when the key arrives.
 *   transcribed     Ready, with the words. The transcript is the extracted
 *                   text, so every downstream feature — the digest, the
 *                   budgets, the token estimate, the prompt section — works
 *                   without knowing it came from speech.
 *   failed          Failed, with prose. A recording the service could not
 *                   process is a recording, not a bug.
 *
 * The transcript is data
 * ----------------------
 * It reaches the prompt inside the attachment section, under the same framing
 * as every other piece of context: reference material, and imperative wording
 * inside it is something a person said, never an instruction. A recording of
 * somebody reading out a prompt injection is a recording of that.
 */
class AudioProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    public function __construct(private readonly AudioTranscriber $transcriber) {}

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Audio;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        /*
         * Asked before the file is copied anywhere.
         *
         * A deployment with no transcription service should not be writing
         * somebody's recording to a temporary path to discover that.
         */
        if (! $this->transcriber->isConfigured()) {
            return AiAttachmentExtraction::unsupported($this->transcriber->unavailableReason());
        }

        $result = $this->withLocalCopy(
            $attachment,
            fn (string $path): ?array => $this->transcriber->transcribe($path, $attachment->filename ?? 'audio')
        );

        if ($result === null) {
            return AiAttachmentExtraction::failed(
                'That recording could not be transcribed. The file may be longer or larger than '
                .'the transcription service accepts, or in a codec it cannot read.'
            );
        }

        $text = $this->normalise($result['text']);

        if (trim($text) === '') {
            return AiAttachmentExtraction::ready(
                summary: 'No speech detected',
                structured: ['duration' => $result['duration']],
            );
        }

        [$text, $truncated] = $this->truncate($text);

        $words = str_word_count($text);

        return AiAttachmentExtraction::ready(
            // Labelled, so an answer can say it is quoting a recording rather
            // than a document. A model told only "here is some text" will cite
            // spoken filler as though it were written down.
            text: 'TRANSCRIPT of the recording "'.($attachment->filename ?? 'audio')."\":\n\n".$text,
            structured: [
                'words' => $words,
                'language' => $result['language'],
                'duration' => $result['duration'],
            ],
            summary: $this->summarise($result['duration'], $result['language'], $words),
            truncated: $truncated,
        );
    }

    // -----------------------------------------------------------------

    private function summarise(?float $duration, ?string $language, int $words): string
    {
        $parts = [];

        if ($duration !== null && $duration > 0) {
            $parts[] = $this->clock($duration);
        }

        if ($language !== null) {
            $parts[] = ucfirst($language);
        }

        $parts[] = number_format($words).' '.($words === 1 ? 'word' : 'words');

        return implode(', ', $parts);
    }

    /**
     * Seconds as a person reads them: "4m 12s", "1h 06m".
     */
    private function clock(float $seconds): string
    {
        $total = (int) round($seconds);

        if ($total < 60) {
            return $total.'s';
        }

        if ($total < 3600) {
            return intdiv($total, 60).'m '.str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT).'s';
        }

        return intdiv($total, 3600).'h '
            .str_pad((string) intdiv($total % 3600, 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
