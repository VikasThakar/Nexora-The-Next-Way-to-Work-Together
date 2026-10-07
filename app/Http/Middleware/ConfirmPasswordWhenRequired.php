<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `password.confirm`, behind a deployment switch.
 *
 * This is registered under the `password.confirm` alias itself, replacing the
 * framework's. That is deliberate: the route definitions still read
 * `->middleware(['role:admin', 'password.confirm'])`, so somebody reading
 * routes/web.php sees the control exactly where it has always been, and there
 * is one place — here — that decides whether it runs.
 *
 * It is off by default. See config/workspace.php for the reasoning; the short
 * version is that the delivery team administers boards and accounts as part of
 * ordinary work, and re-prompting on every visit is friction paid many times a
 * day against a threat it only partly addresses.
 *
 * Two properties worth being explicit about:
 *
 *   This is not an authorization decision. It never grants anything. The role
 *   middleware has already blocked the request if this person is not an
 *   administrator, and every policy and gate behind it is untouched. All this
 *   decides is whether somebody who is already authorized is asked to prove it
 *   is still them.
 *
 *   The decision is made per request, not per route definition. Reading the
 *   config while building the route group would bake the answer into
 *   `route:cache`, so a deployment that turned this on would appear to have
 *   changed nothing until the cache was rebuilt — the kind of configuration
 *   that silently does not apply.
 *
 * The parameters the framework's middleware accepts (a redirect route and a
 * timeout) are passed straight through, so re-enabling this loses none of the
 * tuning that was always available.
 */
class ConfirmPasswordWhenRequired
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, ?string $passwordTimeoutSeconds = null): Response
    {
        if (! config('workspace.require_password_confirmation', false)) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle(
            $request,
            $next,
            $redirectToRoute,
            $passwordTimeoutSeconds,
        );
    }
}
