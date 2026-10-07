<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AttachmentStorage;
use App\Services\AvatarStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a profile picture.
 *
 * Never link a browser straight at the stored object. Every fetch passes
 * through here so the request is authorized first — see
 * App\Policies\UserPolicy::viewAvatar, which says a picture is exactly as
 * visible as the name it sits beside.
 *
 * The body of this is deliberately the same shape as AttachmentController: a
 * remote disk redirects to a short-lived signed URL so the bytes come from the
 * bucket rather than through PHP, and a local disk is streamed. That decision
 * is **borrowed from AttachmentStorage rather than re-derived**, because asking
 * it the obvious way — `providesTemporaryUrls()` — was a live bug: Laravel's
 * local driver answers yes and then signs a route that does not exist. The
 * question that matters is whether the file is somewhere else, and only the
 * configured driver answers it.
 */
class UserAvatarController extends Controller
{
    public function __invoke(
        User $user,
        AvatarStorage $avatars,
        AttachmentStorage $attachments,
    ): RedirectResponse|StreamedResponse {
        // Gate::authorize rather than $this->authorize: Laravel 12's base
        // controller no longer carries AuthorizesRequests.
        Gate::authorize('viewAvatar', $user);

        abort_unless($user->hasAvatar(), 404);

        $disk = (string) $user->avatar_disk;
        $path = (string) $user->avatar_path;

        $temporaryUrl = $attachments->temporaryUrlFor($disk, $path);

        if ($temporaryUrl !== null) {
            return redirect()->away($temporaryUrl);
        }

        abort_unless(Storage::disk($disk)->exists($path), 404);

        /*
         * Served inline, and safely so for one reason: this file was written by
         * App\Services\AvatarStorage from bytes GD decoded and re-encoded, so
         * the extension is ours and the type is true by construction. Nothing
         * an uploader chose reaches either header. `nosniff` then stops a
         * browser overruling the type we have stated.
         *
         * SVG cannot arrive here — it is refused at validation and would not
         * survive the transcoder — which is why this has no equivalent of
         * AttachmentController's scriptable-format branch.
         */
        return Storage::disk($disk)->response($path, 'avatar', [
            'Content-Type' => $avatars->mimeTypeFor($path),
            'X-Content-Type-Options' => 'nosniff',

            /*
             * Private, never shared. The response was authorized for this
             * viewer, so a proxy or CDN holding it would be serving one
             * person's picture to whoever asked next.
             *
             * An hour is safe despite the route being stable per user, because
             * User::avatarUrl() carries a digest of the stored path: replacing
             * a picture changes the URL, so nothing stale is ever requested.
             */
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
