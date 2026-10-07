<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AvatarStorage;
use Illuminate\Support\Facades\DB;

/**
 * Take somebody's profile picture away, returning them to their initials.
 *
 * The columns are cleared before the object is deleted, which is the same
 * ordering App\Services\AttachmentStorage uses and for the same reason: an
 * orphaned object costs storage, whereas an orphaned row shows a person a
 * broken picture of themselves on every screen in the product.
 *
 * Nothing else about the account changes. Removing a picture is not
 * deactivation, and there is deliberately no cascade — a person who deletes
 * their photograph keeps their tickets, their comments and their history.
 */
class RemoveAvatar
{
    public function __construct(private readonly AvatarStorage $storage) {}

    public function handle(User $user): User
    {
        if (! $user->hasAvatar()) {
            return $user;
        }

        $disk = $user->avatar_disk;
        $path = $user->avatar_path;

        DB::transaction(function () use ($user): void {
            $user->avatar_disk = null;
            $user->avatar_path = null;
            $user->save();
        });

        $this->storage->delete($disk, $path);

        return $user;
    }
}
