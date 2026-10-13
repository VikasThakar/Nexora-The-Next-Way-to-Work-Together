# Authentication and Security

## Authentication

Nexora uses Laravel's standard session-based authentication, with the screens rebuilt as
Livewire components rather than taken from a starter kit.

| Feature | Route | Component | Status |
|---|---|---|---|
| Login | `GET /login` | `Livewire\Auth\Login` | Implemented |
| Logout | `POST /logout` | `Auth\LogoutController` | Implemented |
| Registration | `GET /register` | `Livewire\Auth\Register` | **Implemented but disabled by default** |
| Forgotten password | `GET /forgot-password` | `Livewire\Auth\ForgotPassword` | Implemented |
| Password reset | `GET /reset-password/{token}` | `Livewire\Auth\ResetPassword` | Implemented |
| Password confirmation | `GET /confirm-password` | `Livewire\Auth\ConfirmPassword` | Implemented |
| Email verification | — | — | **Not implemented** (column exists, no flow) |
| Two-factor authentication | — | — | **Not implemented** |
| SSO / SAML (signing in to Nexora with another identity provider) | — | — | **Not implemented** |
| OAuth authorization server, for AI clients | `GET /oauth/authorize` | Laravel Passport + `resources/views/mcp/authorize.blade.php` | **Implemented** — used only by the remote MCP server; see below |

### Registration is off by default

The `/register` route is always registered so that links and tests resolve, but the
component **returns 404 unless `ALLOW_PUBLIC_REGISTRATION=true`**. This is an internal tool
shared with named customers, so administrators create accounts. When registration is
enabled, a self-registered account receives `UserRole::Customer` — the least-privileged
role — so a self-registered account can never reach internal content.

### Password policy

Defined once in `AppServiceProvider::configurePasswords()`:

| Environment | Rule |
|---|---|
| All environments | At least **12 characters**, containing letters and numbers |
| Production only | Additionally **mixed case** and **checked against known breach corpora** (`uncompromised()`) |

Passwords are hashed with bcrypt at `BCRYPT_ROUNDS=12` (the test suite drops to 4 for
speed). The `password` attribute is cast `hashed`, so a plaintext value assigned to it is
hashed on write and can never be stored in the clear.

### What the login flow does

1. Validates the submitted credentials.
2. Checks the throttle (see *Rate limiting* below) and refuses early if it is tripped.
3. Attempts authentication with a lower-cased email.
4. **Refuses a deactivated account even with correct credentials** — the session is
   established, then immediately invalidated, so a disabled account cannot get in.
5. Clears the throttle counter.
6. **Rotates the session id**, to defeat session fixation.
7. Redirects to the intended URL, or to the dashboard.

### Session handling

| Control | Value | Effect |
|---|---|---|
| Driver | `database` | Sessions survive a container restart and are shared across replicas |
| Lifetime | 120 minutes | Idle timeout |
| `http_only` | `true` | The cookie is unreadable from JavaScript |
| `same_site` | `lax` | Cross-site POSTs do not carry the session |
| `secure` | `SESSION_SECURE_COOKIE` | **Must be set to `true` in production** |
| `encrypt` | `false` by default | Session payloads are stored unencrypted in the database |
| `AuthenticateSession` | Enabled globally | The session is bound to the password hash it was created with, so **changing a password invalidates every other session** |
| `EnsureUserIsActive` | Enabled globally | A deactivated account is logged out on its very next request, not at next login |

> [!WARNING]
> `SESSION_SECURE_COOKIE=false` is the shipped default in `.env.example`, which is correct
> for local HTTP development and **wrong for production**. Set it to `true` for any
> deployment served over HTTPS.

### Re-authentication for administration

Three route groups additionally require `password.confirm`, Laravel's re-authentication
middleware. Reaching them asks for the password again even inside a valid session:

- `/admin/ai` — global AI settings, which hold provider credentials
- `/admin/users` — user administration, which is the privilege-escalation path
- `/boards/create` and `/boards/{board}/edit` — board creation and identity

### OAuth for the remote MCP server

Nexora is an OAuth 2.1 **authorization server** for one purpose: letting a person connect an
AI client (Claude, ChatGPT) to the read-only MCP endpoint at `/mcp`. It is not a way to sign
in to Nexora — that is still the login form above — and a token grants nothing beyond the
MCP tools, which read with the token's user as the viewer.

