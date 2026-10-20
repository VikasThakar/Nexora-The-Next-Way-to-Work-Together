# Testing

## Suites

Three suites are declared in `phpunit.xml`.

| Suite | Directory | Files | Purpose |
|---|---|---|---|
| **Unit** | `tests/Unit` | 17 | Pure logic: enums, value objects, parsers, cost calculation |
| **Feature** | `tests/Feature` | 112 | End-to-end behaviour through routes and Livewire components |
| **Security** | `tests/Security` | 32 | **Authorization and isolation, runnable alone as a fast CI gate** |

Test environment: SQLite in memory, `array` cache and session, `sync` queue, `null`
broadcasting, `array` mail, `BCRYPT_ROUNDS=4`, public registration off, and
`AI_CODE_DRIVER=unavailable` pinned so the suite tests the **default** driver rather than
whatever a developer has in their `.env`.

## Running the tests

```bash
composer test                              # config:clear, then php artisan test
composer test:security                     # the Security suite alone
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test tests/Security/CustomerBoundaryTest.php
php artisan test --filter=test_a_customer_cannot_see_internal_tickets
```

## Current status

Measured at commit `9c0ff8b` on 20 October 2026:

| Result | Count |
|---|---|
| **Passed** | **2,033** |
| Failed | 2 |
| Assertions | 7,905 |

The two failures are the `ThemePreferenceTest` pair below. The third test in the table is
date-sensitive: it passes on days whose report period contains no `7`, as it did on this run.
A fourth, `AiAttachmentSecurityTest > the stored path reveals nothing about the upload`,
failed about one run in forty because the random file name it inspected could itself contain
the letters it looked for; it checks the name's shape instead since `7d13980`.

### The known failures

> [!IMPORTANT]
> All three are **test defects, not application defects.** None indicates a security or
> functional regression.

| Test | Cause |
|---|---|
| `Feature\Theme\ThemePreferenceTest > a page renders the stored preference before the stylesheet` | Pre-existing |
| `Feature\Theme\ThemePreferenceTest > system is rendered as system rather than resolved` | Pre-existing |
| `Security\AiVisualizationSecurityTest > a customers totals exclude internal tickets` | **Date-sensitive assertion** |

The third is worth explaining, because at a glance it reads like a customer-isolation
failure. The test creates 2 customer-visible and 5 internal tickets, then asserts three
things. The **two that matter both pass**:

- the customer's answer contains `(total 2)` — the internal rows never entered the count;
- the staff answer contains `(total 7)`.

The failing assertion is the third, a belt-and-braces check that the customer's answer does
not contain the digit `7` anywhere, so the hidden count could not be recovered by
subtraction. The answer's header reads:

```text
by_status for Dolore Expedita, 2026-08-19 to 2026-09-17 (30 days, UTC).
```

The report period ends on today's date — **2026-09-17** — which contains a `7`. The
assertion therefore fails on any day whose rendered period string contains that digit, and
passes otherwise. **The customer boundary itself is intact and is proven by the first
assertion in the same test.**

The fix is to narrow the assertion to the figure rather than the character — for example
asserting the absence of `(total 7)` — rather than to change any application behaviour.

## What is covered

### Security suite — the customer boundary

| Test file | Asserts |
|---|---|
| `CustomerBoundaryTest` | The overarching internal/customer separation |
| `TicketVisibilityTest`, `TicketAuthorizationTest` | Internal tickets are invisible and unwritable to customers |
| `CommentVisibilityTest` | Internal notes, their count and their existence are hidden |
| `DocumentationVisibilityTest` | The ancestor rule holds |
| `BoardVisibilityTest`, `BoardMembershipTest` | Non-members get 404, never 403 |
| `AttachmentVisibilityTest` | Files inherit their owner's visibility |
| `StatisticsVisibilityTest`, `StatisticsExportSecurityTest` | Aggregates and CSVs cannot leak internal figures |
| `GlobalSearchVisibilityTest` | The palette cannot surface unreachable rows |
| `NotificationVisibilityTest` | No notification is written for an ineligible recipient |
| `ActivityVisibilityTest` | The feed refuses non-staff in SQL |
| `RealtimeAuthorizationTest`, `RealtimeBroadcastAudienceTest` | Channel joins and broadcast audiences |
| `RoleAccessTest` | Route-level role gates |

### Security suite — AI

