<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentStatus;

/**
 * What a processor made of a file.
 *
 * The single return type every processor shares, so the pipeline stores a
 * result without knowing which processor produced it. That is what makes the
 * abstraction real rather than decorative: adding a format cannot require a
 * change to the persistence, the prompt building or the UI.
 *
 * The three named constructors are the three honest outcomes, and they are
 * distinguishable on purpose:
 *
 *   ready()        we read the file. There may be no text — an image is Ready
 *                  with none — and there may be structure.
 *   failed()       we should have been able to read it and could not. The
 *                  message goes on the card, so it is written for a person.
 *   unsupported()  nothing reads this kind. Not an error, and not retryable;
 *                  the card says so rather than offering a retry.
 *
 * `summary` is the one-line description of what was found — "12 pages",
 * "1,204 rows x 8 columns". It is shown on the card, and it is also what an
 * attachment from an earlier turn contributes to a prompt in place of its whole
 * text, which is why every processor is expected to produce one.
 */
final readonly class AiAttachmentExtraction
{
    /**
     * @param  array<string, mixed>  $structured
     */
    private function __construct(
        public AiAttachmentStatus $status,
        public ?string $text = null,
        public array $structured = [],
        public ?string $summary = null,
        public bool $truncated = false,
        public ?string $error = null,
    ) {}

    /**
     * A file that was read.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function ready(
        ?string $text = null,
        array $structured = [],
        ?string $summary = null,
        bool $truncated = false,
    ): self {
        $text = $text === null ? null : trim($text);

        return new self(
            status: AiAttachmentStatus::Ready,
            text: $text === '' ? null : $text,
            structured: $structured,
            summary: $summary,
            truncated: $truncated,
        );
    }

    /**
     * A file we could not read but should have been able to.
     *
     * `$message` is shown to the person who uploaded it, so it says what to do
     * about it. It must never carry a stack trace, a filesystem path or a
     * provider response: those go to the log, and the card gets prose.
     */
    public static function failed(string $message): self
    {
        return new self(status: AiAttachmentStatus::Failed, error: $message);
    }

    /**
     * A kind nothing in this deployment reads.
     *
     * Also the answer when reading it would need something that is not
     * configured — an audio file with no transcription credential. The message
     * says which, and who can change it, because that is actionable where
     * "unsupported" alone is not.
     */
    public static function unsupported(string $message): self
    {
        return new self(status: AiAttachmentStatus::Unsupported, error: $message);
    }

    public function hasText(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }

    public function characters(): int
    {
        return $this->text === null ? 0 : mb_strlen($this->text);
    }
}
