<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Images\ImageTranscoder;
use App\Support\Images\ProcessedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a profile picture lives.
 *
 * Small on purpose. A profile picture is not an attachment — it hangs off no
 * record, belongs to no board, and is not served by AttachmentController — so
 * it does not go in the `attachments` table. What it does share is the disk,
 * and that is the part worth sharing: config('attachments.disk') is the one
 * App\Providers\AppServiceProvider refuses to boot production without, and
 * which it stats when that disk is a mounted volume. Pictures inherit that
 * guarantee without a second check having to be written and remembered.
 *
 * The rules below are App\Services\AttachmentStorage's, applied again rather
 * than reimplemented differently:
 *
 *  - the stored path is generated, never derived from the uploaded filename. A
 *    user-supplied name can carry traversal sequences, null bytes or an
 *    executable extension, and none of it reaches a path here.
 *  - the disk is written onto the row, so changing the application default
 *    later cannot orphan pictures taken before the change.
 *  - no caller may supply a path or a storage key. The only input is an
 *    UploadedFile, and what is written is not that file.
 *
 * What is stored is what we encoded
 * ---------------------------------
 * The uploaded bytes are never written to disk. They are decoded by
 * App\Support\Images\ImageTranscoder and a fresh square JPEG or PNG is written
 * instead — which is what makes "a .png whose bytes are a shell script" a
 * refusal rather than a stored file, and what strips the EXIF that routinely
 * carries the GPS coordinates of wherever a photograph was taken.
 */
class AvatarStorage
{
    public function __construct(private readonly ImageTranscoder $transcoder) {}

    public function disk(): string
    {
        return (string) config('attachments.disk', config('filesystems.default'));
    }

    /**
     * Transcode an upload and write it.
     *
     * Returns null when the file is not an image GD can open — which is the
     * only failure this reports, because it is the only one a person can act
     * on. The caller turns it into a message; nothing is written.
     *
     * @return array{disk: string, path: string}|null
     */
    public function store(UploadedFile $file): ?array
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            return null;
        }

        $image = $this->transcoder->square(
            $contents,
            max(64, (int) config('attachments.avatars.max_dimension', 512)),
            (int) config('attachments.avatars.jpeg_quality', 85),
        );

        if (! $image instanceof ProcessedImage) {
            return null;
        }

        $disk = $this->disk();

        // Flat rather than per-user: a picture is replaced by writing a new
        // ULID and deleting the old one, so a per-user directory would only
        // ever hold a single file and would leak the user id into the path.
        $path = 'avatars/'.(string) Str::ulid().'.'.$image->extension;

        Storage::disk($disk)->put($path, $image->bytes);

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * Remove a stored picture.
     *
     * Tolerant of a file that is already gone: the row is the record of what
     * should exist, and a delete that cannot find its object has still achieved
     * what it was asked to.
     */
    public function delete(?string $disk, ?string $path): void
    {
        if (! filled($disk) || ! filled($path)) {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    /**
     * The media type for a stored picture, from its extension.
     *
     * Safe to trust precisely because the extension is not the uploader's: it
     * was chosen by store() from what the transcoder actually encoded. Anything
     * unrecognised falls back to bytes, which cannot be rendered or executed.
     */
    public function mimeTypeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
