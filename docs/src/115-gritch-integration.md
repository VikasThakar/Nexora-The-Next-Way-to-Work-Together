# Gritch Integration

Gritch's admin dashboard (`admin.getgritch.com`) has a **Nexora** page that shows the real
Nexora UI for one board, the Gritch board, inside an iframe. Gritch's admin AI can also read
and change tickets on that board. Gritch admins never type a Nexora password, and they can
reach nothing in Nexora except that board.

| Piece | Where |
|---|---|
| Settings | `config/gritch.php` (`GRITCH_*` in `.env.example`) |
| The three endpoints | `GritchIntegrationController`: `POST /integrations/gritch/{embed,token,revoke}` |
| The credential | `App\Services\Gritch\GritchAssertion` |
| The account | `App\Actions\Gritch\ProvisionGritchMember`, table `integration_identities` |
| The walls | `App\Http\Middleware\EmbedScope`, `FrameOptions`, `BoardPolicy::manageAiSettings` |
| Gritch's side | `gritch-backend`: `App\Services\Nexora\*`, `/admin/nexora`, `docs/nexora-integration.md` |

## How it works

```diagram
  Gritch admin's browser          Gritch (admin.getgritch.com)        Nexora
  ----------------------          ----------------------------        ------
  opens Admin → Nexora ─────────► BankID session, still a SaleSys
                                  admin? signs a 60-second assertion
                     ◄──────────  page with <iframe> + hidden form
  form POST into the frame ────────────────────────────────────────► nexora.getgritch.com
                                                                     /integrations/gritch/embed
                                                                     verify, provision, log in,
                                                                     new session, → Gritch board
  frame shows the board  ◄───────────────────────────────────────── (same-site cookie)

  asks the admin AI to ─────────► "create a ticket…" → approval card
  change a ticket                 Approve → assertion ──────────────► POST /integrations/gritch/token
                                                     ◄────────────── 15-minute MCP token ("Gritch")
                                  POST /mcp tools/call ─────────────► same tools as Claude/ChatGPT
                                                     ◄────────────── Nexora's own answer
```

### Why a second host

Nexora's session cookie is `SameSite=Lax`. Inside a frame on another site, browsers don't send
it. Safari and Firefox always block it, and Chrome increasingly does. Every Livewire request
would then fail with 419.

`nexora.getgritch.com` shares a registrable domain with `admin.getgritch.com`, so the cookie
counts as same-site there and works unchanged. Nothing about cookies changes for
`nexora.gritch.dev`.

The embed host serves the embed and nothing else:

- **Framing:** `Content-Security-Policy: frame-ancestors <GRITCH_EMBED_ORIGINS>` and no
  `X-Frame-Options`. Every other host still sends `X-Frame-Options: SAMEORIGIN`, which used to
  be set by nginx and is now set by `FrameOptions`.
- **No password login.** A request without an embed session gets the "session ended" page. That
  page `postMessage`s `{type: 'nexora:session-ended'}` to the allowed origins, and Gritch signs
  the admin in again.
- **Only the Gritch board's own screens:** board, tickets, docs, columns and labels, plus the
  endpoints they call (Livewire, realtime, attachments, avatars). Anything else redirects to
  the board.
- **Embed sessions expire.** They end after `GRITCH_EMBED_MAX_MINUTES` (8 h) however active, or
  as soon as the integration is switched off.

### The credential

A `GritchAssertion` is `base64url(claims) "." base64url(HMAC-SHA256(first part, secret))`.

| Claim | Meaning |
|---|---|
| `iss` / `aud` | `gritch` / `nexora` |
| `typ` | `embed`, `token` or `revoke`. An assertion works only for its own endpoint |
| `sub` | the admin's SaleSys user id (`[A-Za-z0-9_-]{1,64}`) |
| `name` | display name for the account. It decides nothing |
| `iat` / `exp` | unix seconds, at most 120 s apart, ±60 s clock skew allowed |
| `jti` | random. Each assertion is redeemed once (`Cache::add`) |

