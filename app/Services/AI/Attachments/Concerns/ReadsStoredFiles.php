<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments\Concerns;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * How a processor gets at the bytes.
 *
 * One implementation, shared, because every processor needs it and none of them
 * should be reading storage its own way. Two things are worth stating.
 *
 * The path is never composed. It comes off the attachment row, where
 * AttachmentStorage put a generated ULID path; nothing here concatenates a
 * filename, an extension or a directory, so there is no way for an uploaded
 * name to influence which object is opened.
 *
 * The bytes go to a local copy for the libraries that need one. A PDF parser
 * and a ZIP reader both want a seekable file rather than a string, and on an
 * S3 disk there is no such file — so one is made in the system temporary
 * directory, with a generated name, and deleted in a finally block. That copy
 * is only ever *read*: it is never included, executed, or served.
 */
trait ReadsStoredFiles
{
    /**
     * The whole file as a string, or null if it is not there.
     *
     * Null rather than an exception: an object that has gone missing (a disk
     * swapped, a bucket lifecycle rule) is an ordinary condition the card
     * should report, not a job failure.
     */
    protected function contents(Attachment $attachment): ?string
    {
        $disk = Storage::disk((string) $attachment->disk);

        if (! $disk->exists((string) $attachment->path)) {
            return null;
        }

        $contents = $disk->get((string) $attachment->path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Run something against a local copy of the file.
     *
     * The callback receives an absolute path to a temporary file that this
     * method created and will delete. It exists for libraries that cannot take
     * a string — smalot/pdfparser can, ZipArchive cannot — and it is the only
     * place in the attachment pipeline that puts uploaded bytes on the local
     * filesystem.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn|null
     */
    protected function withLocalCopy(Attachment $attachment, callable $callback): mixed
    {
        $contents = $this->contents($attachment);

        if ($contents === null) {
            return null;
        }

        // A generated name in the system temporary directory. The uploaded
        // filename contributes nothing to it, so nothing about the upload can
        // decide where this lands or what it is called.
        $path = tempnam(sys_get_temp_dir(), 'nexora-ai-');

        if ($path === false) {
            return null;
        }

        try {
            if (file_put_contents($path, $contents) === false) {
                return null;
            }

            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
