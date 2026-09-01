<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Support\Facades\DB;

class UpdateUser
{
    public function __construct(private readonly BoardAccess $access) {}

    /**
     * @param  array{name?: string, email?: string, password?: ?string, role?: ?UserRole, active?: bool}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            if (array_key_exists('name', $attributes)) {
                $user->name = trim((string) $attributes['name']);
            }

            if (array_key_exists('email', $attributes)) {
                $newEmail = mb_strtolower(trim((string) $attributes['email']));

                if ($newEmail !== $user->email) {
                    $user->email = $newEmail;
                    $user->email_verified_at = null;
                }
            }

            if (filled($attributes['password'] ?? null)) {
                $user->password = $attributes['password'];

                // Clearing the remember token invalidates any outstanding
                // "remember me" cookies issued against the old password.
                $user->setRememberToken(null);
            }

            if (($attributes['role'] ?? null) instanceof UserRole) {
                $user->role = $attributes['role'];
            }

            if (array_key_exists('active', $attributes)) {
                $user->deactivated_at = $attributes['active'] ? null : ($user->deactivated_at ?? now());
            }

            $user->save();

            // Role or activation changes alter what this user may see.
            $this->access->flush($user);

            return $user;
        });
    }
}
