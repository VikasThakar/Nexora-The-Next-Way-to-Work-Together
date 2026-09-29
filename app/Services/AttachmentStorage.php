<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts uploaded files somewhere durable and records them.
 *
 * Storage rules this class exists to enforce:
 *
 *  - The stored path is generated, never derived from the uploaded filename.
 *    A user-supplied name can contain traversal sequences, null bytes or an
 *    executable extension; the original is kept only as a display label.
 *  - The disk used is written onto every row, so changing the application
 *    default later cannot orphan files uploaded before the change.
 *  - Files are laid out per board, which keeps a board's data together for
 *    deletion and for any future per-tenant bucket policy.
 *
 * On Railway the container filesystem is replaced on every deploy, so the local
 * disk is only ever acceptable in development. config/attachments.php refuses
 * to allow it in production.
 */
class AttachmentStorage
{
    public function disk(): string
    {
        return (string) config('attachments.disk', config('filesystems.default'));
    }

    /**
     * Store an uploaded file and record it against its owner.
     */
    public function store(UploadedFile $file, Model $owner, Board $board, ?User $uploader = null): Attachment
    {
        $disk = $this->disk();

        $extension = Str::lower($file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';

        $path = sprintf('attachments/boards/%d/%s%s', $board->getKey(), (string) Str::ulid(), $extension);

        $file->storeAs(dirname($path), basename($path), ['disk' => $disk]);

        $attachment = new Attachment([
            'board_id' => $board->getKey(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $this->safeFilename($file->getClientOriginalName()),
            'mime_type' => $this->mimeType($file),
            'size' => $file->getSize() ?: 0,
            'uploaded_by_id' => $uploader?->getKey(),
        ]);

        $attachment->attachable()->associate($owner);
        $attachment->save();

        return $attachment;
    }

    /**
     * Duplicate an existing attachment onto another owner.
     *
     * A copy rather than a move, because the two owners have different lives:
     * a screenshot dropped into an assistant conversation stays in that
     * conversation — the transcript still refers to it — while the ticket the
     * assistant created from it needs its own file, under the ticket's own
     * visibility and cleaned up with the ticket. Sharing one object between
     * them would tie the deletion of either to the other.
     *
     * The bytes go to a freshly generated path on the same disk, laid out per
     * board exactly as a direct upload is, so board-scoped cleanup finds them.
     */
    public function copy(Attachment $source, Model $owner, ?Board $board, ?User $uploader = null): Attachment
    {
        $disk = (string) $source->disk;

        $extension = Str::lower(pathinfo((string) $source->path, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';

        $path = $board instanceof Board
            ? sprintf('attachments/boards/%d/%s%s', $board->getKey(), (string) Str::ulid(), $extension)
            : sprintf('attachments/copies/%s%s', (string) Str::ulid(), $extension);

        Storage::disk($disk)->copy((string) $source->path, $path);

        $attachment = new Attachment([
            'board_id' => $board?->getKey(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $source->filename,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'uploaded_by_id' => $uploader?->getKey() ?? $source->uploaded_by_id,
        ]);

        $attachment->attachable()->associate($owner);
        $attachment->save();

        return $attachment;
    }

    /**
     * Remove the row and the underlying object.
     *
     * The row goes first: an orphaned object costs storage, whereas an orphaned
     * row shows the user a broken download.
     */
    public function delete(Attachment $attachment): void
    {
        $disk = $attachment->disk;
        $path = $attachment->path;

        $attachment->delete();

        Storage::disk($disk)->delete($path);
    }

    /**
     * A short-lived link for an S3-compatible disk, or null when the disk
     * cannot produce one (local development), in which case the caller streams
     * the file through the application instead.
     */
    public function temporaryUrl(Attachment $attachment): ?string
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->providesTemporaryUrls()) {
            return null;
        }

        /*
         * A disk that claims it can issue a URL and then cannot streams instead.
         *
         * Laravel's local driver says yes whenever the disk is configured with
         * `serve`, and the URL it issues points at a route named after the
         * disk. Two served local disks (`local` and `volume` in
         * config/filesystems.php) share one URI, so the second registration
         * silently replaces the first's route name and the other disk's URL
         * throws "route not defined" — which made every attachment on a
         * development install a 500. The bytes come out of the same disk
         * either way; the redirect only ever bought anything for an object
         * store, so falling back to the stream is the right answer, not an
         * error page.
         */
        try {
            return $disk->temporaryUrl(
                $attachment->path,
                now()->addMinutes((int) config('attachments.url_ttl_minutes', 5))
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The type of an uploaded file, detected rather than declared.
     *
     * `getClientMimeType()` returns whatever the *browser* said, and for a
     * Livewire upload it says `application/octet-stream` — the file has already
     * been written to the temporary disk by the time this code sees it, so the
     * original request header is long gone. Every attachment stored through the
     * interface therefore had a useless type, which is why an uploaded PNG got
     * the generic document icon in the attachments panel.
     *
     * `getMimeType()` reads the file instead. That is both more accurate and
     * safer: a declared type is an assertion by whoever uploaded the file,
     * where a detected one is a fact about its bytes. It is also consistent
     * with the `mimes:` validation rule, which already guesses from content.
     *
     * Falls back to the declared type only if detection fails, and to the
     * generic type if that is empty too, so a row always has something.
     */
    private function mimeType(UploadedFile $file): string
    {
        try {
            $detected = $file->getMimeType();
        } catch (\Throwable) {
            // A disk that cannot be read back must not fail the upload here —
            // the write above already succeeded.
            $detected = null;
        }

        return $detected ?: ($file->getClientMimeType() ?: 'application/octet-stream');
    }

    /**
     * Keep something human-readable, without letting it influence any path.
     */
    private function safeFilename(?string $original): string
    {
        $name = trim((string) $original);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' ? 'attachment' : Str::limit($name, 200, '');
    }
}
