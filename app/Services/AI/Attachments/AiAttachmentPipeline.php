<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Enums\AiAttachmentStatus;
use App\Jobs\ProcessAiAttachment;
use App\Models\AiAttachment;
use App\Models\AiSession;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Storing an attachment, and reading it.
 *
 * The one place both halves meet, and the only class that writes to
 * `ai_attachments`. Everything above it — the composer, the chat service, the
 * queued job — talks to this and never to a processor directly, which is what
 * lets a new file format be a new processor and nothing else.
 *
 * Order of operations on an upload
 * --------------------------------
 * Deliberate, and each step is where it is because of what the previous one
 * proved:
 *
 *   1. authorization. The caller has already asked, and this asks again —
 *      see the note on attach();
 *   2. classification, against the extension *and* the detected type, before
 *      any byte is stored. A file that will not be read is not kept;
 *   3. the per-conversation count, so a conversation cannot accumulate an
 *      unbounded context;
 *   4. storage, through App\Services\AttachmentStorage — the same generated
 *      ULID path, the same durability check, the same disk as every other
 *      attachment in the product;
 *   5. the row, Pending;
 *   6. the job. Queued, so a forty-page PDF or a ten-minute recording does not
 *      hold a web request open.
 *
 * Deduplication
 * -------------
 * Attaching the same file to the same conversation twice reuses the first
 * reading rather than parsing and paying for it again. Matched on a SHA-256 of
 * the stored bytes, scoped to the conversation. Not a security control — two
 * people may legitimately attach the same specification to two conversations,
 * and each gets its own row under its own session.
 */
