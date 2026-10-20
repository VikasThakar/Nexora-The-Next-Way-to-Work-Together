# Notifications, Queues and Scheduled Work

## Why background workers are required

> [!WARNING]
> **Without a queue worker, several features silently do nothing — and nothing errors,
> because all of it is queued by design.** Realtime updates never arrive, no Slack message is
> posted, no SMS is sent, no pull request is ever linked to a ticket, and every AI run stays
> queued for ever.
>
> This is the single most common misconfiguration for this application.

## The three queues

| Queue | Carries | Where the worker runs | Why |
|---|---|---|---|
| `ai` | `ExecuteAiRunJob` | The **worker** service | An AI run takes minutes. A worker chewing through a repository clone must never delay a broadcast or a notification |
| `default` | Broadcasts, in-app notifications, GitHub webhook processing, Slack messages, SMS | The **worker** service | Millisecond work |
| `attachments` | `ProcessAiAttachment`, `ImportDocumentFile` | **Inside the web container** | See below |

```diagram
   RAILWAY SERVICES
   ----------------

   web  (railway.json)                      worker  (railway.worker.json)
   +-----------------------------+          +------------------------------+
   | supervisord                 |          | php artisan queue:work       |
   |   php-fpm                   |          |   --queue=ai,default         |
   |   nginx                     |          |   --tries=3 --max-time=3600  |
   |   queue:work                |          +------------------------------+
   |     --queue=attachments     |
   |     --tries=2 --max-time=3600|         reverb  (railway.reverb.json)
   +-----------------------------+          +------------------------------+
                                            | php artisan reverb:start     |
   Same image, three start commands.        | 1 replica only               |
                                            +------------------------------+
```

> [!IMPORTANT]
> **`ProcessAiAttachment` runs inside the web container, and the reason is the disk rather
> than the workload.** The job opens the file that was just uploaded. On a deployment whose
> attachment disk is `volume`, those bytes are on a Railway volume — and **a volume attaches
> to exactly one service**. The web service and the worker service each have their own, both
> mounted at `/data`, so `volume` resolves to a different filesystem in each container. The
> upload request writes the file in the web container; a worker in the other container asks
> its own `/data` and finds nothing.
>
> Worse, a missing object is a *legitimate* condition in the reader, so the row settles into
> `failed` with no exception, no retry and nothing in the log to chase — the card simply says
> the file could not be read.
>
> An S3-backed deployment has no such constraint. To consolidate, set
> `AI_ATTACHMENTS_QUEUE=default`, add it to the worker's `--queue` list, and remove the
> supervisor program.
>
> `attachments` is deliberately **not** in the worker's queue list. Adding it in both places
> would mean two workers racing for the job and the wrong one winning about half the time.

The `ai` queue must be **named explicitly and is listed first**. A worker started with a bare
`queue:work` listens only to `default` and would leave every AI run queued for ever.

## Jobs

| Job | Queue | Tries | Timeout | Notes |
|---|---|---|---|---|
| `ExecuteAiRunJob` | `ai` | 3 | 900s | `WithoutOverlapping` lock plus an authoritative status claim; backoff 30s, 120s |
| `ProcessAiAttachment` | `attachments` | 2 | 600s | Reads, extracts, transcribes or re-encodes an upload; also keeps a document as page Markdown for the assistant's docs import |
| `ImportDocumentFile` | `attachments` | 1 | 600s | Imports a documentation file over 8 MB as a page off the web request; reports to the screen through the cache (`doc-import:{id}`) and removes the stored file if it cannot be read |
| `ProcessGitHubWebhookJob` | `default` | 3 | 60s | Parses a verified delivery into links |
| `SendSmsJob` | `default` | 3 | — | Backoff 15s, 60s, 180s |

Slack messages are queued through Laravel's notification system on `default`, with 3 tries
and backoff 10s, 60s, 180s.

## Failed jobs

