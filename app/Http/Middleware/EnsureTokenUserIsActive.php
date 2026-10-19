<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Users\RevokeOAuthAccess;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a bearer token whose user has been deactivated.
 *
 * The token-route counterpart of EnsureUserIsActive, which cannot be reused
 * here: it logs out of the session guard and invalidates the session, and the
 * MCP endpoint has no session — calling it would turn a deactivated user's
 * request into a server error rather than a refusal.
 *
 * Deactivated users could not read anything anyway (BoardAccess answers
 * nothing for them). This exists so the answer is a clean 401 — which the MCP
 * client reads as "sign in again" — and so the tokens that got this far are
 * revoked rather than left to keep trying.
 */
class EnsureTokenUserIsActive
{
    public function __construct(private readonly RevokeOAuthAccess $revoke) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isActive()) {
            return $next($request);
        }

        if ($user instanceof User) {
            $this->revoke->handle($user);
        }

        throw new AuthenticationException('This account cannot be used here.', ['api']);
    }
}
