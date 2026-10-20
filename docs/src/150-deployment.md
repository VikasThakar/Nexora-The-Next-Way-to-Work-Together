# Deployment Architecture

## Production topology

```diagram
   GitHub repository
         |  push
         v
   Railway build  ──►  Dockerfile (3 stages)
         |                 1. composer:2        vendor/  (--no-dev)
         |                 2. node:22-alpine    public/build  (Vite)
         |                 3. php:8.3-fpm-alpine  runtime
         v
   ONE IMAGE, THREE SERVICES
         |
         +─────────────────────────┬──────────────────────────┐
         v                         v                          v
   +--------------+        +---------------+         +----------------+
   | web          |        | worker        |         | reverb         |
   | railway.json |        | railway.      |         | railway.       |
   |              |        | worker.json   |         | reverb.json    |
   | supervisord: |        |               |         |                |
   |  php-fpm     |        | queue:work    |         | reverb:start   |
   |  nginx :PORT |        |  --queue=     |         |  --host=0.0.0.0|
   |  queue:work  |        |  ai,default   |         |  --port=$PORT  |
   |   --queue=   |        |  --tries=3    |         |                |
   |   attachments|        |  --max-time=  |         | 1 REPLICA ONLY |
   |              |        |   3600        |         |                |
   | health:      |        |               |         | needs its own  |
   |  /health     |        | restart:      |         | public domain  |
   | 1 replica    |        |  ALWAYS       |         |                |
   +--------------+        +---------------+         +----------------+
         |                         |                          |
         +─────────────┬───────────+──────────────────────────+
                       v
         +--------------------------------+
         |  MySQL   (Railway plugin)      |
         |    application schema          |
         |    sessions, cache, jobs       |
         +--------------------------------+
                       |
         +--------------------------------+
         |  S3-compatible object storage  |
         |  or a Railway volume at /data  |
         +--------------------------------+
```

TLS is terminated at the Railway edge and the request is proxied over HTTP, which is why the
application sets `trustProxies(at: '*')` and forces the `https` scheme for generated URLs.

## The Docker build

### Stage 1 — vendor

`composer:2`, installing with `--no-dev --no-scripts`. Scripts are skipped because `artisan`
is not present yet; the autoloader is rebuilt against the full source tree in the runtime
stage.

### Stage 2 — assets

`node:22-alpine`, `npm ci`, then `npm run build`.

It copies `resources`, **plus `app/` and `config/`** — Tailwind scans Blade templates *and*
PHP sources for class names. It also copies Laravel's pagination views out of the vendor
stage, because `resources/css/app.css` declares `@source` paths that must resolve or the
pagination styles silently drop out of the bundle.

> [!WARNING]
> `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME` are
> **build arguments**. Vite inlines `import.meta.env.*` at build time, so they cannot be
> supplied as runtime variables later. An image built without them ships a browser bundle
> that never subscribes to anything.
>
> **Only the public key belongs here. `REVERB_APP_SECRET` must never be passed to this
> stage** — everything in it is readable in the shipped JavaScript.
>
> Empty is a working default, not a broken one: the build succeeds and the application runs
> exactly as it does with realtime switched off.

### Stage 3 — runtime

`php:8.3-fpm-alpine`, with:

| Component | Why |
|---|---|
| **PHP extensions** `bcmath`, `gd`, `intl`, `opcache`, `pcntl`, `pdo_mysql`, `zip` | `gd` is the one worth explaining — it decodes and re-encodes every uploaded image before it reaches a model, stripping EXIF (routinely including GPS) and making the declared media type true by construction. Without it every image attachment reports itself unreadable. **The build asserts `php -m | grep -qx gd`** |
| `libjpeg-turbo`, `libwebp` | PNG and GIF come with `gd`; JPEG and WebP have to be asked for, and the assistant's allow-list accepts all four |
| `nginx`, `supervisor`, `curl`, `icu-libs`, `libzip`, `tzdata` | Runtime |
| `git` | The isolated checkout for apply mode |
| `claude-code` | The apply-mode coding runtime, from Anthropic's APK repository |
| `nodejs`, `npm` | For `AI_VALIDATION_COMMANDS`, which runs the **target repository's** own build inside the checkout |
| `ripgrep`, `libgcc`, `libstdc++` | Alpine is musl, so Claude Code needs a real ripgrep rather than its bundled glibc-linked copy |