| Test file | Asserts |
|---|---|
| `AiAssistantSecurityTest`, `AiChatSecurityTest` | Conversation ownership and scope |
| `CustomerAiSecurityTest` | A customer's assistant is read-only and staff tools are withheld |
| `McpCustomerBoundaryTest` | A customer's AI app (remote MCP) gets the customer tools only, never sees an internal ticket or note through any tool, and can change only what the ticket screen lets them: raise a ticket, edit their own, reply on the customer thread |
| `AiWriteAuthorizationTest` | Proposals are authorized against the confirming person, per field |
| `AiVisibilityTest` | AI runs are invisible to customers |
| `AiVisualizationSecurityTest` | Charts cannot aggregate hidden data |
| `AiKnowledgeScopeSecurityTest` | Outside Project does not widen data access |
| `AiAttachmentSecurityTest` | Uploads are private to their conversation |
| `AiCredentialSecurityTest` | Keys are never rendered or returned |
| `SecretExposureTest` | **No credential reaches a response, a log line or an exception message** |
| `WebhookSecurityTest` | Signature verification, replay handling, fail-closed behaviour |
| `RichTextSanitizationTest` | The editor allow-list holds in both directions |

### Feature suite highlights

| Area | Files |
|---|---|
| AI | 39 files — capability modes, chat modes, knowledge scope, sessions, context window and prompt caching, tools, attachments, voice, rich responses, apply mode, run caps, inheritance, Anthropic and OpenAI providers, token usage, audit log, batch tickets, importing a file into the docs, tickets from the page on screen |
| MCP | 5 files — the endpoint and both protocol generations, OAuth and the consent screen, every write tool (confirmation codes, retries, stale reads, the AI mode, archived boards, the write limit, the kill switch), the display rules and badges with the in-app output held unchanged, the reconnect migration |
| Tickets | 13 files — creation, editing, movement, filtering, links, subtasks, attachments, events, rich text, checklist conversion, quick add, live conversation |
| Documentation | 8 files — editor, save state, drag and drop, tree, workspace, visibility, importing files as pages, the file-to-Markdown converters |
| Boards | 6 files — management, deletion, columns, labels, integrations, drag and drop |
| Statistics | 6 files — team, customer, flow metrics, exports, chart export markup |
| Notifications | 4 files — bell, ticket notifications, Slack, critical-ticket SMS |
| Auth | 3 files — authentication, password reset, registration |
| Activity | 4 files — feed, logging, cards, unified timeline |
| Other | GitHub webhook processing, health check, profile, dashboard, command palette, theme, UI navigation, dialogs, attachment durability, demo seeder |

## Test support

| Helper | Purpose |
|---|---|
| `FakeAiProvider` | Scripted model responses — **the suite never calls a real provider** |
| `UnreachableAiProvider` | Asserts that a code path does *not* reach a provider |
| `FakeExternalKnowledge` | Stands in for the absent knowledge driver |
| `FakeSmsProvider` | Captures messages instead of sending them |
| `FakeFiles` | Deterministic uploads |

13 model factories cover users, boards, columns, members, labels, tickets, subtasks,
repositories, GitHub links, AI runs, AI sessions, AI attachments and AI usage records.

## Gaps

| Gap | Note |
|---|---|
| Browser / end-to-end tests | **None.** No Dusk, Playwright or Cypress. Drag-and-drop, the TipTap editor, the command palette and theme switching are tested only at the server boundary |
| JavaScript unit tests | **None.** No Vitest or Jest; `resources/js` is untested in isolation |
| Load or performance tests | None |
| Mutation testing | None |
| Coverage reporting | Configured (`<source>` includes `app`) but no threshold is enforced and no CI configuration is present in the repository |

<!-- pagebreak -->

# Troubleshooting

Each entry gives the **problem**, the **cause**, the **solution** and how to **verify**.

## APP_KEY is missing

**Problem** — The container exits immediately with
`[entrypoint] FATAL: APP_KEY is not set`, or pages return a decryption error.

**Cause** — `APP_KEY` is unset. The entrypoint refuses to start rather than booting an
application that cannot decrypt sessions, Slack webhook URLs or stored AI credentials.

**Solution**

```bash
php artisan key:generate --show      # prints base64:...
```

Set it as an environment variable on every service.

**Verify** — The container starts and `/health` returns 200.

> [!WARNING]
> **Never rotate `APP_KEY` casually on a running deployment.** It decrypts Slack webhook URLs
> and stored AI credentials. Rotating it makes both unreadable — and by design they degrade
> to "not configured" rather than throwing, so the symptom is **silence**: Slack simply stops
> posting and the AI simply stops working.

## Database connection failure

**Problem** — `/health` reports `database: false`; every page 500s.

**Cause** — Wrong credentials, wrong host, or the database is not reachable from the
container.

**Solution** — Check `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`. On Railway, use the private network host rather than the public proxy.

**Verify**

```bash
php artisan tinker --execute="DB::connection()->getPdo(); echo 'ok';"
curl -s https://<APP_URL>/health
```

