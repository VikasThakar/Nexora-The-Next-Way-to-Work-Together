<?php

use App\Http\Middleware\EnsureTokenUserIsActive;
use App\Mcp\Servers\NexoraServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
|--------------------------------------------------------------------------
| Remote MCP server
|--------------------------------------------------------------------------
|
| What Claude and ChatGPT connect to when someone adds Nexora as a connector:
| https://<host>/mcp. Loaded by laravel/mcp outside the `web` group, so these
| routes have no session and no CSRF — every request carries a bearer token.
|
| The first connection runs the OAuth dance the clients expect:
|
|   POST /mcp without a token        → 401 + WWW-Authenticate pointing at
|                                      /.well-known/oauth-protected-resource/mcp
|   GET  /.well-known/…              → where to authorize, and how
|   POST /oauth/register             → the client registers itself (DCR), with a
|                                      redirect on an allowed domain only
|                                      (config/mcp.php `redirect_domains`)
|   GET  /oauth/authorize            → Nexora's login page, then the consent
|                                      screen (resources/views/mcp/authorize)
|   POST /oauth/token                → access + refresh token for that person
|
| After that every tool call is a POST /mcp as that person. See
| App\Mcp\Servers\NexoraServer for what the tools can and cannot see.
|
*/

if (! config('mcp.server.enabled')) {
    return;
}

Route::middleware('throttle:mcp-oauth')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', NexoraServer::class)
    ->name('mcp')
    ->middleware([
        'auth:api',
        CheckToken::using(Registrar::OAUTH_SCOPE),
        EnsureTokenUserIsActive::class,
        'throttle:mcp',
    ]);