| Property | Value |
|---|---|
| Implementation | Laravel Passport, discovery and registration routes from laravel/mcp |
| Client registration | Dynamic (RFC 7591), public clients; redirect URIs only on `MCP_REDIRECT_DOMAINS` (Claude, ChatGPT, loopback) |
| Grant | Authorization code with PKCE `S256` only; the device-code grant is switched off |
| Scope | `mcp:use`, required by `/mcp` and granted by default |
| Access token | 60 minutes (`MCP_ACCESS_TOKEN_MINUTES`) |
| Refresh token | 30 days (`MCP_REFRESH_TOKEN_DAYS`), rotated on every use |
| Consent | `resources/views/mcp/authorize.blade.php`: names the client and the host it returns to; warns on loopback redirects |
| Revocation | Access, refresh and auth codes revoked when the user is deactivated or their password changes (`App\Observers\UserObserver`); a deactivated user's request is a `401` |
| Keys | `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`; rotating them disconnects every client |

## Authorization

Authorization is applied in four independent places, and they are designed to overlap.

```diagram
   1. ROUTE MIDDLEWARE      role:admin,team          coarse; blocks before a component exists
                            password.confirm         re-authentication for administration

   2. QUERY SCOPES          Ticket::visibleTo()      the PRIMARY defence: filters in SQL
                            Comment::visibleTo()     protects lists, search, counts, links
                            AiRun::visibleTo()
                            DocPageFinder            adds the ancestor rule for the page tree

   3. POLICIES              TicketPolicy::view()     single loaded record; mirrors the scope
                            BoardPolicy::useAiChat()
                            DocPagePolicy::update()

   4. ACTIONS               CreateTicket             field-level rules that hold whatever
                            UpdateTicket             screen, console command or AI proposal
                            PostComment              calls them
                            SetPageVisibility
```

Eight policies are registered: `BoardPolicy`, `UserPolicy`, `TicketPolicy`, `CommentPolicy`,
`DocPagePolicy`, `AttachmentPolicy`, `AiRunPolicy` and `AiSessionPolicy`.

### Attachments inherit visibility rather than storing it

`AttachmentPolicy` holds no rules of its own. It resolves the owning record from an
explicit allow-list (`Ticket`, `Comment`, `DocPage`, `AiSession`) and asks *that* record's
policy. A file on an internal ticket is internal because the ticket is; a file on an
unpublished page is internal because the page is. There is no second flag to keep in sync,
because there is no flag at all. An owner type nobody has registered is **denied**, not
served.

<!-- pagebreak -->

## CSRF protection

CSRF validation is on for the whole `web` group, with exactly one exemption:

```php
$middleware->validateCsrfTokens(except: [
    'webhooks/github',
]);
```

The exemption is necessary — GitHub never rendered one of our forms and has no session — and
it is replaced by something stronger. An HMAC signature proves the request came from
somebody holding the shared secret, whereas CSRF only proves a request came from our own
page.

## Webhook signature verification

`App\Services\GitHub\WebhookSignature` is the entire authentication story for the inbound
endpoint. Four properties, each of which has been a real vulnerability in other systems:

| Property | Implementation |
|---|---|
| **Fail closed with no secret** | With `GITHUB_WEBHOOK_SECRET` unset, *every* delivery is rejected. There is no "skip verification when unconfigured" path |
| **Verify the raw body** | `$request->getContent()`, never the parsed or re-encoded input — key order, unicode escaping and float formatting all differ after a round trip |
| **Constant-time comparison** | `hash_equals()`, never `===`, so the number of correct leading bytes cannot be walked out through timing |
| **SHA-256 only** | The legacy SHA-1 `X-Hub-Signature` header is ignored. Accepting it would make the endpoint's real strength SHA-1's, because the attacker picks the header |

Failures are deliberately indistinguishable: a missing secret, a missing header and a wrong
digest all return the same `401 Invalid signature.`

## Rate limiting

Four named limiters, protecting different things.

| Limiter | Applies to | Keyed on | Default | Why |
|---|---|---|---|---|
| `github-webhooks` | `POST /webhooks/github` | Client IP | 300/min (`GITHUB_WEBHOOK_RATE_LIMIT`) | The only unauthenticated route that can enqueue work. Rejected requests never reach signature verification and never touch the database |
| `ai-voice` | `POST /ai/voice/listen`, `GET /ai/messages/{id}/speech` | Signed-in user id | 30/min (`AI_VOICE_RATE_LIMIT`) | Transcription and speech synthesis are billed per call and are the only AI surface a browser can trigger in a loop without a person typing |
| `mcp` | `POST /mcp` | Token's user id | 60/min (`MCP_RATE_LIMIT`) | One connected AI client looping on a tool slows down that connection only |
| `mcp-oauth` | OAuth discovery and `POST /oauth/register` | Client IP | 120/min (`MCP_OAUTH_RATE_LIMIT`) | Unauthenticated by design; generous because every Claude or ChatGPT user arrives from the same vendor addresses |

