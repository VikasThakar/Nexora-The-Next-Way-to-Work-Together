# GitHub Integration

Two features that look unrelated but share one credential:

| Direction | What it does | Class |
|---|---|---|
| **Inbound** | GitHub tells Nexora about branches, commits, pull requests and CI that mention a ticket key | `GitHubWebhookController`, `WebhookProcessor` |
| **Outbound** | Apply mode pushes a branch and opens a draft pull request | `PullRequestClient` |

Both are off until credentials exist, and both fail loudly rather than pretending: with no
token the pull-request client reports itself unconfigured, and with no webhook secret the
endpoint rejects every delivery rather than trusting unsigned input.

## Inbound: how a reference becomes a link

```diagram
  Developer                GitHub                    Nexora
  ---------                ------                    ------
  git checkout -b
    NL-42-fix-login
        |
        v
  git push  ─────────────► push event
                              |
                              | POST /webhooks/github
                              | X-Hub-Signature-256: sha256=...
                              v
                        ┌─────────────────────────────────────────┐
                        │ 1. throttle:github-webhooks (300/min)   │
                        │ 2. HMAC-SHA256 verify the RAW body      │◄─ 401 if wrong
                        │ 3. INSERT webhook_deliveries            │◄─ 200 if replay
                        │    (unique index = the claim)           │
                        │ 4. dispatch ProcessGitHubWebhookJob     │
                        │ 5. respond 202                          │
                        └─────────────────────────────────────────┘
                                        |  queue: default
                                        v
                        ┌─────────────────────────────────────────┐
                        │ WebhookProcessor                        │
                        │                                         │
                        │ repository "acme/app" is matched against│
                        │ board_repositories                      │
                        │        │                                │
                        │        ├─ no match ──► 0 links, ignored │
                        │        v                                │
                        │ those boards are the ONLY boards in     │
                        │ scope                                   │
                        │        |                                │
                        │ extract "NL-42" from the branch name    │
                        │ and every commit message                │
                        │        |                                │
                        │ resolve prefix NL + number 42 on those  │
                        │ boards                                  │
                        │        v                                │
                        │ upsert github_links, keyed on           │
                        │ (ticket, repository, type, external_id) │
                        │        v                                │
                        │ write a ticket event only if something  │
                        │ ACTUALLY CHANGED                        │
                        └─────────────────────────────────────────┘
                                        |
                                        v
                        Ticket NL-42 timeline shows the branch,
                        the commits, the pull request and CI state
```

### The reference pattern

`/\b([A-Za-z][A-Za-z0-9]{1,5})-(\d{1,9})\b/` — a board prefix, a hyphen, a number.

Case-**insensitive** here, unlike in prose, because branch names are typed by hand at a
terminal and `aqd-42-fix` is what people actually write.

> [!IMPORTANT]
> **The security boundary is the repository, not the pattern.** A webhook is an anonymous
> request whose only credential proves GitHub sent it — it does **not** prove who owns the
> repository it is about. Anybody can add a webhook to a repository they control and point it
> here, and with the secret they could deliver a valid signed payload claiming a commit in
> `attacker/anything` fixes `AQD-1`.
>
> So a delivery may only reach tickets on boards where an administrator has **already
> attached that repository**. The repository name in the payload is matched against
> `board_repositories`, and the resulting board ids are the *only* boards in scope. A
> repository nobody has configured resolves to nothing and the delivery is recorded as
> ignored.
>
> Note what is deliberately absent: any `visibleTo()` scoping. There is no viewer — a webhook
> is a system actor with no user — so the ordinary visibility scopes have nothing to apply.
> The board restriction replaces them, and it is **narrower**: not "boards somebody can see"
> but "boards this specific repository is wired into".

### Events understood

| Event | What is linked | Detail |
|---|---|---|
| `push` | Branch and commits | The **branch name is checked as well as each commit message**, because the convention most teams use is to put the key in the branch once rather than in every commit |
| `create` | Branch | Only when `ref_type` is `branch`; tags have no place on a ticket |
| `pull_request` | Pull request | References taken from the title, the body and the head branch, in that order of likelihood. Tracks opened, edited, closed, merged, ready-for-review |
| `check_suite` | CI status on an existing link | — |
| `status` | CI status on an existing link | — |

