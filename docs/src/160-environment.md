# Environment Variables

183 variables are documented in `.env.example`. Below are the ones a deployment actually
has to decide about, grouped by concern.

> [!WARNING]
> **Every value in this section is a placeholder.** Never commit real credentials. `.env` is
> in `.gitignore`, alongside `.env.backup` and `.env.production`.

## Required

| Variable | Purpose | Example |
|---|---|---|
| `APP_KEY` | Encryption key. **Also decrypts Slack webhook URLs and stored AI credentials** | `base64:<APP_KEY>` |
| `APP_ENV` | Environment | `production` |
| `APP_DEBUG` | Debug output | `false` |
| `APP_URL` | Canonical URL; drives generated links | `https://nexora.example.com` |
| `DB_CONNECTION` | Driver | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Connection | `<DB_PASSWORD>` |

## Workspace identity

| Variable | Default | Purpose |
|---|---|---|
| `WORKSPACE_NAME` | `Aqueduct Workspace` | Sidebar and transactional mail. **Set to `Nexora` to brand the product as Nexora** |
| `WORKSPACE_SHORT_NAME` | `Aqueduct` | Page-title suffix |
| `WORKSPACE_DEFAULT_TIMEZONE` | `UTC` | Default board timezone; also where "today" and "yesterday" are cut for statistics and MCP answers. Production: `Europe/Stockholm` |
| `WORKSPACE_NEW_TICKETS_VISIBLE` | `true` | Whether tickets staff create start visible to the board's customers (form, quick add, assistant). A board can override it |
| `ALLOW_PUBLIC_REGISTRATION` | `false` | Self-service sign-up. Registrants get the `customer` role |

## Session and security

| Variable | Default | Production guidance |
|---|---|---|
| `SESSION_DRIVER` | `database` | Keep |
| `SESSION_LIFETIME` | `120` | Minutes |
| `SESSION_SECURE_COOKIE` | `false` | **Set to `true`** |
| `SESSION_ENCRYPT` | `false` | Consider `true` |
| `SESSION_SAME_SITE` | `lax` | Keep |
| `BCRYPT_ROUNDS` | `12` | Keep |

## Storage

| Variable | Default | Purpose |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | **Must be `s3` or `volume` in production** — boot is refused otherwise |
| `ATTACHMENT_DISK` | falls back to `FILESYSTEM_DISK` | Point attachments at a dedicated bucket |
| `ATTACHMENT_VOLUME_PATH` | — | Mount path, when using a Railway volume |
| `ATTACHMENT_MAX_SIZE_KB` | `10240` | 10 MB per record attachment |
| `ATTACHMENT_MAX_PER_OWNER` | `20` | |
| `ATTACHMENT_URL_TTL_MINUTES` | `5` | Signed-URL lifetime |
| `AWS_ACCESS_KEY_ID` | — | `<AWS_ACCESS_KEY_ID>` |
| `AWS_SECRET_ACCESS_KEY` | — | `<AWS_SECRET_ACCESS_KEY>` |
| `AWS_BUCKET`, `AWS_DEFAULT_REGION` | — | |
| `AWS_ENDPOINT` | — | Set for Cloudflare R2, MinIO or Backblaze B2 |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | Required by some S3-compatible providers |

## Queue, cache and realtime

| Variable | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | |
| `CACHE_STORE` | `database` | Redis needed for multi-replica scheduler locks |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | — | Optional |
| `BROADCAST_CONNECTION` | `reverb` | `null` disables realtime cleanly |
| `REVERB_APP_ID`, `REVERB_APP_KEY` | — | Server credentials |
| `REVERB_APP_SECRET` | — | **`<REVERB_APP_SECRET>` — never pass to the build stage** |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | — | The public domain browsers connect to |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | `0.0.0.0`, `$PORT` | What the process binds |
| `VITE_REVERB_APP_KEY` | — | **BUILD ARGUMENT.** Public key only |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | — | **BUILD ARGUMENTS** |