The login form carries a third limiter of its own, inside `Livewire\Auth\Login`:

| Control | Value |
|---|---|
| Maximum failed attempts | 5 |
| Throttle key | `strtolower(email) + "|" + client IP` |
| On success | The counter is cleared |
| On lockout | A validation error naming the remaining seconds |

The composite key is deliberate: keying on the email alone would let one attacker lock every
account out from a single address, and keying on the IP alone would not stop one address
brute-forcing one account.

> [!NOTE]
> There is no rate limit on the assistant's **text** endpoint. It is authenticated,
> costed against a per-session and per-user daily token budget, and bounded by the
> capability mode — but a distinct request limiter would still be worth adding. See
> section 32.

## Input validation and output sanitisation

Every piece of user-written prose passes through one pipeline, `App\Services\ContentRenderer`:

1. Markdown to HTML with **raw HTML stripped** and unsafe link schemes rejected
   (`App\Support\Markdown`).
2. `@mentions` of board members highlighted.
3. Ticket references such as `AQD-142` linked — **but only for tickets the particular reader
   may open**.

Steps 2 and 3 are viewer-dependent, so nothing in this pipeline may be cached across
viewers.

The rich-text editor adds a second, stricter layer. `App\Support\RichText\EditorHtml` is an
**allow-list** applied to HTML travelling in *both* directions:

- *browser to server* — the posted document is attacker-controlled, so it is cleaned before
  the Markdown converter sees it. Nothing hostile is stored, not even as inert text that
  would still surface in search results, board-card excerpts and AI prompts.
- *server to browser* — the stored Markdown is rendered and cleaned again, which is about
  shape rather than safety: it drops syntax-highlighting spans the editor must not
  serialise back, and rewrites task lists into the form TipTap recognises.

An allow-list is used rather than a deny-list because a deny-list has to anticipate
`onpointerrawupdate`, `xlink:href` and whatever parser-confusion trick lands in browsers
next year, whereas an allow-list only has to know the dozen elements this product's
Markdown can express.

### Model output is never rendered directly

AI-generated charts, tables and KPI rows are validated into typed value objects
(`ChartSpec`, `TableSpec`, `KpiSpec`) before anything is drawn. The model supplies numbers
and labels; the server draws the SVG. Consequently:

- there is no charting library receiving model-authored configuration, so there is no
  option in such a library that turns a string into a callback;
- nothing model-authored reaches a `<script>`, an event handler, a style attribute or a URL;
- colours come from a fixed palette chosen by index, never supplied;
- labels are escaped as text nodes by Blade.

A chart with no valid numbers, or a table with no columns, falls back to rendering the
model's text as prose. An answer never becomes an error message because its illustration
was malformed.

<!-- pagebreak -->

## Secret handling

> [!IMPORTANT]
> No credential in this system is ever rendered back to a browser, written to a log line,
> or included in an exception message. This is enforced by construction, and there is a
> dedicated test — `tests/Security/SecretExposureTest.php` — that asserts it.

| Secret | Stored | Read by | Write-only in the UI? |
|---|---|---|---|
| `APP_KEY` | Environment | Framework | n/a |
| Anthropic API key | Environment, **or** encrypted in `ai_credentials`, **or** encrypted per board | `AiCredentialVault` only | Yes — fields blank on load, blanked after save |
| OpenAI API key | Same three places | `AiCredentialVault` only | Yes |
| GitHub token | Environment (`GITHUB_TOKEN`) | `config('github.token')` only | n/a — environment only |
| GitHub webhook secret | Environment | `WebhookSignature` only | n/a |
| Slack webhook URL | **Encrypted** in `boards.settings.slack` | `BoardSlackSettings` | Yes — replaced, never read back |
| 46elks credentials | Environment | `ElksSmsProvider` only | n/a |

Three design decisions are worth stating explicitly.

