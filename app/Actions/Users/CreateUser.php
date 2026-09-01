<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place a user account is created.
 *
 * `role` is not mass assignable on the model, so it must be set explicitly
 * here. That keeps privilege assignment auditable to a single call site.
 */
class CreateUser
{
    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function handle(array $attributes, UserRole $role): User
    {
        return DB::transaction(function () use ($attributes, $role): User {
            $user = new User([
                'name' => trim($attributes['name']),
                'email' => mb_strtolower(trim($attributes['email'])),
                'password' => $attributes['password'],
            ]);

            $user->role = $role;
            $user->save();

            return $user;
        });
    }
}