> [!NOTE]
> `git`, `claude-code`, `node` and `npm` are **not used by the web service**. They are in the
> shared image because the worker runs the *same image* with a different start command, and an
> apply run refuses immediately without them. The assets stage cannot supply node, because
> only `public/build` is copied out of it — without node in the runtime, a validation command
> would fail with `sh: npm: not found` **after** the model had already done its work.

The build finishes by dumping an optimised autoloader, **removing `tests/` and
`phpunit.xml`**, creating the writable directories, and creating and chowning nginx's
spill-to-disk paths.

## Entrypoint sequence

`docker/entrypoint.sh` runs before supervisord. Everything in it is runtime work that must
**not** happen at image build time — configuration caching reads environment variables that
only exist once the service is running, and migrations need a reachable database.

```diagram
   1.  Substitute $PORT into nginx.conf
   2.  Create and chown /var/cache/nginx/{client_body,fastcgi}  → www-data
   3.  Recreate the storage tree (ephemeral on Railway) and chown it
   4.  If ATTACHMENT_VOLUME_PATH is set: mkdir + chown (non-recursive)
   5.  ABORT if APP_KEY is unset
   6.  config:clear, then config:cache, route:cache, view:cache, event:cache
   7.  If RUN_MIGRATIONS=true:
          migrate:status succeeds?  →  migrate --force --isolated
          migrate:status fails?     →  migrate --force        (empty database)
   8.  If RUN_SEEDERS=true: db:seed --force
   9.  exec supervisord
```

Two details are load-bearing:

**Migrations are opt-in.** `RUN_MIGRATIONS` must be set on **exactly one service**, so a
rollback or a second replica cannot race the schema.

**The first migration deliberately runs unisolated.** `--isolated` takes its lock from the
default cache store, which on the `database` store lives in `cache_locks` — a table created
by a migration. On a first deploy against an empty database the lock cannot be taken, because
the table it needs is one of the tables it is guarding the creation of. Passing `--isolated`
there fails with *"Base table or view not found: cache_locks"* and, with `set -e`,
restart-loops the container. The probe is `migrate:status`, which exits non-zero while the
migrations table is absent.

**The attachment volume is prepared but not created.** If `ATTACHMENT_VOLUME_PATH` is missing
from the mount, `mkdir` makes an ordinary container directory — and the durability check in
`AppServiceProvider` then refuses to boot, which is the intended outcome.

## Web container processes

| Program | Priority | Command |
|---|---|---|
| `php-fpm` | 10 | `php-fpm --nodaemonize --force-stderr` |
| `nginx` | 20 | `nginx -g "daemon off;"` |
| `attachment-worker` | 30 | `queue:work --queue=attachments --sleep=1 --tries=2 --max-time=3600`, as `www-data` |

All logs go to stdout/stderr so Railway collects them.

## PHP and nginx tuning

| Setting | Value | Note |
|---|---|---|
| `memory_limit` | 256M | |
| `max_execution_time` | 60 | Streaming AI answers raise this per-request via `set_time_limit()` |
| `upload_max_filesize` / `post_max_size` | 32M / 34M | Must stay above the attachment ceiling |
| `expose_php`, `display_errors` | Off | Errors go to stderr |
| OPcache | `validate_timestamps = 0` | Application code never changes inside a running container, so timestamp validation is pure overhead |
| `session.use_strict_mode` | 1 | Rejects browser-supplied session ids |
| PHP-FPM pool | `pm = dynamic`, max 20 children, `max_requests = 500` | Recycles workers to bound leaks |
| nginx `client_max_body_size` | 32m | |
| nginx workers | `user www-data` | **Must** match the PHP-FPM pool user, which is why the temp paths are chowned explicitly |

## Health checks

| Probe | Path | Used by | Checks |
|---|---|---|---|
| Framework | `/up` | Docker `HEALTHCHECK`, every 30s | The framework boots |
| Application | `/health` | Railway (`healthcheckPath`), timeout 60s | **Database answering** and **cache store writable** |

`/health` answers one question: can this container serve a request end to end? Both checks
are round trips a real request would make anyway, and both fail in ways a process-liveness
check cannot see — a container whose database credentials are wrong boots perfectly and 500s
on every page.

What it deliberately does **not** do:

- **Nothing slow.** No queue depth, no third-party reachability, no disk scan. It runs every
  thirty seconds for the life of the deployment, and a probe that calls Anthropic or GitHub
  turns their outage into our restart loop.