class AiAttachmentPipeline
{
    /**
     * @param  iterable<AiAttachmentProcessor>  $processors
     */
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AiAttachmentClassifier $classifier,
        private readonly iterable $processors,
    ) {}

    // -----------------------------------------------------------------
    // Storing
    // -----------------------------------------------------------------

    /**
     * Store one uploaded file against a conversation and queue its reading.
     *
     * @throws RuntimeException with a message written for the person who
     *                          uploaded it — the composer shows it verbatim
     */
    public function attach(UploadedFile $file, AiSession $session, User $uploader): AiAttachment
    {
        if (! (bool) config('ai.attachments.enabled', true)) {
            throw new RuntimeException('Attachments are switched off for this deployment.');
        }

        /*
         * Authorized here as well as in the component.
         *
         * The component's check is what shows or hides the control; this one is
         * what makes the operation safe, and it is inside the service precisely
         * so that a future caller — a queued import, a second surface, an
         * API — cannot forget it. It costs one gate call.
         */
        if ($uploader->cannot('manageAttachments', $session)) {
            throw new RuntimeException('You cannot attach files to that conversation.');
        }

        $kind = $this->classifier->classify($file);

        if ($kind === null) {
            throw new RuntimeException($this->refusalFor($file));
        }

        $limit = max(1, (int) config('ai.attachments.max_per_session', 10));

        if ($this->countFor($session) >= $limit) {
            throw new RuntimeException(
                'This conversation already has '.$limit.' attachments, which is the limit. '
                .'Remove one, or start a new session for a fresh set.'
            );
        }

        $maxKb = max(1, (int) config('ai.attachments.max_size_kb', 20480));

        if (($file->getSize() ?: 0) > $maxKb * 1024) {
            throw new RuntimeException(
                'That file is larger than the '.$this->megabytes($maxKb).' limit for attachments.'
            );
        }

        /*
         * The board is taken from the session, never from the request.
         *
         * It is what the visibility scope reads, so a value that could be
         * supplied would be a way to file a document against a board the
         * uploader cannot reach — or to make one readable there.
         */
        $board = $session->board;

        if ($board === null && $session->board_id !== null) {
            // A board that has been deleted under a live conversation. The
            // session is unusable; refusing is better than filing an orphan.
            throw new RuntimeException('That conversation belongs to a board that no longer exists.');
        }

        $attachment = $board === null
            ? $this->storeWithoutBoard($file, $session, $uploader)
            : $this->storage->store($file, $session, $board, $uploader);

        $record = DB::transaction(function () use ($attachment, $session, $uploader, $kind): AiAttachment {
            $record = new AiAttachment;

            // Assigned, never mass assigned. A request that could choose its
            // own ai_session_id could file a document into somebody else's
            // conversation.
            $record->attachment_id = $attachment->getKey();
            $record->ai_session_id = $session->getKey();
            $record->board_id = $session->board_id;
            $record->user_id = $uploader->getKey();
            $record->kind = $kind;
            $record->status = AiAttachmentStatus::Pending;
            $record->checksum = $this->checksum($attachment);

            $record->save();

            return $record;
        });

        $reused = $this->reuseExtraction($record);

        if (! $reused) {
            ProcessAiAttachment::dispatch($record->getKey());
        }

        return $record->refresh();
    }

    /**
     * Take a file back off a conversation.
     *
     * The stored object goes with it, through AttachmentStorage so the row and
     * the object are removed by the same code that created them. Removing an
     * attachment does not touch the turns that were already answered with it —
     * the transcript is a record of what happened, and what happened is that
     * the assistant read that file.
     */
    public function detach(AiAttachment $record, User $actor): void
    {
        if ($actor->cannot('manageAttachments', $record->session)) {
            throw new RuntimeException('You cannot change that conversation.');
        }

        $attachment = $record->attachment;

        // The ai_attachments row cascades from the attachment, so deleting the
        // attachment is sufficient and keeps one deletion path.
        if ($attachment instanceof Attachment) {
            $this->storage->delete($attachment);

            return;
        }

        $record->delete();
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Read a stored file and record what came out.
     *
     * Called from the queued job. It cannot throw: any exception from a
     * processor is caught and turned into a Failed row, because the alternative
     * is a card that spins forever while the job retries a file that will never
     * parse.
     */
    public function process(AiAttachment $record): AiAttachment
    {
        $attachment = $record->attachment;

        if (! $attachment instanceof Attachment) {
            return $this->store(
                $record,
                AiAttachmentExtraction::failed('The stored file is no longer available.')
            );
        }

        $processor = $this->processorFor($record->kind);

        if ($processor === null) {
            return $this->store(
                $record,
                AiAttachmentExtraction::unsupported(
                    'Nexora has no reader for '.$record->kind->label().' files in this deployment.'
                )
            );
        }

        $record->status = AiAttachmentStatus::Processing;
        $record->save();

        try {
            $extraction = $processor->process($attachment);
        } catch (Throwable $exception) {
            /*
             * The detail goes to the log, and prose goes on the card.
             *
             * An exception message from a parser names byte offsets and object
             * numbers; it is useful to whoever is debugging and useless to
             * whoever uploaded the file. Neither the filename nor the message
             * is put in front of the person as-is.
             */
            Log::warning('An AI attachment could not be processed.', [
                'ai_attachment_id' => $record->getKey(),
                'kind' => $record->kind->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $extraction = AiAttachmentExtraction::failed(
                'That file could not be read. It may be corrupt, or not really a '
                .$record->kind->label().' file.'
            );
        }

        return $this->store($record, $extraction);
    }

    /**
     * The processor for a kind, or null.
     *
     * A search rather than a map because the collection is injected: the
     * container hands over every registered implementation and each declares
     * its own kind, so registering one is a line in the service provider and
     * nothing here changes.
     */
    public function processorFor(AiAttachmentKind $kind): ?AiAttachmentProcessor
    {
        foreach ($this->processors as $processor) {
            if ($processor->kind() === $kind) {
                return $processor;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------

    /**
     * Write an extraction onto its row.
     *
     * The token figure is derived here rather than in each processor, so there
     * is one ratio and one place it is applied. It is an *estimate* and is
     * labelled one everywhere it is shown; nothing derived here is ever written
     * to `ai_usage_records`, which only ever holds figures a provider reported.
     */
    private function store(AiAttachment $record, AiAttachmentExtraction $extraction): AiAttachment
    {
        $perToken = max(1, (int) config('ai.attachments.context.characters_per_token', 4));
        $characters = $extraction->characters();

        $record->status = $extraction->status;
        $record->extracted_text = $extraction->text;
        $record->structured = $extraction->structured === [] ? null : $extraction->structured;
        $record->summary = $extraction->summary;
        $record->characters = $characters;
        $record->token_estimate = $characters === 0 ? null : (int) ceil($characters / $perToken);
        $record->truncated = $extraction->truncated;
        $record->error = $extraction->error;
        $record->processed_at = now();

        $record->save();

        return $record;
    }

    /**
     * Copy an earlier reading of the same file in the same conversation.
     *
     * The saving is real: a fifty-page PDF attached twice is parsed once. The
     * scope is the conversation, so this never reaches across to somebody
     * else's — and the row is still its own row, with its own status and its
     * own place in the composer.
     */
    private function reuseExtraction(AiAttachment $record): bool
    {
        if ($record->checksum === null) {
            return false;
        }

        $existing = AiAttachment::query()
            ->where('ai_session_id', $record->ai_session_id)
            ->where('checksum', $record->checksum)
            ->whereKeyNot($record->getKey())
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->first();

        if (! $existing instanceof AiAttachment) {
            return false;
        }

        $record->status = $existing->status;
        $record->extracted_text = $existing->extracted_text;
        $record->structured = $existing->structured;
        $record->summary = $existing->summary;
        $record->characters = $existing->characters;
        $record->token_estimate = $existing->token_estimate;
        $record->truncated = $existing->truncated;
        $record->error = $existing->error;
        $record->processed_at = now();

        $record->save();

        return true;
    }

    /**
     * SHA-256 of the stored bytes, or null if they cannot be read.
     *
     * Computed from what was stored rather than from the upload, so it is a
     * hash of the thing that will actually be parsed.
     */
    private function checksum(Attachment $attachment): ?string
    {
        try {
            $disk = Storage::disk((string) $attachment->disk);

            if (! $disk->exists((string) $attachment->path)) {
                return null;
            }

            $contents = $disk->get((string) $attachment->path);

            return is_string($contents) ? hash('sha256', $contents) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How many files this conversation already carries.
     */
    public function countFor(AiSession $session): int
    {
        return AiAttachment::query()->where('ai_session_id', $session->getKey())->count();
    }

    /**
     * Store a file for a conversation that has no board.
     *
     * The workspace conversation is the only owner in the product with no
     * board, and AttachmentStorage requires one for its per-board layout — for
     * good reasons: cleanup and any future per-tenant bucket policy. Rather
     * than loosen that signature for one caller, the workspace conversation
     * files under the uploader's own board-less path, which is stable and
     * unique and keeps the "generated path, never derived from the filename"
     * guarantee.
     */
    private function storeWithoutBoard(UploadedFile $file, AiSession $session, User $uploader): Attachment
    {
        $disk = $this->storage->disk();

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';

        $path = sprintf(
            'attachments/ai-sessions/%d/%s%s',
            $session->getKey(),
            (string) Str::ulid(),
            $extension
        );

        $file->storeAs(dirname($path), basename($path), ['disk' => $disk]);

        $attachment = new Attachment([
            // No board: the conversation is the workspace one, and the row's
            // reachability comes from its owner rather than from a board.
            'board_id' => null,
            'disk' => $disk,
            'path' => $path,
            'filename' => $this->safeFilename($file->getClientOriginalName()),
            'mime_type' => $this->detectedType($file),
            'size' => $file->getSize() ?: 0,
            'uploaded_by_id' => $uploader->getKey(),
        ]);

        $attachment->attachable()->associate($session);
        $attachment->save();

        return $attachment;
    }

    /**
     * Same rules as AttachmentStorage's: keep something readable, let it
     * influence nothing.
     */
    private function safeFilename(?string $original): string
    {
        $name = trim((string) $original);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' ? 'attachment' : mb_substr($name, 0, 200);
    }

    private function detectedType(UploadedFile $file): string
    {
        try {
            $detected = $file->getMimeType();
        } catch (Throwable) {
            $detected = null;
        }

        return $detected ?: ($file->getClientMimeType() ?: 'application/octet-stream');
    }

    /**
     * Why a file was refused, said usefully.
     *
     * The two cases read differently on purpose. An extension nobody accepts is
     * the person's mistake and the message lists what does work. An accepted
     * extension whose bytes disagree is more interesting, and saying so is both
     * more honest and more likely to be actionable — that is usually a file
     * somebody renamed, or an export that silently produced HTML.
     */
    private function refusalFor(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $claimed = $extension === '' ? null : $this->classifier->kindForExtension($extension);

        if ($claimed === null) {
            return 'Nexora cannot read .'.($extension === '' ? '' : $extension)
                .' files. You can attach '.$this->allowedList().'.';
        }

        return 'That file is named .'.$extension.' but its contents are not a '
            .$claimed->label().' file, so it has not been stored. '
            .'If it was renamed, export it again in the format its name claims.';
    }

    private function allowedList(): string
    {
        $extensions = array_map(
            static fn (string $extension): string => '.'.$extension,
            $this->classifier->allowedExtensions()
        );

        if ($extensions === []) {
            return 'nothing in this deployment';
        }

        $last = array_pop($extensions);

        return $extensions === [] ? $last : implode(', ', $extensions).' and '.$last;
    }

    private function megabytes(int $kilobytes): string
    {
        $mb = $kilobytes / 1024;

        return ($mb === floor($mb) ? (string) (int) $mb : number_format($mb, 1)).' MB';
    }
}
