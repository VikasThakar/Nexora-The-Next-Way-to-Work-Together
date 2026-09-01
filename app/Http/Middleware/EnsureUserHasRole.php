<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse-grained role gate for whole route groups.
 *
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,team')
 *
 * This is a first line of defence only. Anything that touches a specific
 * record must still be authorized by a policy — role membership alone never
 * proves that a user may see a particular board.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403);
        }

        $allowed = array_filter(array_map(
            fn (string $role): ?UserRole => UserRole::tryFrom($role),
            $roles
        ));

        if ($allowed === []) {
            // A typo in a route definition must fail closed, not open.
            abort(403);
        }

        if (! in_array($user->role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
