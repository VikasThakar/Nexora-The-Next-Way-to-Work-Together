<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve an attachment.
 *
 * Never link a browser straight at the stored object. Every download passes
 * through here so the request is authorized against the owning ticket first —
 * which means an attachment on an internal ticket is exactly as protected as
 * the ticket.
 *
 * For an S3-compatible disk the response is a redirect to a short-lived signed
 * URL, so the bytes come from the bucket rather than through PHP. For a local
 * disk (development only) the file is streamed.
 */
class AttachmentController extends Controller
{
    public function __invoke(Attachment $attachment, AttachmentStorage $storage): RedirectResponse|StreamedResponse
    {
        // Gate::authorize rather than $this->authorize: Laravel 12's base
        // controller no longer carries AuthorizesRequests.
        Gate::authorize('view', $attachment);

        $temporaryUrl = $storage->temporaryUrl($attachment);

        if ($temporaryUrl !== null) {
            return redirect()->away($temporaryUrl);
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        /*
         * Images are served inline; everything else still downloads.
         *
         * This is what makes an inline image in a ticket description work on a
         * local disk. `download()` sets `Content-Disposition: attachment`, and
         * while an <img> tag ignores that header, opening the image in a tab —
         * or right-clicking it — would hand back a file to save rather than a
         * picture to look at.
         *
         * Restricted to image MIME types on purpose. Serving an arbitrary
         * uploaded file inline is how a stored HTML or SVG document becomes
         * script running on this origin, and `svg` is in the allowed upload
         * list. `Content-Type` is taken from the stored `mime_type` rather than
         * sniffed from the bytes, and `X-Content-Type-Options: nosniff` stops
         * the browser overruling it.
         *
         * hasImageMimeType(), not isImage(): the latter falls back to the
         * filename for historical rows, and a filename is chosen by whoever
         * uploaded the file. Presentation may guess; this decision may not.
         */
        if ($attachment->hasImageMimeType() && ! $this->isScriptable($attachment)) {
            return Storage::disk($attachment->disk)->response($attachment->path, $attachment->filename, [
                'Content-Type' => (string) $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }

    /**
     * Image formats a browser will execute script from.
     *
     * SVG is a document, not a bitmap: it can carry `<script>` and event
     * handlers, and served inline from this origin it would run with the
     * session's cookies. It stays a download, which is inert.
     */
    private function isScriptable(Attachment $attachment): bool
    {
        return str_contains((string) $attachment->mime_type, 'svg');
    }
}
