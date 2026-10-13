# HTTP Endpoints and Webhooks

> [!IMPORTANT]
> **Nexora exposes no general REST or JSON API for third parties.** There is no
> `routes/api.php` and no OpenAPI specification. The one machine-facing interface is the
> **remote MCP server** at `POST /mcp`: AI clients such as Claude and ChatGPT call it with an
> OAuth token on behalf of one signed-in person, read-only, under that person's in-app
> permissions. Everything else below is a browser route, an inbound webhook, or a small number
> of controller endpoints the application's own front end calls.

## Remote MCP server — `POST /mcp`

Defined in `routes/ai.php`, which laravel/mcp loads **outside** the `web` group: no session,
no CSRF, a bearer token on every request. `MCP_SERVER_ENABLED=false` removes every route in
this section.

| Route | Purpose | Authentication | Limit |
|---|---|---|---|
| `POST /mcp` | MCP JSON-RPC: `initialize`, `tools/list`, `tools/call` | Passport token (`auth:api`), scope `mcp:use`, active user | `throttle:mcp` — per person |
| `GET` / `DELETE /mcp` | Always `405` (no server-initiated stream) | — | — |
| `GET /.well-known/oauth-protected-resource[/mcp]` | RFC 9728 metadata: `resource` is exactly `https://<host>/mcp` | Public | `throttle:mcp-oauth` — per IP |
| `GET /.well-known/oauth-authorization-server[/…]` | RFC 8414 metadata: endpoints, S256 PKCE, scope | Public | `throttle:mcp-oauth` |
| `POST /oauth/register` | Dynamic client registration (RFC 7591), public clients only, redirect URIs limited to `config('mcp.redirect_domains')` | Public | `throttle:mcp-oauth` |
| `GET /oauth/authorize` | Nexora's login if needed, then the consent screen (`resources/views/mcp/authorize.blade.php`) | Web session | — |
| `POST` / `DELETE /oauth/authorize` | Approve / deny | Web session + CSRF | — |
| `POST /oauth/token` | Code exchange (PKCE S256) and refresh-token rotation | Client | Passport's own |

**The handshake.** An unauthenticated `POST /mcp` is always a JSON `401` with
`WWW-Authenticate: Bearer realm="mcp", resource_metadata="…/.well-known/oauth-protected-resource/mcp"`,
whatever the client sends in `Accept` — `bootstrap/app.php` forces JSON rendering for these
paths, because a redirect to the login page here is what makes a client report that it
"couldn't reach the server".

**The tools** are the in-app assistant's read tools, through `AiToolRegistry::invoke()` — see
section 9 for what each reads and who gets it. Every call writes an `ai_tool_invocations` row
with `channel = mcp`.

## Inbound webhook — GitHub

### `POST /webhooks/github`

| Property | Value |
|---|---|
| Authentication | **HMAC-SHA256** over the raw body, in `X-Hub-Signature-256` |
| CSRF | Exempt — the only exempt path in the application |
| Rate limit | `throttle:github-webhooks` — 300/min per IP, applied **before** anything else runs |
| Content type | `application/json` |
| Purpose | Link branches, commits, pull requests and CI results to tickets |

**Required headers**

| Header | Purpose |
|---|---|
| `X-Hub-Signature-256` | `sha256=<hex digest>`. **Required** |
| `X-GitHub-Event` | Event name |
| `X-GitHub-Delivery` | Delivery id, used for de-duplication |

**Events acted on:** `push`, `create`, `pull_request`, `check_suite`, `status`. `ping` is
answered 200. Anything else is acknowledged and ignored.

**Responses**

| Status | Body | Meaning |
|---|---|---|
| `200` | `Webhook configured.` | A `ping` |
| `200` | `Already received.` | A replay — the original delivery and its links stand |
| `202` | `Accepted.` | Verified, recorded, queued |
| `202` | `Event ignored.` | Not one of the five |
| `202` | `Malformed payload.` | Body was not valid JSON |
| `401` | `Invalid signature.` | **Identical** whether the secret is unset, the header is missing or the digest is wrong |
| `429` | — | Rate limited |

> [!NOTE]
> Everything that is not a signature failure answers **2xx**, including events we ignore and
> payloads we cannot parse. GitHub retries a non-2xx and shows a red cross in the repository
> settings, and neither helps for a delivery that will never succeed. A bad signature is the
> one case that genuinely is the caller's fault.
>
> The controller does **no parsing beyond reading two fields, no ticket lookup and no writing
> to ticket history.** That is partly latency — GitHub disables an endpoint that repeatedly
> takes more than ten seconds — and partly blast radius: the less this method does with
> unverified-shaped input, the less there is to get wrong in the request a stranger can make.

**Setting it up**

1. Repository → Settings → Webhooks → Add webhook.
2. Payload URL: `https://<APP_URL>/webhooks/github`
3. Content type: `application/json`
4. Secret: the value of `GITHUB_WEBHOOK_SECRET`
5. Events: pushes, branch/tag creation, pull requests, check suites, statuses.

## Health endpoints

| Route | Auth | Returns |
|---|---|---|
| `GET /up` | None | Framework probe. Used by the Docker `HEALTHCHECK` |
| `GET /health` | None | `{ database: bool, cache: bool, migrations_pending: bool, timestamp }` |

Deliberately returns booleans and a timestamp only — no hostnames, no versions, no driver
names, no error text.

## Application endpoints

These are called by Nexora's own front end. All require an authenticated session.

### `GET /attachments/{attachment}`