## AI — core

| Variable | Default | Purpose |
|---|---|---|
| `AI_ENABLED` | `true` | Master switch |
| `AI_PROVIDER` | `anthropic` | Default provider |
| `ANTHROPIC_API_KEY` | — | `<ANTHROPIC_API_KEY>`. May instead be stored via the settings screen |
| `OPENAI_API_KEY` | — | `<OPENAI_API_KEY>`. Also powers voice and audio transcription |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | For a proxy or Azure |
| `AI_MODEL` | `claude-opus-5` | Default model |
| `AI_DEFAULT_MODE` | `agent` | **Capability ceiling.** Set `operator` or `observer` for a cautious posture |
| `AI_MAX_OUTPUT_TOKENS` | `16000` | |
| `AI_EFFORT` | `high` | `low`/`medium`/`high`/`xhigh`/`max` |
| `AI_REQUEST_TIMEOUT` | `300` | Seconds |
| `AI_MAX_RETRIES` | `2` | |

## AI — limits and caps

| Variable | Default | Purpose |
|---|---|---|
| `AI_SESSION_TOKEN_LIMIT` | `400000` | Per conversation. 0 = unlimited |
| `AI_DAILY_USER_TOKEN_LIMIT` | `2000000` | Per person per day |
| `AI_SESSION_HISTORY` | `25` | Sessions returned by `AiSessionManager::history()`; not currently listed in the interface |
| `AI_DAILY_AUTO_RUN_CAP` | `20` | Per board. **No bypass exists** |
| `AI_MAX_DAILY_AUTO_RUN_CAP` | `500` | Ceiling a board may configure |
| `AI_DAILY_MANUAL_RUN_CAP` | `100` | |
| `AI_ADMINS_BYPASS_CAP` | `true` | Manual cap only |

## AI — chat, tools and knowledge

| Variable | Default | Purpose |
|---|---|---|
| `AI_CHAT_DEFAULT_MODE` | `reading` | |
| `AI_CHAT_READING_MODEL` | `claude-sonnet-5` | |
| `AI_CHAT_WRITING_MODEL` | `claude-opus-5` | |
| `AI_CHAT_EVERYTHING_MODEL` | `claude-opus-5` | |
| `AI_CHAT_HISTORY` | `20` | Turns replayed |
| `AI_CHAT_MAX_OUTPUT_TOKENS` | `4000` | |
| `AI_CHAT_MAX_OUTPUT_TOKENS_OUTSIDE` | `8000` | Outside Project scope |
| `AI_CHAT_MAX_OUTPUT_TOKENS_WRITING` | `16000` | A turn that may propose changes, so a batch of tickets is not cut off |
| `AI_CHAT_MAX_TICKETS_PER_REPLY` | `30` | Most tickets one `propose_create_tickets` call may carry |
| `AI_CHAT_STREAM_TIME_LIMIT` | `360` | Must exceed `AI_REQUEST_TIMEOUT` |
| `AI_CHAT_CONTEXT_TICKETS` / `_PAGES` / `_EVENTS` | `60` / `40` / `40` | Board context depth |
| `AI_CHAT_CONTEXT_BOARDS` / `_BOARD_TICKETS` | `12` / `6` | Workspace context breadth |
| `AI_TOOLS_ENABLED` | `true` | Retrieval tools |
| `AI_TOOLS_MAX_ROUNDS` / `_CALLS` / `_CHARACTERS` | `4` / `6` / `60000` | Loop bounds |
| `AI_KNOWLEDGE_ENABLED` | `true` | Live external lookup |
| `AI_KNOWLEDGE_DRIVER` | *(empty)* | **No driver ships.** Empty resolves to the refusing implementation |
| `AI_KNOWLEDGE_MAX_RESULTS` / `_TIMEOUT` | `5` / `10` | |

## AI — runs, code generation and repositories