There is no session and no CSRF token on these paths (`integrations/gritch/*` is exempt); the
assertion replaces them. In the browser it travels only in a POST body, never in a URL. Both
repositories test the same fixed vector, so the two signers cannot drift apart.

### The account

There is one Nexora account per Gritch admin, found by `(gritch, SaleSys id)` in
`integration_identities` and **never by email**. A Gritch admin who edits an email address in
SaleSys therefore still reaches only their own account.

- **Role `team`**, member of the Gritch board only. Memberships added by hand are removed at the
  next sign-in. If someone changes the role, Gritch stops working for that person (fail closed);
  an admin role would otherwise see every board.
- **Email `salesys-<id>@gritch.invalid`.** The address can never receive mail and never collides
  with a real account. The password is random.
- **No other way in:** password login refuses these accounts, and password reset sends nothing.
- **No board settings.** `BoardPolicy::manageAiSettings` denies them, which covers the AI
  settings, repositories and Slack/SMS settings, and every Livewire action on those screens.
- **Names:** the account's name follows the Gritch name on every sign-in. History reads
  "Vikas Thakar via Gritch" for AI changes.

### The admin AI

`POST /integrations/gritch/token` issues a personal access token, scope `mcp:use`, lasting
`GRITCH_TOKEN_MINUTES` (15). It goes through the personal access client named **Gritch**, which
is created on first use. Passport always issues through the newest personal access client, so
the controller makes sure that client is Gritch.

Gritch calls `/mcp` with the token, so everything the MCP server does applies unchanged:

- business rules;
- confirmation codes for delete and for customer-visible comments;
- `RepeatGuard`;
- the write rate limit;
- one `ai_tool_invocations` row per call, `channel = mcp`, with "via Gritch" in the message.

Writes need the Gritch board's AI mode at Operator or above.

## Setting it up

1. **Domain.** Add `nexora.getgritch.com` as a custom domain on the Nexora web service in
   Railway, and a CNAME for it on getgritch.com's DNS (Gritch's owner).
2. **Secret.** Generate it once with `openssl rand -hex 32`. Set it as
   `GRITCH_INTEGRATION_SECRET` here and `NEXORA_INTEGRATION_SECRET` on Gritch. Share it through
   a password manager.
3. **Board.** Create or choose the board and set `GRITCH_BOARD=<slug>`. Its AI mode must be
   Operator or higher for the admin AI's changes.
4. **The rest:**
   - `GRITCH_EMBED_HOST=nexora.getgritch.com`
   - `GRITCH_EMBED_ORIGINS=https://admin.getgritch.com`
   - `GRITCH_INTEGRATION_ENABLED=true`
5. **Migrate.** Deploying runs the migration (`integration_identities`).
6. **Gritch:** set its `NEXORA_*` variables (see its `docs/nexora-integration.md`).

**Check:**

- `curl -I https://nexora.getgritch.com/up` shows `content-security-policy: frame-ancestors
  https://admin.getgritch.com`.
- `curl -I https://nexora.gritch.dev/login` still shows `x-frame-options: SAMEORIGIN`.

## Turning someone off

- **One person, at once:** deactivate their account in Nexora (Admin → Users; the name is
  theirs, the email `salesys-…@gritch.invalid`). Their sessions end on the next request and
  their tokens are revoked. Gritch shows "Your Nexora access has been turned off".
- **Losing admin rights in SaleSys:** Gritch stops vouching for them at the next page load or AI
  call. An open frame ends at the latest after `GRITCH_EMBED_MAX_MINUTES`.
- **Logging out of Gritch:** Gritch calls `POST /integrations/gritch/revoke`. All of that
  account's sessions are deleted and its tokens revoked.

## Rollback

- `GRITCH_INTEGRATION_ENABLED=false`:
  - every integration endpoint returns 404;
  - the embed host returns 404;
  - tokens already issued expire within `GRITCH_TOKEN_MINUTES`.
- Nothing else in Nexora depends on the integration.
- The `integration_identities` rows and the accounts stay, so switching back on reuses them.