Anything else is acknowledged with a 202 and ignored. GitHub retries on a non-2xx, and
retrying a delivery we will never act on helps nobody.

### Link types and state

| `github_links` column | Values |
|---|---|
| `type` | `branch`, `commit`, `pull_request` |
| `state` | `open`, `draft`, `merged`, `closed` |
| `ci_status` | The reported conclusion |
| Also stored | `reference`, `title`, `url`, `author_login`, `merged_at`, `metadata` |

Three link types and deliberately no more. A ticket's engineering trail is "somebody started
a branch, commits landed on it, a pull request was opened" — anything further (reviews,
comments, deployments) belongs in GitHub, and mirroring it here would make the panel a worse
copy of a better tool.

### Idempotence and de-duplication

Two independent mechanisms, because GitHub redelivers on timeout and replays on demand:

| Level | Mechanism |
|---|---|
| **Delivery** | A unique index on `(source, delivery_id)`. **The insert is the claim** — atomic across web containers, which a "select then insert" would not be. A duplicate key is the expected outcome for a replay, not an error |
| **Link** | A unique index on `(ticket, repository, type, external_id)`, so a replay updates the row it wrote the first time |

Ticket events are written **only when something actually changed** — a row that was just
created, or whose state or CI conclusion actually moved. GitHub sends a `check_suite` for
every push; an event per delivery would bury the ticket's own history within a day.

### Bounds

| Bound | Default | Variable |
|---|---|---|
| Ticket references per delivery | 50 | `GITHUB_MAX_REFERENCES` |
| Commits linked per push | 20 | `GITHUB_MAX_COMMITS` |
| Delivery-record retention | 30 days | `GITHUB_WEBHOOK_RETENTION_DAYS` |
| Requests per minute | 300 | `GITHUB_WEBHOOK_RATE_LIMIT` |
| Job attempts / timeout | 3 / 60s | `GITHUB_JOB_TRIES`, `GITHUB_JOB_TIMEOUT` |

A branch merge can carry hundreds of commits each mentioning a key; the cap stops one
delivery writing an unbounded number of rows.

## Outbound: opening a pull request

Used only by apply mode. See section 11 for the full run pipeline.

```diagram
  Apply run                                        GitHub
  ---------                                        ------
  clone (shallow, depth 1)      ◄────────────────  repository
  refuse if base branch is protected
  create branch  ai/NL-42-<uuid>
  coding runtime edits files
  re-derive the diff from `git status`
  run AI_VALIDATION_COMMANDS
        │ non-zero exit ──► FAIL, nothing pushed
        v
  commit as "Workspace AI"
  push refs/heads/ai/NL-42-<uuid>  ──────────────► new branch only
  POST /repos/{owner}/{name}/pulls ──────────────► DRAFT pull request
        │
        v
  internal note on NL-42 with the PR link
```

`draft: true` by default, so a pull request arrives **visibly unfinished** rather than
looking like a colleague's finished work waiting on a rubber stamp.

| Setting | Default | Variable |
|---|---|---|
| Branch prefix | `ai/` | `AI_BRANCH_PREFIX` |
| Commit author | `Workspace AI <ai@example.invalid>` | `AI_COMMIT_AUTHOR_NAME`, `AI_COMMIT_AUTHOR_EMAIL` |
| Clone depth | 1 | `AI_CLONE_DEPTH` |
| Git timeout | 300s | `AI_GIT_TIMEOUT` |
| Validation commands | none | `AI_VALIDATION_COMMANDS` (pipe-separated) |
| Validation timeout | 900s | `AI_VALIDATION_TIMEOUT` |
| Protected branches | `main`, `master`, `production`, `release`, `develop` | not overridable |

## Security model