## Migration fails with "Base table or view not found: cache_locks"

**Problem** — The first deploy against an empty database restart-loops.

**Cause** — `migrate --isolated` takes its lock from the default cache store, which on the
`database` store lives in `cache_locks` — a table created by a migration. On an empty
database the lock cannot be taken, because the table it needs is one of the tables it is
guarding.

**Solution** — The entrypoint already handles this: it probes with `migrate:status` and runs
the first migration **unisolated**. If you are migrating by hand, do the same:

```bash
php artisan migrate --force            # first run, empty database
php artisan migrate --force --isolated # every run after
```

**Verify** — `php artisan migrate:status` lists every migration as ran.

## The queue is not processing

**Problem** — AI runs stay "Queued" for ever, Slack messages never arrive, no SMS is sent,
pull requests never link to tickets, and realtime updates never appear. **Nothing errors.**

**Cause** — No worker, or a worker listening to the wrong queues. A bare `queue:work` listens
only to `default`.

**Solution**

```bash
php artisan queue:work --queue=ai,default --tries=3 --max-time=3600
```

In production, the worker service runs that command and the **web** container separately runs
`--queue=attachments`. Locally, include all three.

**Verify**

```bash
php artisan queue:monitor default,ai
php artisan tinker --execute="echo DB::table('jobs')->count();"
```

## AI attachments stay "pending" for ever

**Problem** — Uploaded files never reach `ready`, or settle into `failed` with no exception
and nothing in the log.

**Cause** — Either no worker is serving the `attachments` queue, or — on a volume-backed
disk — the worker is in the **wrong container**. A Railway volume attaches to exactly one
service; the upload request writes the file in the web container, and a worker elsewhere asks
its own `/data` and finds nothing. A missing object is a legitimate condition in the reader,
so it fails silently.

**Solution** — Keep the `attachment-worker` supervisor program in the **web** container. If
the disk is S3, you may instead set `AI_ATTACHMENTS_QUEUE=default` and add it to the worker
service — but never serve it from both.

**Verify**

```bash
php artisan tinker --execute="echo App\Models\AiAttachment::where('status','pending')->count();"
```

## AI runs are stuck at "Running"

**Problem** — A ticket shows a run in progress indefinitely, blocking further runs.

**Cause** — The worker died mid-run: a deploy, a container restart or an OOM. The run is
owned by the job that claimed it, so nothing can finish it.

**Solution**

```bash
php artisan ai:reap-stalled
```

It runs automatically every ten minutes and refuses to touch a run that still has a job on
the queue.

**Verify** — The run moves to `failed` with a reason, and a new run can be started.

## AI runs are refused

**Problem** — The button is disabled, or an internal note says the run was refused.

**Causes and fixes**

| Message | Cause | Fix |
|---|---|---|
| "…set to AI Observer… writes nothing" | Capability mode too low | Raise to AI Operator on `/admin/ai` or the board |
| "Apply mode… needs AI Agent" | Apply requires the top mode | Raise to AI Agent |
| "Automatic runs… need AI Agent" | Unattended work requires AI Agent | Raise, or run manually |
| Cap reached | Daily run cap | Wait for the board's own midnight, or raise `daily_auto_run_cap` |
| Coding runtime unavailable | `AI_CODE_DRIVER=unavailable` | Set `AI_CODE_DRIVER=claude_code`, ensure the binary and `ANTHROPIC_API_KEY` are in the **worker's** environment |
| No credential | No key configured | Set one in `/admin/ai`, or `ANTHROPIC_API_KEY` |

## GitHub webhook returns 401

**Problem** — GitHub shows a red cross; no links appear on tickets.

**Cause** — `GITHUB_WEBHOOK_SECRET` is unset or does not match the repository's webhook.
**Unset means every delivery is refused** — that is the only safe default.

**Solution** — Set the same secret in both places. Deliver only `application/json`.

**Verify** — Redeliver from GitHub's webhook settings; expect **202 Accepted**. A ping should
return 200.

## GitHub webhook returns 202 but nothing links

**Problem** — Deliveries succeed, no `github_links` rows appear.

**Causes**

| Cause | Fix |
|---|---|
| The repository is not attached to any board | Add it on `/boards/{board}/ai/settings`. **This is the security boundary** — an unattached repository resolves to no boards at all |
| No ticket key in the branch name or commit messages | Use `NL-42` style references |
| The prefix does not match a board | Check the board's `ticket_prefix` |
| The queue worker is not running | See above |
| The event is not one of the five understood | Only `push`, `create`, `pull_request`, `check_suite`, `status` |

**Verify**