- **Nothing revealing.** It is unauthenticated by necessity, so it returns booleans and a
  timestamp — no hostnames, no versions, no driver names, no error text.
- **No pending-migration failure.** Pending migrations are *reported* but do not mark the
  container unhealthy: migrations run from one service, so during a deploy every other
  service would briefly report pending and be restarted in a loop by the very platform
  waiting for the migration to finish.

## Restart policy

| Service | Policy | Replicas |
|---|---|---|
| web | `ON_FAILURE`, max 5 retries | 1 |
| worker | `ALWAYS`, max 10 retries | 1 |
| reverb | `ALWAYS`, max 10 retries | **1 — do not raise without Redis** |

## Deployment process

1. Push to the default branch; Railway builds the Dockerfile.
2. Build arguments supply the `VITE_REVERB_*` values.
3. On boot, the entrypoint caches configuration, routes, views and events from the **live**
   environment.
4. The service with `RUN_MIGRATIONS=true` migrates.
5. Railway polls `/health` before routing traffic to the new deployment.
6. The worker and reverb services restart onto the new image.

> [!IMPORTANT]
> **A deploy restarts the worker, which is why `ai:reap-stalled` exists.** Any AI run in
> flight is orphaned; the reaper recovers it within ten minutes. An apply run takes minutes,
> so this is ordinary rather than exceptional.

### The remote MCP server in production

- **Passport keys.** Set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` on the **web**
  service (generate once, e.g. `openssl genrsa -out private.pem 4096 && openssl rsa -in
  private.pem -pubout -out public.pem`; paste the PEM text, never commit it). Without them
  every OAuth request and every `/mcp` call with a token fails. The worker and Reverb do not
  need them.
- **Timezone.** `WORKSPACE_DEFAULT_TIMEZONE=Europe/Stockholm` on web and worker, so
  "yesterday" means the Swedish day.
- **The URL** people add as a connector is exactly `https://<host>/mcp` — no trailing slash.
  Check `GET /.well-known/oauth-protected-resource/mcp` returns that value as `resource`; it
  is built from the request, so it is only right because `trustProxies('*')` and
  `URL::forceScheme('https')` make Laravel see HTTPS behind Railway's edge.
- **Reachability.** Claude's calls come from Anthropic's published egress range and
  ChatGPT's from OpenAI's; both need the public HTTPS endpoint. Nothing else is required.
- **Scheduler.** `passport:purge` is scheduled, like the other housekeeping, and only runs
  where a scheduler does.
- **The release that added changes over MCP** runs migration
  `2026_10_21_000100_revoke_read_only_mcp_connections`, which signs out every connected AI
  app once. Tell people to expect it: in Claude, Settings → Connectors → Nexora → Connect,
  and agree to the new screen. No new variable is needed; the defaults are
  `MCP_WRITES_ENABLED=true` and `MCP_WRITE_RATE_LIMIT=30`.
- **Back to read-only** without a deploy: set `MCP_WRITES_ENABLED=false` on the web service.
  Every write tool disappears from the next `tools/list`; reads keep working. Do not roll
  the revoke migration back — un-revoking credentials is never the right way back.

## Rollback considerations

| Concern | Guidance |
|---|---|
| Code rollback | Redeploy the previous image. Configuration is re-cached at boot |
| Schema rollback | **Read the `down()` method first.** Several are destructive by necessity — restoring a `NOT NULL` constraint requires deleting the rows that violate it |
| `RUN_MIGRATIONS` during rollback | Leave it off on the rolled-back deploy unless you have read the down migrations |
| Attachments | Unaffected — they live in object storage or on a volume, keyed by the disk recorded on each row |
| In-flight AI runs | Orphaned by the restart; recovered by the reaper |
| Queued jobs | Survive in the `jobs` table and are picked up by the new worker |
| `APP_KEY` | **Never rotate casually.** It decrypts Slack webhook URLs and stored AI credentials; rotating it silently disables both, degrading to "not configured" |

## Scaling notes

| Want to scale | Do this |
|---|---|
| Web traffic | Raise web replicas. Sessions and cache are in MySQL, so this is safe |
| AI throughput | Split the worker: one `--queue=ai`, one `--queue=default` |
| Realtime connections | Requires `REVERB_SCALING_ENABLED=true` **and** a Redis service |
| Scheduler | Must run on **one** service. `->onOneServer()` needs a shared cache store to mean anything |
| Attachment worker | Stays in the web container unless the disk is S3 |

<!-- pagebreak -->