Failed jobs are recorded in `failed_jobs` and **are not retried automatically**. An AI run or
an SMS that exhausted its attempts has already recorded why on its own row, and blindly
replaying a batch of them would re-post internal notes and re-send messages.

The table is pruned weekly at 168 hours — long enough to investigate a Monday failure on the
following Friday.

## Scheduled tasks

Run by `php artisan schedule:work` on the **worker** service, or by a Railway cron job
invoking `schedule:run`. Running it on a web service with several replicas would run every
task once per replica.

| Schedule | Command | Purpose |
|---|---|---|
| Daily 03:15 | `workspace:prune` | Webhook deliveries past 30 days; SMS records past 90 days |
| Every 10 minutes | `ai:reap-stalled` | Recover runs stranded by a worker that died mid-run |
| Weekly | `queue:prune-failed --hours=168` | Keep `failed_jobs` readable |
| Daily | `auth:clear-resets` | Expired password-reset tokens |
| Daily | `queue:prune-batches` | Batch records |

All carry `->onOneServer()`, which needs a **shared cache store (Redis)** to mean anything on
a multi-replica deployment.

> [!NOTE]
> Everything scheduled is housekeeping. **Nothing a user waits for is scheduled, and nothing
> here is required for the application to function** — a deployment with no scheduler works,
> it simply accumulates rows. The one exception worth watching is `ai:reap-stalled`: without
> it, a run stranded by a deploy shows "Running" for ever and blocks further runs on that
> ticket.
>
> `workspace:prune` deletes in **chunks** rather than with one unbounded `DELETE`, because a
> single statement removing a year of rows takes a lock long enough to be noticed by
> everything else using the table.

## Console commands

| Command | Purpose |
|---|---|
| `workspace:prune` | Retention enforcement for webhook deliveries and SMS records |
| `ai:reap-stalled` | Recover stranded AI runs; refuses to touch a run that still has a job on the queue |
| `workspace:icons` | Generate favicons; `--check` reports whether they exist without rewriting |

## In-app notification architecture

`App\Services\NotificationDispatcher` holds one rule:

> A notification is only written for a recipient who passes **the same policy check that
> guards the thing being notified about**.

That is deliberately a policy call and not a fresh set of conditions. *"May this person see
this comment?"* already has an answer, in `CommentPolicy`, which delegates the ticket half to
`TicketPolicy` and the board half to `BoardAccess`. Re-deriving it would create a second,
unreviewed definition of the customer boundary — and the notification bell is exactly the
kind of peripheral surface where such a copy goes stale.

A customer therefore **cannot** be notified about an internal note even if something upstream
tried to: the gate check fails and no row is written.

Notifications are stored in the `notifications` table (`morphs('notifiable')`, `read_at`) and
read back through `NotificationReader`, which re-checks every subject against the visibility
scopes before returning a single line.

<!-- pagebreak -->

# Files and Attachments

## Two pipelines, one storage service

| | Record attachments | AI conversation attachments |
|---|---|---|
| Owners | Ticket, Comment, DocPage | AiSession |
| Config | `config/attachments.php` | `config/ai.php` → `attachments` |
| Size ceiling | 10 MB | 20 MB |
| Count ceiling | 20 per owner | 10 per conversation |
| Read into a prompt? | **No** | **Yes** |
| Type check | Extension allow-list | Extension **and** detected MIME |
| Storage | `AttachmentStorage` | `AttachmentStorage` |

Both go through `App\Services\AttachmentStorage`, so there is exactly one answer to "where do
uploaded files live" and one production durability check guarding it.

## Storage rules

| Rule | Why |
|---|---|
| The stored path is **generated**, never derived from the uploaded filename | A user-supplied name can contain traversal sequences, null bytes or an executable extension. The original is kept only as a display label |
| The **disk is written onto every row** | Changing the application default later cannot orphan files uploaded before the change |
| Files are laid out **per board** | Keeps a board's data together for deletion and for any future per-tenant bucket policy |

## Disks