| Variable | Default | Purpose |
|---|---|---|
| `AI_QUEUE` | `ai` | |
| `AI_JOB_TRIES` / `AI_JOB_TIMEOUT` | `3` / `900` | Timeout must exceed request + clone + test time |
| `AI_CODE_DRIVER` | `unavailable` | Set `claude_code` to enable apply mode |
| `AI_CLAUDE_CODE_BINARY` | `claude` | |
| `AI_CODE_TIMEOUT` | `1800` | |
| `AI_VALIDATION_COMMANDS` | *(empty)* | Pipe-separated; run in the checkout **before** committing |
| `AI_VALIDATION_TIMEOUT` | `900` | |
| `AI_REPOSITORY_CLONE_ENABLED` | `false` | Opt-in |
| `AI_CLONE_DEPTH` | `1` | Shallow |
| `AI_GIT_BINARY` / `AI_GIT_TIMEOUT` | `git` / `300` | |
| `AI_BRANCH_PREFIX` | `ai/` | |
| `AI_COMMIT_AUTHOR_NAME` / `_EMAIL` | `Workspace AI` / `ai@example.invalid` | |
| `AI_WORKSPACE_PATH` | `ai-runs` | Relative to `storage/app` |
| `AI_KEEP_FAILED_WORKSPACES` | `false` | Leave `false` on Railway |
| `AI_MAX_CONTEXT_FILES` / `_BYTES` | `400` / `60000` | Tree summarised into the prompt |

## AI — attachments and voice

| Variable | Default | Purpose |
|---|---|---|
| `AI_ATTACHMENTS_ENABLED` | `true` | |
| `AI_ATTACHMENTS_QUEUE` | `attachments` | **Must be served by a worker that can read the file** |
| `AI_ATTACHMENT_MAX_SIZE_KB` | `20480` | 20 MB |
| `AI_ATTACHMENT_MAX_PER_SESSION` | `10` | |
| `AI_ATTACHMENT_FILE_CHARS` / `_TOTAL_CHARS` / `_DIGEST_CHARS` | `40000` / `120000` / `1200` | |
| `AI_ATTACHMENT_CSV_ROWS` / `_COLUMNS` / `_SAMPLES` | `300` / `40` / `5` | |
| `AI_ATTACHMENT_IMAGE_MAX_DIMENSION` / `_QUALITY` | `1568` / `82` | |
| `AI_ATTACHMENT_AUDIO_ENABLED` | `true` | Needs an OpenAI key |
| `AI_TRANSCRIPTION_MODEL` / `_TIMEOUT` | `whisper-1` / `180` | |
| `AI_VOICE_ENABLED` / `_DRIVER` | `true` / `openai` | Needs an OpenAI key |
| `AI_VOICE_TRANSCRIPTION_MODEL` | `whisper-1` | |
| `AI_VOICE_SPEECH_MODEL` | `gpt-4o-mini-tts` | |
| `AI_VOICE_VOICES` / `_DEFAULT` | `alloy,echo,fable,onyx,nova,shimmer` / `alloy` | Allow-list |
| `AI_VOICE_TIMEOUT` / `AI_VOICE_RATE_LIMIT` | `120` / `30` | |
| `AI_OPENAI_MODELS` | `gpt-5.1,gpt-5-mini` | Catalogue entries for OpenAI |
| `AI_OPENAI_VISION_MODELS` | *(empty)* | **Opt-in** vision support |

## Remote MCP server

| Variable | Default | Purpose |
|---|---|---|
| `MCP_SERVER_ENABLED` | `true` | `false` removes `/mcp` and the OAuth discovery and registration routes |
| `MCP_REDIRECT_DOMAINS` | `https://claude.ai,https://claude.com,https://chatgpt.com,http://localhost` | Where a registering AI client may send the authorization code |
| `MCP_RATE_LIMIT` | `60` | Tool calls per minute, per person |
| `MCP_OAUTH_RATE_LIMIT` | `120` | Discovery / registration requests per minute, per IP |
| `MCP_ACCESS_TOKEN_MINUTES` | `60` | Access-token lifetime |
| `MCP_REFRESH_TOKEN_DAYS` | `30` | Refresh-token lifetime — how long an unused connection survives |
| `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` | *(none)* | Token signing keys (PEM). **Required in production**; locally `php artisan passport:keys` writes `storage/oauth-*.key` instead. Rotating them disconnects every client |