**The Slack webhook URL is encrypted at rest.** A Slack incoming-webhook URL is a bearer
token wearing a URL's clothes — anyone holding it can post into that channel as the app,
for ever, with no further authentication, and Slack has no read-only variant. Storing it as
plain text in a JSON column would put it in every database dump and backup.

**Credential screens are write-only.** The global AI settings screen and the board
integrations screen render *presence* and *the last four characters*, never the value. The
vault has no method that would return a key to a component. Somebody who gains access to
those screens can **replace** a credential — which is visible in `rotated_at` and
`updated_by_id` — but cannot read one out.

**Decryption failure degrades to "not configured".** Rotating `APP_KEY` makes stored
credentials unreadable. That is the correct failure — the alternative is a key rotation
that leaves credentials readable — and it is handled by reading as *absent* rather than by
throwing, so a board with an unreadable webhook stops posting instead of breaking every
ticket save.

## The GitHub token is deliberately weak

The fine-grained token needs exactly two permissions on the repositories in scope:

| Permission | Why |
|---|---|
| `contents: write` | Push a branch |
| `pull_requests: write` | Open a pull request |

It is **never given merge rights**. `PullRequestClient` has no merge method, no
branch-delete method and no review-approval method — so apply mode could not merge even if
the token allowed it. A future phase that genuinely needs to merge has to add the
capability and be reviewed for it.

## File upload security

| Control | Ticket / comment / page attachments | AI conversation attachments |
|---|---|---|
| Size ceiling | 10 MB (`ATTACHMENT_MAX_SIZE_KB=10240`) | 20 MB (`AI_ATTACHMENT_MAX_SIZE_KB=20480`) |
| Count ceiling | 20 per owning record | 10 per conversation |
| Type checking | Extension allow-list | **Extension allow-list *and* detected MIME type** — both must pass |
| Stored path | Generated, never derived from the uploaded filename | Same |
| Original filename | Kept only as a display label | Same |
| `svg` allowed? | Yes — only ever served as a download | **No** — an "image" here is decoded and sent to a vision model |
| `zip` allowed? | Yes | **No** — a container whose contents nothing has validated |
| Images before sending to a model | n/a | **Re-encoded with GD** — strips EXIF (including GPS), strips anything appended after the image data, and makes the declared media type true by construction |

Downloads never link straight at stored bytes. `AttachmentController` authorizes the request
against the owning record first, then either redirects to a **short-lived signed URL** (S3
disks, default 5 minutes via `ATTACHMENT_URL_TTL_MINUTES`) or streams the file (local disk,
development only).

## Storage durability is enforced at boot

`App\Providers\AppServiceProvider` refuses to boot a **production** application whose
attachment disk is not in `config('attachments.durable_disks')` — currently `s3` and
`volume`. The two are checked differently:

- `s3` is durable by construction; there is nothing local to verify.
- `volume` is durable only while a persistent volume is actually mounted at its root, so the
  check **stats the root and refuses to boot when the mount is absent**.

Without this, a forgotten volume would write into the container and lose every file at the
next deploy, silently.

## HTTP security headers

Set by nginx on every application response:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `server_tokens` | `off` — nginx does not advertise its version |

Dotfiles other than `/.well-known` are denied. `client_max_body_size` is 32 MB.

> [!WARNING]
> **There is no Content-Security-Policy header and no Strict-Transport-Security header.**
> Both are worth adding. HSTS in particular is cheap on a platform that already terminates
> TLS. See section 32.

## Transport security

`URL::forceScheme('https')` is applied in production, or whenever `APP_URL` starts with
`https://`. Without it, generated URLs — password-reset links in particular — would be
`http://`, because Railway terminates TLS at the edge and proxies over HTTP.
`trustProxies(at: '*')` is what lets Laravel see the real scheme and the real client IP for
rate limiting.

## Realtime authorization

There is **no public channel and no presence channel**. Every channel is private, and
joining one is a request Laravel authorizes per user before the WebSocket server will
deliver anything.

| Channel | Who may join |
|---|---|
| `board.{id}.internal` | Staff members of that board — `canView()` **and** `canSeeInternalContent()` |
| `board.{id}.customer` | Anyone who may view the board, customers included |
| `users.{id}` | That user, and only while active |

The split is the point: because `BoardUpdated` only signals the internal channel when an
*internal* ticket changes, a customer's browser is never even woken by activity they are
not allowed to know about. That closes the timing side-channel a single shared channel
would leave open even with an empty payload.

<!-- pagebreak -->