```bash
php artisan tinker --execute="print_r(App\Models\WebhookDelivery::latest()->take(5)->get(['event','status','links_written','error'])->toArray());"
```

## Slack notifications never arrive

**Problem** — No messages in the channel.

**Causes**

| Check | Where |
|---|---|
| `SLACK_NOTIFICATIONS_ENABLED=true` | Environment |
| The board's master switch is on | `/boards/{board}/integrations` |
| The webhook URL is configured | Same screen — it is **write-only**, so it shows "configured", never the value |
| The board subscribes to that event | Same screen. `ai_run_finished` is **off** by default |
| A worker serves `default` | See above |
| `APP_KEY` has not been rotated | Rotation makes stored URLs unreadable, degrading silently to "not configured" |

**Verify** — `SlackNotifier` never throws, so failures appear only in the log. Check the
application log for Slack delivery errors.

## Storage configuration failure

**Problem** — Production refuses to boot, with a durability error.

**Cause** — `FILESYSTEM_DISK` / `ATTACHMENT_DISK` is `local`, or is `volume` while the volume
is **not actually mounted**.

**Solution** — Use `s3` (any S3-compatible provider), or mount a Railway volume and set
`ATTACHMENT_VOLUME_PATH` to its path.

**Verify** — The container boots. Upload a file and download it back.

> [!NOTE]
> This refusal is deliberate. Writing attachments into the container would lose every file at
> the next deploy — silently — which is the exact failure the check exists to prevent.

## File uploads return 500, or silently vanish

**Problem** — Small forms work; uploading a file fails. In Livewire the composer simply
empties with no error.

**Cause** — nginx buffers any request body larger than `client_body_buffer_size` to disk, and
the temp paths must be writable by `www-data`. The Alpine package's default paths are owned
by the `nginx` user instead.

**Solution** — The Dockerfile and entrypoint both create and chown
`/var/cache/nginx/{client_body,fastcgi}`. If you have customised either, keep that. Also
check `client_max_body_size` (32m), `upload_max_filesize` (32M) and `post_max_size` (34M)
against your attachment ceiling.

**Verify** — Upload a file larger than 16 KB to a ticket.

## Realtime never updates

**Problem** — Boards and the bell only refresh on reload.

**Causes**

| Cause | Fix |
|---|---|
| The image was built without `VITE_REVERB_APP_KEY` | **Rebuild** — these are build arguments, not runtime variables |
| The Reverb service has no public domain | Browsers must reach it directly |
| `REVERB_HOST`/`PORT`/`SCHEME` point at the wrong place | Should be the public domain, 443, https |
| More than one Reverb replica | Reverb holds connections in process memory. Use one replica, or `REVERB_SCALING_ENABLED=true` plus Redis |
| `BROADCAST_CONNECTION=null` | Set it to `reverb` |
| No worker on `default` | Broadcasts are queued |

**Verify** — The browser console shows a WebSocket connection; a change made in one tab
appears in another.

## Railway deployment fails the health check

**Problem** — The deploy never goes live.

**Causes** — `APP_KEY` missing (the entrypoint aborts); the database is unreachable; the
storage durability check refuses; or `PORT` is not being honoured.

**Solution** — Read the deploy logs; the entrypoint prints each step. `/health` returns
booleans for the database and cache.

**Verify**

```bash
curl -s https://<APP_URL>/health
curl -s https://<APP_URL>/up
```

## Missing environment variables

**Problem** — A feature is quietly absent rather than broken.

**Cause** — This application's design is to **degrade honestly**: an unconfigured integration
reports itself unconfigured rather than failing.

| Symptom | Missing |
|---|---|
| AI refuses with "not configured" | `ANTHROPIC_API_KEY` / `OPENAI_API_KEY`, or a stored credential |
| The voice control is disabled | `OPENAI_API_KEY` |
| Audio attachments say transcription is not configured | `OPENAI_API_KEY` |
| Apply mode fails immediately | `AI_CODE_DRIVER`, `GITHUB_TOKEN` |
| Every webhook is refused | `GITHUB_WEBHOOK_SECRET` |
| Slack is silent | Per-board webhook URL |
| SMS records show `failed` with an explanation | `SMS_DRIVER`, `SMS_46ELKS_*` |
| External knowledge lookups say they are unavailable | `AI_KNOWLEDGE_DRIVER` — **no driver ships** |

## Configuration changes have no effect

**Problem** — An environment variable was changed but nothing behaves differently.

**Cause** — The entrypoint caches configuration, routes, views and events at boot.

**Solution** — Restart the service. Locally:

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear
```

**Verify** — `php artisan tinker --execute="echo config('ai.modes.default');"`

<!-- pagebreak -->