## GitHub

| Variable | Default | Purpose |
|---|---|---|
| `GITHUB_TOKEN` | — | `<GITHUB_TOKEN>`. Needs `contents: write` + `pull_requests: write`. **Never merge rights** |
| `GITHUB_WEBHOOK_SECRET` | — | `<GITHUB_WEBHOOK_SECRET>`. **Unset means every delivery is refused** |
| `GITHUB_API_URL` | `https://api.github.com` | |
| `GITHUB_REQUEST_TIMEOUT` | `20` | |
| `GITHUB_WEBHOOK_RATE_LIMIT` | `300` | Per minute per IP |
| `GITHUB_WEBHOOK_RETENTION_DAYS` | `30` | |
| `GITHUB_MAX_REFERENCES` / `GITHUB_MAX_COMMITS` | `50` / `20` | |
| `GITHUB_QUEUE` / `_JOB_TRIES` / `_JOB_TIMEOUT` | `default` / `3` / `60` | |

## Slack and SMS

| Variable | Default | Purpose |
|---|---|---|
| `SLACK_NOTIFICATIONS_ENABLED` | `true` | Master switch. Turn **off** on staging restored from a production dump |
| `SLACK_QUEUE` / `SLACK_TIMEOUT` / `SLACK_TRIES` | `default` / `8` / `3` | Webhook URLs live per board, encrypted |
| `SMS_ENABLED` | `false` | |
| `SMS_DRIVER` | `unavailable` | `unavailable`, `log`, or `46elks` |
| `SMS_FROM` | `Aqueduct` | Alphanumeric (≤11 chars) or E.164 |
| `SMS_46ELKS_USERNAME` / `_PASSWORD` | — | `<SMS_46ELKS_PASSWORD>` |
| `SMS_ALERT_RECIPIENTS` | *(empty)* | Comma-separated E.164 fallback rota |
| `SMS_MAX_RECIPIENTS` | `5` | Hard ceiling |
| `SMS_BOARD_COOLDOWN` | `300` | Seconds |
| `SMS_MAX_LENGTH` | `300` | |
| `SMS_RETENTION_DAYS` | `90` | **Data-protection obligation** — rows hold phone numbers |

## Mail, logging and seeding

| Variable | Default | Purpose |
|---|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | — | Password-reset delivery |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | — / `${WORKSPACE_NAME}` | |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | `stack` / `single` / `debug` | Use `error` or `warning` in production |
| `ACTIVITY_LOGGER_ENABLED` | `true` | Workspace activity feed |
| `SEED_ADMIN_NAME` / `_EMAIL` / `_PASSWORD` | — | **A password must be supplied explicitly outside local/testing** |
| `SEED_TEAM_*`, `SEED_CUSTOMER_*` | — | Demo accounts |

## Deployment-only

| Variable | Purpose |
|---|---|
| `PORT` | Injected by Railway; substituted into `nginx.conf` at boot |
| `RUN_MIGRATIONS` | **Set to `true` on exactly one service** |
| `RUN_SEEDERS` | Run seeders at boot. Leave unset in production after first run |

<!-- pagebreak -->

# Installation and Local Development

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.2 or later, with `bcmath`, `gd`, `intl`, `pdo_mysql`, `zip` |
| Composer | 2.x |
| Node.js | 22 recommended (the image uses `node:22-alpine`) |
| npm | Bundled with Node |
| MySQL | 8.x — or SQLite for a quick start |
| Git | For the repository, and for AI apply mode |