Authorized through `AttachmentPolicy`, which delegates to the owning record's policy.
Redirects to a signed URL (S3 disks, 5-minute TTL) or streams the file (local disk). Denials
are 404.

### `GET /stats/export?dataset=…&board=…&range=…&from=…&to=…`

Team CSV. `role:admin,team`, re-checked in the controller. `dataset` is an **allow-list** —
anything not in `KEYS` is refused rather than reflected.

### `GET /stats/customer/export`

Customer CSV. Open to any authenticated user, because it can only reach
`CustomerStatisticsExport` → `CustomerStatistics`, neither of which can see a team figure.

### `GET /ai/messages/{message}/blocks/{block}/export.csv`

Exports one table or chart out of an assistant answer. Both parameters are constrained to
digits, so a malformed URL is a router 404 rather than a cast to zero.

Four authorization filters: the route group's `role:admin,team`; `visibleTo`; `ownedBy` (**an
administrator cannot export a colleague's conversation**); and a non-null session id.

### `POST /ai/voice/listen`

A recording in, the transcript out. `throttle:ai-voice` (30/min per user). Allowed to
**anyone eligible for the assistant panel, customers included** — transcribing reads nothing
from the workspace.

### `GET /ai/messages/{message}/speech`

A stored assistant turn, as audio. `throttle:ai-voice`. Resolved through `visibleTo` **and**
`ownedBy`. A `GET` so an `<audio>` element can play it directly, and addressed by message id
rather than by posting text — what is read aloud is what is stored.

### `PUT /settings/theme`

Persists the appearance preference. Validated against `ThemePreference`. `PUT` because it
replaces one value on the signed-in person's own row; there is no user id in the payload.

### `POST /logout`

### `POST /broadcasting/auth`

Registered by `withBroadcasting()`, behind the `web` middleware. Authorizes a channel
subscription with the caller's session, decided by `routes/channels.php`. **A browser cannot
join a channel merely by knowing its name.**

## Browser routes

| Route | Component | Gate |
|---|---|---|
| `GET /` | redirect to `/dashboard` | — |
| `GET /login` | `Auth\Login` | guest |
| `GET /register` | `Auth\Register` | guest; **404 unless `ALLOW_PUBLIC_REGISTRATION`** |
| `GET /forgot-password` | `Auth\ForgotPassword` | guest |
| `GET /reset-password/{token}` | `Auth\ResetPassword` | guest |
| `GET /confirm-password` | `Auth\ConfirmPassword` | auth |
| `GET /dashboard` | `Dashboard\Index` | auth |
| `GET /boards` | `Boards\Index` | auth |
| `GET /boards/create` | `Boards\ManageBoard` | `role:admin` + confirm |
| `GET /boards/{board}` | `Boards\Show` | auth + `BoardPolicy::view` |
| `GET /boards/{board}/edit` | `Boards\ManageBoard` | `role:admin` + confirm |
| `GET /boards/{board}/settings` | `Boards\Settings` | auth + `manageColumns` |
| `GET /boards/{board}/ai` | `Ai\Chat` | `role:admin,team` + `useAiChat` |
| `GET /boards/{board}/ai/settings` | `Boards\AiSettings` | `role:admin,team` + `manageAiSettings` |
| `GET /boards/{board}/integrations` | `Boards\Integrations` | `role:admin,team` + `manageAiSettings` |
| `GET /boards/{board}/tickets/create` | `Tickets\Create` | auth + `TicketPolicy::create` |
| `GET /boards/{board}/tickets/{number}` | `Tickets\Show` | auth + `TicketFinder` |
| `GET /boards/{board}/docs` | `Docs\Show` | auth + `DocPagePolicy::viewAny` |
| `GET /boards/{board}/docs/{slug}` | `Docs\Show` | auth + `DocPageFinder` |
| `GET /activity` | `Activity\Index` | `role:admin,team` |
| `GET /stats` | `Stats\Team` | `role:admin,team` |
| `GET /stats/customer` | `Stats\Customer` | auth |
| `GET /settings` | `Settings\Index` | auth |
| `GET /settings/profile` | `Profile\UpdateProfile` | auth |
| `GET /admin/ai` | `Ai\GlobalSettings` | `role:admin` + confirm |
| `GET /admin/users` | `Users\Index` | `role:admin` + confirm |
| `GET /admin/users/create` | `Users\ManageUser` | `role:admin` + confirm |
| `GET /admin/users/{user}/edit` | `Users\ManageUser` | `role:admin` + confirm |

Route ordering is deliberate: literal segments such as `/boards/create`, `/boards/{board}/ai`
and `/boards/{board}/settings` are declared **before** `/boards/{board}`, so a literal is
never treated as a slug.

## Outbound HTTP

| Target | Client | Purpose | Timeout |
|---|---|---|---|
| Anthropic | `anthropic-ai/sdk` via `ClaudeService` | Chat, analysis | 300s |
| OpenAI `/chat/completions` | `Http` via `OpenAiService` | Chat | 300s |
| OpenAI audio | `OpenAiVoiceProvider`, `AudioTranscriber` | Transcription, speech | 120s / 180s |
| GitHub `POST /repos/{owner}/{repo}/pulls` | `PullRequestClient` | Open a **draft** pull request | 60s |
| Slack incoming webhook | `SlackWebhookChannel` | Board notifications | 8s |
| 46elks `POST /a1/sms` | `ElksSmsProvider` | SMS alerts | 15s |

<!-- pagebreak -->