| Control | Implementation |
|---|---|
| Inbound authentication | HMAC-SHA256 over the raw body, constant-time comparison, SHA-256 only, fail-closed with no secret |
| Inbound authorization | Repository must be attached to a board. No other board is reachable |
| Inbound rate limiting | 300/min per IP, applied **before** signature verification touches the database |
| CSRF | Exempt for this one path only; replaced by the signature, which is a stronger claim |
| Outbound token scope | `contents: write` and `pull_requests: write` only. **Never merge rights** |
| Merge capability | **Absent from the codebase** — `PullRequestClient` has no merge method |
| Token exposure | Read from config at call time, never stored on the object, never logged, never interpolated into an error message — failure text is rebuilt from the HTTP status |
| Customer visibility | GitHub links are internal. `GithubLinkReader` refuses customers; `get_code_activity` and `get_github_repository` are withheld from them |
| Timeouts | 20s for API calls (`GITHUB_REQUEST_TIMEOUT`), 60s for the webhook job |

<!-- pagebreak -->

# Slack Integration

## Design

Slack is configured **per board rather than per workspace**, because that is how the product
is used: each customer's board belongs to a different delivery team with a different channel,
and a single workspace-wide webhook would put every customer's tickets in one room.

The webhook URL therefore lives on the **board**, encrypted. What lives in
`config/slack.php` is the behaviour that must not differ per board: whether the integration
is available at all, how long to wait, and how hard to retry.

## Flow

```diagram
  Ticket or comment or AI run changes
              |
              v
  TicketObserver / CommentObserver / AI result action
              |
              v
      SlackNotifier            ── never throws; a failure is a log line
              |
              +─ Is SLACK_NOTIFICATIONS_ENABLED on?     no ──► stop
              +─ Does this board have a webhook URL?    no ──► stop
              +─ Is the board's master switch on?       no ──► stop
              +─ Does the board want THIS event?        no ──► stop
              |
              v
      Notification queued on `default`
              |
              v
      SlackWebhookChannel  ── decrypts the URL, POSTs Block Kit JSON
              |                 timeout 8s, 3 tries, backoff 10s / 60s / 180s
              v
      Slack channel:   [NL-42]  Login fails on Safari
                       Raised by Priya Raman  ·  Open in Nexora →
```

## Events

| Event | Default | Fires when |
|---|---|---|
| `customer_ticket_raised` | **on** | Somebody outside the delivery team files a new ticket |
| `customer_comment_posted` | **on** | A customer replies in the customer conversation. **Internal notes are never announced** |
| `ticket_moved_to_done` | **on** | A ticket reaches a column marked `is_done` |
| `ai_run_finished` | **off** | An AI run completes or fails. The analysis itself stays in the internal note |

A board that has never opened the integrations screen does **nothing** — the board-level
default for `enabled` is `false`. A board with no webhook URL cannot post anyway, and
defaulting the events to "on" would mean the first team that pastes a URL immediately gets
every event rather than choosing.

Muting is available per event, per board, and for the whole board with one switch.

> [!NOTE]
> `critical_ticket_raised` is deliberately **not** a Slack event. An SMS is an interruption
> chosen deliberately for the few things worth waking somebody for, and offering it as "just
> another Slack event" would blur that. A critical ticket still produces the ordinary
> ticket-raised message in Slack.

## What a message contains, and why it is thin

> [!IMPORTANT]
> A Slack channel is **outside this application's authorization model**. Whoever is in the
> room reads the message, and Nexora has no idea which of them is a board member, which is a
> customer and which is a contractor added last week.
>
> So a message carries an **identifier, a title, who did it, and a link** — never a ticket
> description, a comment body or an AI summary. **The link is the access control**: following
> it requires logging in, and the ordinary visibility rules then apply to whatever they see.

The `text` field is not an ignored fallback: it is what appears in the desktop notification
and the channel-list preview, and a Block Kit message without it shows up as "This content
can't be displayed".

## Configuration and security

| Control | Value |
|---|---|
| Master switch | `SLACK_NOTIFICATIONS_ENABLED`, default `true` |
| Per-board switch | `boards.settings.slack.enabled`, default `false` |
| Webhook URL storage | **Encrypted** with `APP_KEY` in `boards.settings.slack` |
| Webhook URL in the UI | **Write-only** — blank on every load; blank means "leave as is"; removal is a separate explicit button |
| Delivery | Always queued on `default` |
| Timeout / tries / backoff | 8s / 3 / 10s, 60s, 180s |
| Channel name | `slack_webhook`, deliberately not `slack` — that name is reserved for `laravel/slack-notification-channel`, and a collision would make it impossible to add later without silently rerouting every existing notification |