Optional: Redis, the Claude Code CLI (apply mode), an Anthropic and/or OpenAI API key.

## Quick start

The repository defines a `setup` script that does most of it:

```bash
git clone <REPOSITORY_URL> nexora
cd nexora
composer setup
```

`composer setup` runs, in order:

```bash
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
npm install
npm run build
```

## Step by step

**1. Clone and install PHP dependencies**

```bash
git clone <REPOSITORY_URL> nexora
cd nexora
composer install
```

**2. Install front-end dependencies**

```bash
npm install
```

**3. Create the environment file**

```bash
cp .env.example .env
```

**4. Generate the application key**

```bash
php artisan key:generate
```

**5. Configure the database**

Edit `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nexora
DB_USERNAME=nexora
DB_PASSWORD=<DB_PASSWORD>
```

**6. Run migrations**

```bash
php artisan migrate
```

**7. Seed**

```bash
php artisan db:seed
```

| Seeder | What it creates | Safe in production? |
|---|---|---|
| `AdminUserSeeder` | The first administrator, from `SEED_ADMIN_*`. Idempotent | **Yes** — credentials come from the environment, and outside local/testing a password must be supplied explicitly |
| `DemoUserSeeder` | Three known accounts, one per role | **No** — see the warning below |
| `DevelopmentSeeder` | Two sample boards with a deliberately asymmetric fixture | **No** — refuses to run in production |

> [!WARNING]
> **`DemoUserSeeder` has its credentials in the source, on purpose and with eyes open.** The
> workspace was being brought up as a demo, and "set four environment variables, then find a
> shell" was the wrong price for three demo logins. The cost is equally plain: anybody who can
> read the repository can read those passwords, and they are far below what the application
> itself would accept from a form — production requires 12+ characters, mixed case, and a
> breach check, and seeders bypass form validation, which is the only reason they work.
>
> **Change them from Admin → Users before anything real lands**, at which point the UI holds
> you to the strong rules.

`DevelopmentSeeder` creates a fixture designed to check the access rules by hand:

| Board | Prefix | Members | Purpose |
|---|---|---|---|
| Aqueduct Platform | `AQD` | team, customer | Mixes internal and customer-visible tickets, so the two roles see different boards at one URL |
| Internal Tooling | `INT` | team only | **The customer must get a 404 here, not a 403** |

**8. Build the front end**

```bash
npm run build     # production
npm run dev       # watch mode
```

**9. Run everything**

The repository defines a single command that starts all five processes:

```bash
composer dev
```

which runs concurrently:

```bash
php artisan serve
php artisan queue:listen --queue=ai,default --tries=1 --timeout=0 --sleep=1
php artisan reverb:start
php artisan pail --timeout=0
npm run dev
```

Or start them individually:

```bash
php artisan serve                                          # http://127.0.0.1:8000
php artisan queue:work --queue=ai,default,attachments      # REQUIRED for most features
php artisan reverb:start                                   # realtime (optional)
php artisan schedule:work                                  # housekeeping (optional)
npm run dev                                                # asset watcher
```

> [!IMPORTANT]
> Include **`attachments`** in the local worker's queue list. In production that queue is
> served by a separate worker inside the web container; locally there is only one process, so
> omitting it means AI file uploads sit at `pending` for ever.

## Code style

```bash
composer lint         # vendor/bin/pint — fixes
composer lint:test    # vendor/bin/pint --test — checks only
```

## Useful environment settings for development

```env
APP_ENV=local
APP_DEBUG=true
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=null        # skip Reverb
AI_ENABLED=false                 # no provider calls
SMS_DRIVER=log                   # log instead of sending
SLACK_NOTIFICATIONS_ENABLED=false
```

Outside production, `Model::shouldBeStrict()` is on, so N+1 queries, missing attributes and
silently discarded mass-assignment values all fail loudly.

<!-- pagebreak -->
