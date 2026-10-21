<?php

use App\Http\Middleware\ConfirmPasswordWhenRequired;
use App\Http\Middleware\EmbedScope;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\FrameOptions;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Framework probe. The richer application probe lives at /health.
        health: '/up',
    )
    /*
     * Realtime.
     *
     * Registers POST /broadcasting/auth behind the `web` middleware, so a
     * websocket subscription is authorized with the caller's session and then
     * decided by routes/channels.php. A browser cannot join a channel by
     * knowing its name.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (and any other PaaS) terminates TLS at the edge and proxies
        // the request over HTTP. Without this, Laravel would build http:// URLs
        // and would not see the real client IP for rate limiting.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            // Ties each session to the password hash it was created with, so
            // changing a password invalidates every other session.
            AuthenticateSession::class,

            // Runs on every web request so deactivating a user takes effect
            // immediately rather than at their next login.
            EnsureUserIsActive::class,

            // Keeps the Gritch embed host to the Gritch board. A no-op on
            // every other host. After EnsureUserIsActive, so a deactivated
            // account is signed out before this looks at it.
            EmbedScope::class,
        ]);

        // Laravel sorts `auth` ahead of the web group's own middleware, which
        // would send a signed-out frame to /login — a page the embed host does
        // not have — before EmbedScope could answer "session ended".
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EmbedScope::class);

        // Who may frame Nexora. Global so error pages carry the header too.
        $middleware->append(FrameOptions::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,

            /*
             * Replaces the framework's `password.confirm`.
             *
             * The alias is overridden rather than a new one introduced, so the
             * route definitions keep naming the control they have always named
             * and there is exactly one place that decides whether it runs. It
             * is off by default — see config/workspace.php and
             * ConfirmPasswordWhenRequired for why, and for what it does not
             * relax.
             */
            'password.confirm' => ConfirmPasswordWhenRequired::class,
        ]);

        /*
         * Inbound webhooks cannot carry a CSRF token: the caller never
         * rendered one of our forms and has no session with us.
         *
         * The exemption is scoped to the single path, and that path
         * authenticates every request with an HMAC of the raw body under a
         * shared secret (App\Services\GitHub\WebhookSignature) — which is a
         * stronger claim than CSRF makes, not a weaker one. CSRF proves a
         * request came from our own page; the signature proves it came from
         * someone holding the secret.
         */
        $middleware->validateCsrfTokens(except: [
            'webhooks/github',

            // The same reasoning for Gritch: its page posts the assertion into
            // the iframe from admin.getgritch.com, which never rendered one of
            // our forms. The assertion is single-use, expires within two
            // minutes and is signed with a shared secret — see
            // App\Services\Gritch\GritchAssertion.
            'integrations/gritch/*',
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * The remote MCP endpoint and its OAuth discovery and registration
         * routes are called by machines, and always answer in JSON.
         *
         * This matters most for the very first request a client makes: an
         * unauthenticated POST /mcp must come back as a 401 (which laravel/mcp
         * decorates with the WWW-Authenticate header that starts the OAuth
         * flow). Left to the default, a client that sends an unusual Accept
         * header would be redirected to the login page instead, and Claude or
         * ChatGPT would report that it could not reach the server.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('mcp', 'oauth/register', '.well-known/*')
                || $request->expectsJson()
        );
    })->create();