The master switch is useful for a staging deployment restored from a production database
dump, which would otherwise start posting into the real team's channel within minutes of
booting.

Rotating `APP_KEY` makes stored webhook URLs unreadable. That is the correct failure, and it
degrades to "not configured" rather than throwing — a board with an unreadable URL simply
stops posting instead of breaking every ticket save.

<!-- pagebreak -->

# SMS Alerts

## Design

An SMS is the most intrusive notification this application can send: it reaches a person who
is not at a computer, possibly asleep, and it costs money per message. So it has **exactly
one trigger — a critical ticket being raised — and it is off until somebody deliberately
turns it on**.

`SMS_ENABLED` defaults to `false`.

## Providers

| Driver | Behaviour |
|---|---|
| `unavailable` *(default)* | Every send is refused with a message naming exactly what to configure, and recorded as **failed**. No message is claimed to have been sent |
| `log` | Writes to the application log and records status `logged`, **never `sent`** — a dashboard counting these as delivered would be lying about whether an on-call engineer was reached |
| `46elks` | The real provider. Needs `SMS_46ELKS_USERNAME`, `SMS_46ELKS_PASSWORD` and a sender in `SMS_FROM` |

An unrecognised value resolves to `unavailable`, so a typo in a deployment variable cannot
silently disable alerting while appearing to work.

## Recipients

Workspace-wide fallback numbers in E.164 form (`SMS_ALERT_RECIPIENTS`, comma-separated). A
board may override these with its own on-call list on the integrations screen.

Numbers rather than users, on purpose: **on-call is a rota, not a role**, and the person
carrying the phone this week is often not the person whose account raised the board.

A hard ceiling of `SMS_MAX_RECIPIENTS` (5) applies whatever a board is configured with — an
alert that texts thirty people is a phone tree, not an alert, and also thirty times the cost
of a mistake.

## Duplicate suppression

| Guard | Mechanism |
|---|---|
| Per ticket, per number | A **unique index** on `sms_messages(event, ticket_id, recipient)` — the guarantee has to survive a cache flush and a race between two workers, and only the database can promise that |
| Per board | A cooldown window, `SMS_BOARD_COOLDOWN` (300s). If a script files forty critical tickets in a minute, the on-call phone rings once, not forty times |

## Data protection

Each `sms_messages` row holds a **phone number**, which is personal data, so the table has a
retention window rather than growing for ever. `SMS_RETENTION_DAYS` defaults to 90, enforced
by the daily `workspace:prune` command.

Ninety days keeps the evidence useful for the question it exists to answer — *"was the
on-call rota reached about that incident?"* — without keeping it indefinitely.

On the integrations screen the rota field is **readable** (somebody has to be able to remove
a colleague's number when they leave the rota), but the delivery **history** below it is
masked — it is a growing log that accumulates numbers no longer on the rota, scanned to
answer "was anybody reached?", a question that does not need the digits.

## Other controls

| Control | Value |
|---|---|
| Message length | Truncated at `SMS_MAX_LENGTH` (300) — an SMS is billed per 160-character segment |
| Sender | `SMS_FROM`, alphanumeric (max 11 chars) or E.164. Alphanumeric senders cannot receive replies, which is right for an alert |
| Delivery | Queued on `default`, 3 tries, backoff 15s / 60s / 180s |
| Timeout | 15s |
| Delivery receipts | `SMS_46ELKS_DLR_URL` may be set, but **this application does not expose a receiving endpoint** — *Planned / Not Yet Implemented* |
| Credential exposure | Rebuilt failure text from the HTTP status rather than the response body, which on an auth failure can echo the username back |

Like the AI trigger, `AlertCriticalTicket` **never throws**. It is called from the ticket
observer inside the transaction that creates the ticket, and a critical ticket that exists
and did not page anybody is far better than a critical ticket that failed to be filed.

<!-- pagebreak -->
