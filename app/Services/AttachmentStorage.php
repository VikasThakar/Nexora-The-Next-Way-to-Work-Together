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
        $attachment = $this->put($file, $board, $uploader);

        $attachment->attachable()->associate($owner);
        $attachment->save();

        return $attachment;
    }

    /**
     * Store a file that has nothing to belong to yet.
     *
     * The creation-form case: a screenshot dropped into a ticket that will not
     * exist until Save is pressed. The bytes are written exactly as they are for
     * any other attachment — same disk, same generated path, same per-board
     * layout — and only the owner is missing, so adoption later is one column
     * rather than a move.
     *
     * An uploader is required rather than optional here, and that is the whole
     * security model of a draft: with no owner to delegate to, AttachmentPolicy
     * has nothing but this column to decide who may read the file. A null would
     * be a row nobody owns and anybody could claim.
     */
    public function storeDraft(UploadedFile $file, Board $board, User $uploader): Attachment
    {
        $attachment = $this->put($file, $board, $uploader);

        $attachment->save();

        return $attachment;
    }

    /**
     * Give a draft the owner it was uploaded for.
     *
     * Deliberately not a general "move this file to that record": it refuses
     * anything that is not still a draft, so a replayed request cannot re-point
     * a file that already belongs to a ticket somewhere else. Who is allowed to
     * ask is decided one level up, by App\Actions\Attachments\AdoptDraftAttachments.
     */
    public function adopt(Attachment $attachment, Model $owner): Attachment
    {
        if (! $attachment->isDraft()) {
            return $attachment;
        }

        $attachment->attachable()->associate($owner);
        $attachment->save();

        return $attachment;
    }

    /**
     * Write the bytes and build the row, without deciding what owns it.
     */
    private function put(UploadedFile $file, Board $board, ?User $uploader): Attachment
    {
        $disk = $this->disk();

        $extension = Str::lower($file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';

        $path = sprintf('attachments/boards/%d/%s%s', $board->getKey(), (string) Str::ulid(), $extension);

        $file->storeAs(dirname($path), basename($path), ['disk' => $disk]);

        return new Attachment([
            'board_id' => $board->getKey(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $this->safeFilename($file->getClientOriginalName()),
            'mime_type' => $this->mimeType($file),
            'size' => $file->getSize() ?: 0,
            'uploaded_by_id' => $uploader?->getKey(),
        ]);
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
     * A short-lived link for a remote disk, or null when the bytes should be
     * streamed through the application instead.
     *
     * The question this asks is **"is the file somewhere else?"**, and it is
     * deliberately answered from the configured driver rather than from
     * `providesTemporaryUrls()`.
     *
     * Those are not the same question, and assuming they were is what broke
     * this method. `providesTemporaryUrls()` answers "can this adapter build a
     * URL", and Laravel's local driver can: with `'serve' => true` it issues a
     * signed link to its own `storage.{disk}` route. So a local disk said yes,
     * the controller redirected, and the redirect pointed at a route that is
     * not registered — because `local` and `volume` are both local disks that
     * claim the same `/storage/{path}` URI, and the one declared second
     * silently replaces the first in the route collection. Every attachment
     * download on a local deployment raised RouteNotFoundException.
     *
     * Serving an attachment from that route would be wrong even where it
     * resolves. App\Http\Controllers\AttachmentController is the only thing
     * that authorizes a download against the record owning the file, and it is
     * also where the inline-versus-download decision and the `nosniff` header
     * live. Laravel's route knows about none of that. The redirect exists for
     * exactly one reason — bytes that live in a bucket should not be dragged
     * through PHP — and a local disk has nothing to gain from it, because PHP
     * is already serving the request.
     *
     * A remote disk that cannot produce a link degrades to streaming rather
     * than failing the download. A misconfigured bucket is somebody's afternoon;
     * a 500 on every image in every ticket is everybody's.
     */
    public function temporaryUrl(Attachment $attachment): ?string
    {
        return $this->temporaryUrlFor($attachment->disk, $attachment->path);
    }

    /**
     * The same decision, for a file that is not an attachment row.
     *
     * Profile pictures are stored outside this table but on the same disk, and
     * they need this answered identically — see
     * App\Http\Controllers\UserAvatarController. Sharing the method rather than
     * the reasoning is the point: this is the second caller, and the bug
     * described above is exactly the kind a second implementation reintroduces.
     */
    public function temporaryUrlFor(?string $disk, ?string $path): ?string
    {
        if (! filled($disk) || ! filled($path) || $this->isLocal($disk)) {
            return null;
        }

        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->providesTemporaryUrls()) {
                return null;
            }

            return $filesystem->temporaryUrl(
                $path,
                now()->addMinutes((int) config('attachments.url_ttl_minutes', 5))
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Does this disk keep its bytes on the machine serving the request?
     *
     * Read from configuration rather than by inspecting the adapter, because an
     * adapter can be swapped for a fake in a test while the disk it stands in
     * for is still conceptually remote — `Storage::fake('s3')` installs a local
     * driver, and a test asserting the redirect branch needs that branch to be
     * chosen by what the deployment says the disk is.
     */
    private function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
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
