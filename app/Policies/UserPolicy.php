<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User administration is an administrator-only concern.
 *
 * Customers and team members can only ever act on their own account, which is
 * handled by the profile components rather than this policy.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAdministerWorkspace();
    }

    public function view(User $user, User $model): bool
    {
        return $user->canAdministerWorkspace() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->canAdministerWorkspace();
    }

    public function update(User $user, User $model): bool
    {
        return $user->canAdministerWorkspace();
    }

    /**
     * Administrators may not delete themselves; that would be an easy way to
     * leave the workspace with no administrator at all.
     */
    public function delete(User $user, User $model): Response
    {
        if (! $user->canAdministerWorkspace()) {
            return Response::deny('Only administrators can remove users.');
        }

        if ($user->is($model)) {
            return Response::deny('You cannot delete your own account.');
        }

        return Response::allow();
    }

    /**
     * Changing a role is separated from update() because it is the privilege
     * escalation path and deserves its own gate.
     */
    public function updateRole(User $user, User $model): Response
    {
        if (! $user->canAdministerWorkspace()) {
            return Response::deny('Only administrators can change roles.');
        }

        if ($user->is($model)) {
            return Response::deny('You cannot change your own role.');
        }

        return Response::allow();
    }
}
