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

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }
}
