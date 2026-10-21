<?php

/*
|--------------------------------------------------------------------------
| Gritch integration
|--------------------------------------------------------------------------
|
| Gritch's admin dashboard (admin.getgritch.com) shows one Nexora board inside
| an iframe, and its admin AI reads and changes tickets on that board. Each
| Gritch admin gets their own Nexora account — role `team`, a member of that
| one board and nothing else — and never types a Nexora password: Gritch's
| server vouches for them with a short-lived assertion signed with the shared
| secret below. See App\Services\Gritch\GritchAssertion and
| docs/src/115-gritch-integration.md.
|
| Off unless every required value is present. A half-configured integration
| answers 404 everywhere rather than accepting anything.
|
*/

return [

    'enabled' => (bool) env('GRITCH_INTEGRATION_ENABLED', false),

    /*
     * The HMAC key Gritch signs assertions with. At least 32 characters, and
     * the same value as NEXORA_INTEGRATION_SECRET on Gritch. Anybody holding
     * it can sign in as any Gritch admin's Nexora account, so it lives only in
     * the two deployments' environment — never in a repository or a chat.
     */
    'secret' => env('GRITCH_INTEGRATION_SECRET'),

    /*
     * The slug of the one board Gritch admins may reach. Their accounts are
     * members of this board only, re-checked every time Gritch signs them in.
     */
    'board' => env('GRITCH_BOARD'),

    /*
     * The host the iframe loads, e.g. nexora.getgritch.com.
     *
     * It must share a registrable domain with the Gritch admin dashboard, so
     * that Nexora's ordinary SameSite=Lax session cookie counts as same-site
     * inside the frame. On nexora.gritch.dev — a different site — browsers
     * would drop the cookie and every Livewire request would fail.
     *
     * The host serves the embed and nothing else: no password login, only the
     * Gritch board, and it is the only host that may be framed.
     */
    'embed_host' => env('GRITCH_EMBED_HOST'),

    /*
     * Who may frame the embed host (Content-Security-Policy frame-ancestors).
     * Comma-separated origins; anything that is not a plain scheme://host[:port]
     * is dropped.
     */
    'embed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GRITCH_EMBED_ORIGINS', 'https://admin.getgritch.com')),
    ))),

    /*
     * How long an embed session may last, however active, before Gritch has to
     * vouch for the person again. Gritch re-signs on every page load, so this
     * only ends a page left open all day.
     */
    'embed_max_minutes' => (int) env('GRITCH_EMBED_MAX_MINUTES', 480),

    /*
     * Lifetime of the MCP access tokens Gritch's admin AI receives. Short,
     * because Gritch asks for a new one whenever it needs one.
     */
    'token_minutes' => (int) env('GRITCH_TOKEN_MINUTES', 15),

    /*
     * Requests per minute per IP to the three integration endpoints.
     */
    'rate_limit' => (int) env('GRITCH_RATE_LIMIT', 120),

];
