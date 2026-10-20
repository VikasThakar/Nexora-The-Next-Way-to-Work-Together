<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Where an OAuth client registered through dynamic client registration
    | (POST /oauth/register) may send the authorization code. The package
    | default is "*", which would let any website register itself and ask a
    | Nexora user to approve it. This list is the AI clients we support:
    |
    |   https://claude.ai     Claude on the web, desktop and phone apps
    |                         (callback https://claude.ai/api/mcp/auth_callback)
    |   https://claude.com    Anthropic's second callback domain
    |   https://chatgpt.com   ChatGPT (https://chatgpt.com/connector/oauth/…)
    |   http://localhost      loopback redirects of native clients (Claude
    |                         Code, MCP Inspector) on any port — a code sent
    |                         there can only reach the user's own machine
    |
    | Comma-separated in MCP_REDIRECT_DOMAINS to override.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'MCP_REDIRECT_DOMAINS',
            'https://claude.ai,https://claude.com,https://chatgpt.com,http://localhost'
        )),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. None are allowed until somebody needs one.
    |
    */

    'custom_schemes' => [],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | The OAuth issuer identifier (RFC 8414) advertised in the discovery
    | metadata. Null means url('/'), which is right while Passport runs inside
    | this application.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | The package's limits for tool search requests. Unused by Nexora's server,
    | kept at the package defaults.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nexora's remote MCP server
    |--------------------------------------------------------------------------
    |
    | The endpoint at /mcp that Claude and ChatGPT connect to: reads, and the
    | changes the signed-in person may make. See routes/ai.php and
    | App\Mcp\Servers\NexoraServer.
    |
    |   enabled               false removes /mcp and the OAuth discovery and
    |                         registration routes entirely
    |   rate_limit            tool calls per minute, per user
    |   oauth_rate_limit      discovery / registration requests per minute,
    |                         per IP — generous, because every Claude or
    |                         ChatGPT user arrives from the same few addresses
    |   access_token_minutes  how long one access token lasts; clients refresh
    |                         it silently
    |   refresh_token_days    how long a connection survives without use before
    |                         the person has to sign in again
    |   writes_enabled        false takes every write tool away (back to
    |                         read-only) without a deploy
    |   write_rate_limit      changes per minute, per user, on top of
    |                         rate_limit — a runaway client stops here
    |
    */

    'server' => [
        'enabled' => (bool) env('MCP_SERVER_ENABLED', true),
        'rate_limit' => (int) env('MCP_RATE_LIMIT', 60),
        'oauth_rate_limit' => (int) env('MCP_OAUTH_RATE_LIMIT', 120),
        'access_token_minutes' => (int) env('MCP_ACCESS_TOKEN_MINUTES', 60),
        'refresh_token_days' => (int) env('MCP_REFRESH_TOKEN_DAYS', 30),
        'writes_enabled' => (bool) env('MCP_WRITES_ENABLED', true),
        'write_rate_limit' => (int) env('MCP_WRITE_RATE_LIMIT', 30),
    ],

];
