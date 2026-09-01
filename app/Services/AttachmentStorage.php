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
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by_id' => $uploader?->getKey(),
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

        return $disk->temporaryUrl(
            $attachment->path,
            now()->addMinutes((int) config('attachments.url_ttl_minutes', 5))
        );
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
