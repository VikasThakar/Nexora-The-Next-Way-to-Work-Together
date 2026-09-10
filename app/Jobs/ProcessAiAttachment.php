<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AiAttachmentStatus;
use App\Models\AiAttachment;
use App\Services\AI\Attachments\AiAttachmentPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reads one uploaded file, off the request cycle.
 *
 * Reading a forty-page PDF takes seconds and transcribing a ten-minute
 * recording takes considerably longer. Neither may happen inside the upload
 * request: Livewire uploads as soon as a file is chosen, so that request is the
 * one holding the composer's spinner, and a person choosing three files would
 * otherwise wait for all three serially before typing a word.
 *
 * So the upload stores the file and returns, and this reads it. The attachment
 * card shows Queued, then Processing, then what came out, polling while the row
 * is not terminal. On a `sync` queue — which is what the test suite uses — the
 * whole thing simply happens inline, and the card is Ready by the time the
 * upload response is rendered.
 *
 * Why it takes a key and not a model
 * ----------------------------------
 * A serialised model in a payload is a snapshot: it would carry the row's state
 * from the moment of dispatch, which for a row whose whole purpose is to change
 * state is precisely the wrong thing to hold. The key is re-read here, so the
 * job always operates on the current row and a row deleted between dispatch and
 * execution is simply gone rather than resurrected.
 *
 * Failure
 * -------
 * The pipeline does not throw for a bad file — a corrupt PDF is an ordinary
 * input and becomes a Failed row with prose on the card. So an exception
 * reaching this job's boundary is something else entirely: storage unreachable,
 * the database gone. Those are worth retrying, hence the two attempts, and
 * failed() is what stops a card spinning forever after the last one.
 */
class ProcessAiAttachment implements ShouldQueue
{
    use Queueable;

    /**
     * Two attempts, not three.
     *
     * The retryable failures here are transient infrastructure ones, and a file
     * that could not be parsed is not retried at all — the pipeline already
     * recorded why. A third attempt would only delay the card.
     */
    public int $tries = 2;

    /**
     * Long enough for a transcription of a long recording, which is the slowest
     * thing this job does by an order of magnitude.
     */
    public int $timeout = 600;

    public function __construct(public readonly int $aiAttachmentId) {}

    /**
     * One worker per row.
     *
     * Two workers reading the same file would both write an extraction, and the
     * one that finished second would win — harmless for a PDF and not harmless
     * for a transcription, which would be paid for twice.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai-attachment:'.$this->aiAttachmentId))->dontRelease()];
    }

    public function handle(AiAttachmentPipeline $pipeline): void
    {
        $record = AiAttachment::query()->whereKey($this->aiAttachmentId)->first();

        if (! $record instanceof AiAttachment) {
            // Removed from the conversation before the worker picked it up.
            return;
        }

        /*
         * Already read.
         *
         * Either a retry after a partial success, or the dedupe path in the
         * pipeline copied an earlier reading of the same file before this job
         * ran. Re-reading would spend a transcription's worth of money to
         * arrive at the answer already in the row.
         */
        if ($record->processed_at !== null && $record->status->isTerminal()) {
            return;
        }

        $pipeline->process($record);
    }

    /**
     * The last word when every attempt failed.
     *
     * Without this the row stays Pending, which is a non-terminal state, which
     * means the card polls for a change that is never coming.
     */
    public function failed(?\Throwable $exception): void
    {
        $record = AiAttachment::query()->whereKey($this->aiAttachmentId)->first();

        if (! $record instanceof AiAttachment || $record->status->isTerminal()) {
            return;
        }

        $record->status = AiAttachmentStatus::Failed;
        $record->error = 'That file could not be processed. Try attaching it again.';
        $record->processed_at = now();

        $record->save();
    }
}