| Disk | Durable? | Use |
|---|---|---|
| `local` | **No** | Development only. Refused in production |
| `volume` | Only while a persistent volume is actually mounted | Railway volume. **The mount is verified at boot** |
| `s3` | Yes, by construction | AWS S3, Cloudflare R2, MinIO, Backblaze B2 — anything S3-compatible |

`ATTACHMENT_DISK` falls back to `FILESYSTEM_DISK`, so one setting moves everything at once,
but the attachment disk can be pointed at a dedicated bucket without touching the rest of the
application.

> [!WARNING]
> A **production** boot with a non-durable attachment disk is **refused outright**. For
> `volume`, being named in `durable_disks` is not sufficient proof — the check stats the root
> and refuses to boot when the mount is absent. Otherwise a forgotten volume writes into the
> container and loses every file at the next deploy, silently, which is the exact failure the
> list exists to prevent.

Cloudflare R2 works as an `s3` disk: set `AWS_ENDPOINT` to the R2 endpoint,
`AWS_USE_PATH_STYLE_ENDPOINT` as required, and the standard key, secret, bucket and region
variables.

## Download security

```diagram
   GET /attachments/{attachment}
            |
            v
   AttachmentPolicy::view
            |
            +── resolve the owner from the allow-list
            |     (Ticket | Comment | DocPage | AiSession)
            |     unknown type ──► 404
            |
            +── ask the OWNER's policy  ──► not allowed ──► 404
            |
            v
   S3-compatible disk?
            |
      +-----+------+
      | yes        | no (local, development)
      v            v
   302 redirect   stream the file through PHP
   to a SIGNED URL
   valid 5 minutes
```

**Never link a browser straight at the stored object.** Every download passes through the
controller so the request is authorized against the owning record first — which means an
attachment on an internal ticket is exactly as protected as the ticket.

Signed URLs are short-lived on purpose: the link carries **no further authorization**, so it
should outlive the click and little else. `ATTACHMENT_URL_TTL_MINUTES` defaults to 5.

A **soft-deleted owner does not resolve**, which is what makes the files on a deleted comment
unreachable without any extra bookkeeping.

## Allowed types

**Record attachments** (`config/attachments.php`):

`jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `pdf`, `txt`, `md`, `csv`, `doc`, `docx`, `xls`,
`xlsx`, `ppt`, `pptx`, `zip`, `log`, `json`, `yml`, `yaml`

**AI conversation attachments** — narrower, because these are *read* and put in front of a
language model:

| Kind | Extensions |
|---|---|
| text | `txt`, `log`, `json`, `yml`, `yaml` |
| markdown | `md`, `markdown` |
| csv | `csv`, `tsv` |
| pdf | `pdf` |
| image | `png`, `jpg`, `jpeg`, `gif`, `webp` |
| audio | `mp3`, `m4a`, `wav`, `ogg`, `webm`, `mp4` |
| document | `docx`, `xlsx`, `pptx` |

> [!NOTE]
> Two absences are deliberate. **`svg`** is a document that can carry script, not a picture —
> it is allowed as a record attachment, where it is only ever served as a download, but not
> as an AI attachment, where an "image" is decoded and sent to a vision model. **`zip`** would
> be a container whose contents nothing has validated.

## Upload buffering

nginx buffers a request body larger than `client_body_buffer_size` — which any uploaded file
is — to a file under `client_body_temp_path`, and a large FastCGI response likewise. Those
paths must be writable by `www-data`, the user the nginx workers run as.

This is created and chowned in the Dockerfile **and repeated at boot** by the entrypoint,
because nginx chowns a temp path it creates itself but silently uses a pre-existing one
as-is. Getting it wrong meant every file upload returned 500 while every ordinary request
worked — which in a Livewire application looks like the file simply vanishing, since a failed
upload leaves no error on the page.

`client_max_body_size` is 32 MB, which must stay above the largest configured attachment
ceiling.

<!-- pagebreak -->
