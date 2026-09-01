<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
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
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
