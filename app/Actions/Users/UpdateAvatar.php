<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AvatarStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Give somebody a profile picture, replacing whatever they had.
 *
 * The only place `avatar_path` and `avatar_disk` are written. Neither column is
 * mass assignable, so this is not a convention a caller can go around.
 *
 * Authorization is the caller's job, as it is for every action in this
 * directory. In practice there is one caller — App\Livewire\Profile\UpdateAvatar
 * — and it only ever operates on auth()->user(), which is why UserPolicy has no
 * "update own profile" ability to invoke. An administrator cannot set somebody
 * else's picture; there is no screen that offers it and no route that accepts
 * a user to act on.
 *
 * The order of the three writes is the whole of the failure design
 * ----------------------------------------------------------------
 * New file, then columns, then delete the old file. Taken in that order, every
 * interruption leaves the person with a picture:
 *
 *   failing before the write      they keep the old one
 *   failing before the columns    they keep the old one; the new file is
 *                                 unreferenced and `workspace:prune` collects it
 *   failing before the delete     they have the new one; the old file is
 *                                 unreferenced and `workspace:prune` collects it
 *
 * The opposite order — delete first — has a window in which somebody who
 * uploaded a photograph has none at all, which is the one outcome that looks
 * like data loss to the person it happens to.
 *
 * The delete is outside the transaction deliberately. A filesystem cannot be
 * rolled back, so removing the old object inside one would mean a rollback that
 * restores a row pointing at bytes that are gone.
 */
class UpdateAvatar
{
    public function __construct(private readonly AvatarStorage $storage) {}

    /**
     * Returns false when the upload is not an image GD can open. Nothing is
     * written in that case and the person keeps the picture they had.
     */
    public function handle(User $user, UploadedFile $file): bool
    {
        $stored = $this->storage->store($file);

        if ($stored === null) {
            return false;
        }

        $previousDisk = $user->avatar_disk;
        $previousPath = $user->avatar_path;

        DB::transaction(function () use ($user, $stored): void {
            $user->avatar_disk = $stored['disk'];
            $user->avatar_path = $stored['path'];
            $user->save();
        });

        // Only after the row points at the new object. A picture is never
        // shared between rows — every upload gets its own ULID — so this can
        // never remove a file something else still references.
        if ($previousPath !== $stored['path']) {
            $this->storage->delete($previousDisk, $previousPath);
        }

        return true;
    }
}
