# Aqueduct Workspace

A shared development workspace: Jira-style Kanban boards, Confluence-style documentation, delivery
statistics and AI automation, built so that customers and internal staff can work in the same
product without customers ever observing internal content.

**Feature-complete.** Boards, tickets, two-stream conversations, documentation, attachments,
realtime, notifications, AI automation, delivery statistics, GitHub linking, Slack notifications and
critical-ticket SMS. The features that talk to third parties are off until credentials are supplied,
and each says so rather than pretending — see [Features requiring credentials](#features-requiring-credentials).

---

## Stack

| Layer | Choice |
| --- | --- |
| Framework | Laravel 12 (PHP 8.2+) |
| Database | MySQL 8 |
| Frontend | Blade + Livewire 4 + Alpine.js + Tailwind CSS 4 |
| Build | Vite |
| Charts | Server-rendered inline SVG and CSS — **no charting library** |
| Queue | Laravel Queue (`database`, or Redis) — AI runs on their own `ai` queue |
| AI | Anthropic Messages API via the official `anthropic-ai/sdk`, behind `AiProviderInterface` |
| GitHub | Signed inbound webhooks; outbound REST for apply-mode pull requests |
| Slack | Per-board incoming webhooks, via a custom Laravel notification channel |
| SMS | 46elks, behind `SmsProviderInterface` |
| Realtime | Laravel Reverb + Echo (optional; the app works with it switched off) |
| Storage | S3-compatible object storage (never local disk in a deployed environment) |
| Hosting | Railway (Docker) |

There is deliberately **no JavaScript charting library**. Every chart on the statistics screens is a
Blade component that emits inline SVG or a row of sized elements
([`resources/views/components/charts/`](resources/views/components/charts/)). The numbers are
already computed on the server, so shipping a library to draw a bar of a given width would be a
dependency bought for nothing — and it keeps the figures as real text, which is selectable,
searchable and readable by a screen reader in a way a canvas is not.

---

## Architecture at a glance

```
app/
├── Actions/        one class per write. Business rules live here, not in components
│   ├── AI/         run lifecycle, board AI settings, chat action execution
│   ├── Boards/  Columns/  Comments/  Docs/  Labels/  Tickets/  Users/
│   └── Notifications/   critical-ticket alerting, board integration settings
├── Console/Commands/    workspace:prune
├── Enums/          every closed set: roles, priorities, streams, run states, link types
├── Events/         board and notification broadcasts (content-free)
├── Http/
│   ├── Controllers/     health, attachment download, GitHub webhook
│   └── Middleware/      role gate, active-user gate
├── Jobs/           ExecuteAiRunJob, ProcessGitHubWebhookJob, SendSmsJob
├── Livewire/       one directory per screen area, incl. Stats/
├── Models/         thin; visibility scopes and relations only
├── Notifications/  in-app + Slack (Channels/, Slack/)
├── Observers/      Ticket and Comment fan-out
├── Policies/       every authorization decision, delegating to BoardAccess
├── Providers/
├── Services/
│   ├── AI/         provider abstraction, run pipeline, chat, git, code generation
│   ├── GitHub/     webhook signature, processing, link reading, pull requests
│   ├── SMS/        provider abstraction and delivery
│   ├── Slack/      the notifier that decides which board hears what
│   ├── Statistics/ scope, team, customer, flow and AI figures
│   └── *.php       readers: TicketFinder, CommentReader, DocPageFinder, …
└── Support/        value objects: StatsPeriod, BoardAiSettings, BoardSlackSettings, …

tests/
├── Feature/        behaviour, by area
├── Security/       authorization and isolation — the release gate
└── Unit/           value objects and pure logic
```

Three conventions hold everywhere and are worth knowing before reading any of it:

1. **Writes go through an action.** Models are thin and mostly have an empty or narrow `$fillable`,
   so a rule cannot be bypassed by a form posting an unexpected field.
2. **Reads go through a reader with no unscoped entry point.** See
   [Authorization model](#authorization-model).
3. **Anything slow or external is queued.** A third party being down must never make the product
   slow, and never rolls back the write that triggered the call.

---

## Local setup

Requires PHP 8.2+, Composer 2, Node 20+, and a running MySQL 8 server.

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Create the database, then point .env at it
#    (defaults: DB_DATABASE=aqueduct_workspace, DB_USERNAME=root)
mysql -u root -e "CREATE DATABASE aqueduct_workspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Schema and sample data
php artisan migrate
php artisan db:seed

# 5. Run it
composer run dev      # serve + queue worker + logs + vite, all at once
```

Then open <http://127.0.0.1:8000>.

`composer run dev` starts four processes (`php artisan serve`, `queue:listen`, `pail`, `vite`). To
run them separately:

```bash
php artisan serve
npm run dev
php artisan queue:listen --tries=1
```

### Seeded accounts (local only)

`DevelopmentSeeder` refuses to run in production. Passwords come from `SEED_*_PASSWORD`; when unset
in a local environment the fallback is `password`.

| Account | Email | Role | Boards |
| --- | --- | --- | --- |
| Workspace Admin | `admin@example.test` | admin | all (implicit) |
| Team Member | `team@example.test` | team | Aqueduct Platform, Internal Tooling |
| Customer User | `customer@example.test` | customer | Aqueduct Platform only |

The fixture is deliberately asymmetric, so the rules can be checked by hand:

- `Internal Tooling` exists so you can verify the customer receives a **404**, not a 403.
- `Aqueduct Platform` mixes internal and customer-visible tickets, so `/boards/aqueduct-platform`
  renders a genuinely different board depending on who is signed in.
- One ticket on it was raised by the customer: forced customer-visible, forced into the first
  column, never pre-assigned.
- The shared board carries both comment streams, so one ticket URL shows the customer a
  conversation and shows the team that conversation *plus* an internal thread.
- Its documentation has a published parent (`Working with us`) with one published child and one
  internal child, which is the tree case worth seeing with your own eyes.

### Realtime (optional)

Nothing below is needed to run the application. Without it, pages simply do not refresh by
themselves and the notification bell polls instead.

```bash
# .env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
# plus the VITE_REVERB_* lines from .env.example

npm run build          # the key is compiled into the bundle
php artisan reverb:start
php artisan queue:work # broadcasts are queued
```

Open the same board as two users in two browsers and move a card.

---

## Ticket model

A ticket belongs to exactly one board and carries a per-board number, so its human key is the board
prefix plus that number — `AQD-42`. Numbers come from a counter on the board row, taken under a row
lock, and are never reused: deleting `AQD-3` does not hand `AQD-3` to the next ticket.

`customer_visible` is the customer boundary and is stored positively — `false` (the default) means
internal. It is not mass assignable, so it can only be set by `App\Actions\Tickets\*`, which is where
the rules live:

| | Customer | Team / Admin |
| --- | --- | --- |
| Create a ticket | yes, on their boards | yes |
| Visibility of what they create | always customer-visible | internal by default |
| Column it lands in | always the first column | any |
| Assign it | no | to any staff member of the board |
| Move / reorder | no | yes |
| Change visibility, labels, links | no | yes |
| Edit | only tickets they raised | any |
| Delete | no | yes |

Board columns and labels can be changed by any staff **member of the board**
(`BoardPolicy::manageColumns`), which is deliberately wider than the board record itself — name,
prefix, membership and deletion stay administrator-only.

Deleting a column never deletes tickets: the app requires a destination column first, and
`tickets.board_column_id` is `RESTRICT`, so even a bug in that flow fails the query rather than
losing work.

---

## Conversations

Every ticket carries two threads. `comments.stream` is the boundary, stored as a name rather than
a boolean and defaulting to `internal`, so a row written by code that forgot to choose is private
rather than published.

| | Customer conversation | Internal notes |
| --- | --- | --- |
| Admin / team | read and write | read and write |
| Customer | read and write¹ | **never**, including its ids and its count |

¹ subject to the board's `customers_can_comment` setting.

A comment is readable only when **both** its stream and its ticket are — `Comment::visibleTo()`
composes the ticket scope as a subquery rather than restating it. Reads go through
[`CommentReader`](app/Services/CommentReader.php), which has no unscoped entry point, and counts go
through the same scope: "3 notes you cannot open" would itself tell a customer that internal
discussion is happening.

Three separate things stop a note reaching the wrong audience in the UI, and the first is the one
that actually does the work: the two tabs bind to **two different draft properties**, so text typed
for the team cannot be submitted to the customer. The internal panel then looks nothing like the
customer one, and the server authorizes the stream on every post regardless.

Staff open on the internal tab. That is the fail-closed default.

## Documentation

Confluence-style pages, one tree per board, `doc_pages`. Internal by default; publishing is a
separate action with its own ability.

A tree needs one rule a flat table does not: **a page is visible only when it and every ancestor
are**. Publishing a child of an internal page would otherwise leak the parent's title through the
breadcrumb and the sidebar path. That rule lives in
[`DocPageFinder::isVisible()`](app/Services/DocPageFinder.php) and is enforced three times over:

1. on write — publishing under an internal parent is refused, making a page internal cascades down
   the subtree, and a move that would break the invariant is rejected;
2. on reading one page — the ancestor chain is walked through the visibility scope;
3. on reading the tree — any node whose parent is not visible is dropped with its subtree.

Deleting a page removes its whole subtree, deepest-first, because `doc_pages.parent_id` is
`RESTRICT` for the same reason ticket columns are. (Self-referencing `ON DELETE CASCADE` was
rejected: InnoDB does not recurse it, so it would work on two levels and fail on three.)

## Prose rendering

Ticket descriptions, comments and documentation all render through
[`ContentRenderer`](app/Services/ContentRenderer.php): Markdown → mentions → ticket references.

Markdown is GitHub-flavoured, with raw HTML stripped and `javascript:`-style links rejected, so the
output is safe to echo unescaped — which is why that decision lives in one reviewed place rather
than at each `{!! !!}`. Code blocks are highlighted **on the server**
([`CodeHighlightExtension`](app/Support/CodeHighlightExtension.php)): no highlighter in the bundle,
and nothing to re-initialise every time Livewire swaps part of the DOM.

The last two are **per viewer**, which is the point. `AQD-142` becomes a link only for somebody who
may open AQD-142; for everybody else it stays plain, unstyled text — identical to a number nobody
has used, so a page full of ticket keys cannot be used as an oracle. `@handles` resolve only against
board members, and in an internal note only against staff, so a customer cannot be mentioned into a
thread they may not read.

Both post-processors run over the rendered HTML through
[`HtmlText::mapText()`](app/Support/HtmlText.php), which never hands a callback a character that
belongs to a tag, a code block or an existing link.

## Notifications

Laravel database notifications, read through
[`NotificationReader`](app/Services/NotificationReader.php).

Two rules, and the second matters more than it looks:

- **Recipients are filtered by policy**, not by a fresh set of conditions — `Gate::forUser($u)
  ->allows('view', $comment)`, so there is no second definition of the customer boundary to go stale.
- **The stored payload holds identifiers only.** No titles, no bodies, no excerpts. Rendering has to
  re-read every subject through `Ticket::visibleTo()` / `Comment::visibleTo()`, and drops what does
  not come back. A ticket that is made internal after the fact takes its notifications with it,
  silently, rather than leaving a line in somebody's bell quoting a title they may no longer see.

The unread badge is counted from that same filtered list — a badge of 3 above a list of 1 would
itself be a leak.

## Realtime

Two private channels per board, and the split is a security decision:

```
private-board.{id}.internal    staff members of the board
private-board.{id}.customer    everyone on the board
private-users.{id}             that person's notification bell
```

**Events carry a timestamp and nothing else.** Clients react by re-rendering the Livewire
component, which re-runs the same authorized query it used on first paint. There is no ticket data
on the wire to leak, so realtime cannot become a weaker copy of the visibility rules — the cost is
one extra round trip per change.

An internal ticket changing signals only the internal channel. That is not redundant with channel
authorization: it closes a timing side channel, where a customer could infer internal activity from
*when* their browser woke up, without ever reading a payload.

[`routes/channels.php`](routes/channels.php) delegates to `BoardAccess`; it decides which existing
question to ask, never restates the answer. Broadcasts are queued, so a websocket server that is
down cannot fail the write that triggered it.

---

## Statistics

Two screens, and they are **two components with two services**, not one component with a role
switch inside it.

| Route | Component | Who |
| --- | --- | --- |
| `/stats` | [`Stats\Team`](app/Livewire/Stats/Team.php) | staff only — route gate *and* two in-component checks |
| `/stats/customer` | [`Stats\Customer`](app/Livewire/Stats/Customer.php) | everyone, including staff checking the shape of the page |

The separation is the safety property. `Stats\Customer` injects
[`CustomerStatistics`](app/Services/Statistics/CustomerStatistics.php) and nothing else — it has no
access to the flow, team or AI services at all — so there is no branch in that class or its template
that could be made to render an internal figure. A shared component with `@if ($isStaff)` around the
sensitive half is one careless edit away from leaking, and a reporting screen is exactly the kind of
page somebody edits to add "just one more number".

A statistics screen is also the worst place to get visibility wrong: on a board, a leaked internal
ticket is a visible row somebody notices; in a report it is `+1` on a number, and no assertion about
the page's HTML would ever catch it. So every query starts from
[`StatisticsScope`](app/Services/Statistics/StatisticsScope.php), which applies the ordinary
`visibleTo()` scopes in SQL and has no unscoped entry point, and
[`tests/Security/StatisticsVisibilityTest`](tests/Security/StatisticsVisibilityTest.php) asserts the
counts rather than the markup.

### Filters

Board and date range, both in the query string so a report is a link somebody can paste. Neither can
widen anything:

- the board is resolved through `BoardAccess`, so a slug for somebody else's board yields an **empty**
  report — not that board's figures, and not a silent fallback to "all boards", which would answer a
  question nobody asked while the filter still read as that board's name;
- the range is parsed by [`StatsPeriod`](app/Support/StatsPeriod.php), which corrects rather than
  trusts: an unknown preset falls back, reversed dates are swapped, and a span longer than
  `StatsPeriod::MAX_DAYS` is clamped. Without that clamp, a custom range typed into a URL is the one
  way a reporting screen becomes a denial of service against its own database.

`StatsPeriod` also owns the timezone question, which is where most reporting bugs live. Boundaries
are *chosen* in a human timezone — "last 7 days" for a team in Stockholm means seven Stockholm days —
and *stored and compared* as UTC instants, because that is what is in the database. Weeks start on a
local Monday for the same reason.

### Flow metrics are computed from history, never from current state

This is the part worth insisting on. "Twelve tickets are in Done" is not throughput: those twelve
might have arrived over two years, and the eight finished last month and later reopened are
invisible. So [`FlowMetrics`](app/Services/Statistics/FlowMetrics.php) never reads
`tickets.board_column_id`. Everything comes from `ticket_events`:

| Metric | Derived from |
| --- | --- |
| Tickets closed / weekly throughput | first `ticket_moved` into a column with `is_done`, within the range |
| Cycle time (median, mean, p85) | `ticket_created` event → that first arrival in done |
| Cycle time trend | the same, median per week of closure |
| Average time in column | consecutive transitions, folded into intervals |

Two design decisions behind that table:

- **`ticket_events` carries `from_column_id` and `to_column_id` as indexed columns**, promoted out of
  the JSON payload by [a Phase 5 migration](database/migrations/2026_05_01_000100_add_column_ids_to_ticket_events_table.php)
  that backfills existing rows. `payload->>'$.to_column_id'` works but cannot use an index; a board
  with a year of history would scan every move to count a week of throughput. The payload keeps the
  column *name* as it read at the time, so a renamed or deleted column still renders correctly in an
  old timeline — which is also why the two ids are deliberately **not** foreign keys.
- **Only completed stays are measured.** A ticket sitting in Review since March has not finished its
  stay, so it contributes no interval; counting "so far" would make a healthy board look worse the
  longer it stayed healthy.

The interval fold is bounded by `FlowMetrics::MAX_TICKETS`, and a range that exceeds it says so on
screen rather than quietly averaging a subset.

### AI figures

Staff only, and again by refusal rather than by filter: `AiRun::visibleTo()` denies customers
outright, so a customer asking [`AiStatistics`](app/Services/Statistics/AiStatistics.php) for a
total gets zero rows rather than a filtered subset. The honesty rule from the AI phase carries
through: a run whose model or token counts were never reported has **no** cost, is counted as an
*unpriced run*, and the screen says the real total is higher than the figure shown.

---

## AI

> **Every byte of AI output is internal.** Analysis, code, diffs, errors, pull request URLs — and
> the *fact that a run happened at all*. A customer who learns their request was machine-triaged has
> learnt something internal even if they never read a word of it.

That rule is enforced structurally rather than by discipline, in four independent places:

1. **The note.** `ProcessAiResult` and `HandleAiRunFailure` write to `CommentStream::Internal` as a
   **literal** — not a parameter, not a setting, not a value read from the run. There is nothing a
   caller can pass to publish an assessment to a customer, which is what
   `AiVisibilityTest::test_a_result_is_never_written_to_the_customer_stream_even_if_asked` pins.
2. **The rows.** `AiRun` and `AiChatMessage` have no visibility column. Their `visibleTo()` scopes
   **refuse customers outright** rather than filtering, because a filterable flag is a flag somebody
   eventually sets the wrong way.
3. **The timeline.** All four AI event types are `isInternalOnly()`, so `TicketEvent::readableBy()`
   drops them for a customer — including *skipped*, which would otherwise reveal a cost cap.
4. **The screens.** `AiRunPolicy` and `BoardPolicy::useAiChat` deny as **404**, never 403: a 403 on
   "AI runs for AQD-42" would confirm the feature is being used on their ticket.

### Architecture

```
Ticket created ─► TicketObserver ─► TriggerAutomaticAiRun ─► CreateAiRun ─┐
Manual button  ─► Livewire AiRuns ─► AiRunPolicy ─────────► CreateAiRun ─┤
                                                                          │
                                        (insert + dispatch, afterCommit)  ▼
                                                              ExecuteAiRunJob  [queue: ai]
                                                                          │
                                    ┌─────────────────────────────────────┤
                        suggest ────┤ TicketAnalysisService               │
                                    │   └─ AiProviderInterface            │
                          apply ────┤ ApplyModeRunner                     │
                                    │   ├─ RepositoryCheckout (isolated)  │
                                    │   ├─ CodeChangeGeneratorInterface   │
                                    │   ├─ validation → commit → push     │
                                    │   └─ PullRequestClient (draft only) │
                                    └─────────────────────────────────────┘
                                                                          │
                                        ProcessAiResult ◄─────────────────┤
                                        HandleAiRunFailure ◄──────────────┘
                                                  │
                                          INTERNAL NOTE
```

**The provider boundary is one interface and two value objects.**
[`AiProviderInterface`](app/Services/AI/AiProviderInterface.php) has a single method:
`complete(AiPrompt): AiCompletion`. `ClaudeService` is the only class in the codebase that imports
the Anthropic SDK or reads the API key. That is not tidiness — it is what makes it *impossible* for
a test to reach the network or spend money: `tests/Support/FakeAiProvider` is bound in its place, so
there is no HTTP client to intercept and no environment variable anybody has to remember to unset.

### Suggest mode

Reads the ticket, both comment threads, the board's project context and custom prompt, and — when a
checkout was possible — the repository. Produces a structured Markdown assessment (Summary,
Understanding, Proposed approach, Relevant files, Risks, Suggested implementation) and posts it as
an internal note. It has no code path to a commit; the difference between the modes is structural,
not a flag.

The note's footer states **whether the repository was actually read**. Without it, a confident
assessment produced from the ticket text alone reads exactly like one produced from the code.

### Apply mode

Ten steps, every one of them a refusal point:

1. check the coding runtime is available — *before* cloning anything
2. clone into the run's own isolated directory
3. refuse if the base branch is protected
4. create a new branch off it
5. let the coding runtime edit files
6. **re-derive what changed from `git status`**, not from what the runtime claimed
7. run the configured validation commands
8. commit
9. push the branch — never the base
10. open a **draft** pull request

Three invariants are structural rather than instructions:

| Promise | How it is kept |
| --- | --- |
| Never pushes to main | the push refspec is always the generated branch; the base is only ever read, and `assertNotProtected()` refuses a name that collides with a protected one |
| Never merges | `PullRequestClient` has no merge method. The capability does not exist in this codebase, and a test asserts that its public surface is exactly `isConfigured` and `open` |
| Never fakes success | an empty diff, a failed validation command or a missing runtime all raise. No pull request is opened for work that did not happen |

Validation runs **before** the push, so a change that does not build never becomes a branch on the
remote.

**The one step this application does not own** is editing files, which needs an agentic coding
runtime rather than an HTTP call. It sits behind
[`CodeChangeGeneratorInterface`](app/Services/AI/CodeGeneration/CodeChangeGeneratorInterface.php),
and the default implementation **refuses loudly** with a note naming exactly what to configure. See
[Enabling apply mode](#enabling-apply-mode).

### Repository selection

A board may have several repositories (`board_repositories`). The strategy is ordered, first match
wins, and each step records *why* it fired in `ai_runs.repository_strategy`:

| # | Strategy | Rule |
| --- | --- | --- |
| 1 | `only_repository` | the board has exactly one |
| 2 | `board_setting` | `primary_repository` names one — an explicit human choice, and the newer statement |
| 3 | `primary_flag` | the row flagged `is_primary` |
| 4 | `ticket_mention` | the ticket names **exactly one**; two candidates means no answer, not the first one |
| 5 | `none` | suggest mode proceeds without a tree and says so; apply mode refuses |

Step 4 is deliberately last and weakest: text a customer wrote is a hint, never an instruction, so
it can never override a setting a member of staff made.

### Isolation

Each run gets `storage/app/ai-runs/{uuid}`, created empty and deleted in a `finally` block whether
the run succeeded, failed or threw. `AiRunWorkspace` resolves the path and then **checks the result
is inside `storage/app`** — a mistyped `AI_WORKSPACE_PATH` must not point a coding agent at the
application's own source tree.

Railway containers are ephemeral, so nothing here is state. The note, the pull request URL, the
token counts and the failure reason are all rows on `ai_runs` **before** the directory is deleted.

The Claude Code child process gets a deliberately **short environment** — the provider key, `HOME`,
`PATH`, `LANG`. `DB_PASSWORD`, `APP_KEY`, `AWS_SECRET_ACCESS_KEY`, `MAIL_PASSWORD` and `GITHUB_TOKEN`
are withheld: an agent editing files in a scratch directory has no use for them, and a credential
that is never passed cannot be written into a file by mistake.

### Cost and caps

Automatic runs are the runaway-cost risk: one customer filing twenty tickets in an afternoon starts
twenty runs without anybody deciding to. So the two triggers are capped asymmetrically:

| | Cap | Bypass |
| --- | --- | --- |
| Automatic | per board, default **20/day**, configurable to a deployment ceiling | **none, ever** — not for administrators, because nobody chose these runs |
| Manual | deployment-wide, default 100/day | administrators, when `AI_ADMINS_BYPASS_CAP=true` |

"Today" is the **board's own day**, so a board on Europe/Stockholm rolls over at Stockholm midnight.
Failed runs count (they cost tokens); cancelled ones do not (they never started).

Pricing lives only in `config/ai.php`. `CostCalculationService` is the only class that knows a rate,
and when the model or the token counts are unknown it returns **null** — which travels all the way
to the screen as "not reported". It never substitutes zero or a default rate: somebody adds these
figures up, and a plausible invented number is worse than an obvious gap. A summary reports
`unpriced_runs` alongside the total for the same reason.

### Workspace AI chat

Team-only, one shared thread per board, persisted in `ai_chat_messages`.

[`BoardContextBuilder`](app/Services/AI/BoardContextBuilder.php) is the security boundary, and it
holds **no rules of its own**: every read goes through the reader that already governs that content,
with the *asking user* as the viewer — `TicketFinder`, `DocPageFinder`, `TicketEvent::readableBy()`,
`BoardRepository::visibleTo()`. The model therefore receives exactly what that person could have
read by clicking around, and not one row more. Nothing takes a list of boards: a question asked on
board A cannot pull in board B, even for an administrator who can open both.

The system prompt and the context are rebuilt on every request and never stored, so editing a
board's project context changes the next answer rather than being frozen into an old row.

**Actions are proposals, never writes.** The model has four tools, all named `propose_*`, and
nothing executes one. A tool call becomes a stored proposal and a preview; only when a human presses
Confirm does [`ExecuteChatAction`](app/Actions/AI/ExecuteChatAction.php) re-resolve the target
*within this board through the visibility readers*, authorize it against the confirming user with
the ordinary abilities, and call the ordinary action — `CreateTicket`, `UpdateTicket`, `CreatePage`,
`UpdatePage`. Nothing is granted because "the AI suggested it".

The surface is deliberately narrow: the chat cannot change visibility, assign work, move a card,
publish a page or delete anything. A ticket it creates is internal, because `CreateTicket` defaults
staff-created tickets that way and the executor never passes a visibility.

---

## GitHub integration

Mention a ticket key — `AQD-142` — in a branch name, a commit message, or a pull request title or
body, and it is linked to that ticket. The panel on the ticket shows the branch, the recent commits
and the pull request with its state and CI result.

### The security boundary is the repository, not the key

A webhook is an anonymous request whose only credential is an HMAC proving *GitHub* sent it. It does
not prove who owns the repository it is about: anyone can add a webhook to a repository they control,
and if they held the secret they could deliver a valid, signed payload claiming a commit in
`attacker/anything` fixes `AQD-1`.

So a delivery may only reach tickets on boards where **that repository is already attached** (on the
board's AI settings screen). A repository nobody has configured resolves to nothing and the delivery
is recorded as ignored. See
[`TicketReferenceResolver`](app/Services/GitHub/TicketReferenceResolver.php), and
`test_a_signed_delivery_cannot_reach_a_ticket_on_an_unrelated_board`.

### Verification

[`WebhookSignature`](app/Services/GitHub/WebhookSignature.php) has four properties, each of which
has been a real vulnerability somewhere:

- **fails closed with no secret** — an unconfigured endpoint rejects everything, because "skip
  verification until the secret is set, so it works out of the box" turns a half-deployed system
  into an unauthenticated write endpoint;
- **verifies the raw body**, not a re-encoded array — `json_encode(json_decode($body))` is not the
  bytes GitHub signed;
- **compares in constant time** with `hash_equals`, so no timing side channel leaks the digest;
- **SHA-256 only** — accepting the legacy `X-Hub-Signature` would mean the endpoint's real strength
  is SHA-1's, since the caller picks which header to send.

The route is CSRF-exempt (a webhook cannot carry a token from a form it never rendered) and rate
limited before anything else runs, so an unsigned flood is refused without touching the database.

### Processing

`POST /webhooks/github` verifies, records the delivery, queues, and answers `202` — no parsing, no
ticket lookup, no writing. GitHub disables an endpoint that repeatedly takes more than ten seconds,
and a push to a monorepo can carry hundreds of commits.

`webhook_deliveries` gives idempotency (a unique index on the delivery id, so a replay updates
nothing) and evidence (whether GitHub delivered at all, and what the processor made of it). It
deliberately **does not store the payload**: that would make it a copy of private repository history
with none of the access control GitHub applies to it.

Everything not a signature failure answers 2xx, including ignored events and unparseable bodies —
GitHub retries a non-2xx, and retrying something that will never succeed helps nobody.

### Visibility

GitHub links are **internal**, enforced the same way AI runs are: the scope refuses customers
outright. A branch name is often a paraphrase of the fix
(`AQD-42-disable-vat-for-eu-resellers`), a commit message says what was wrong in the words an
engineer used while annoyed about it, and a red CI badge on a customer's ticket invites a question
the team has not decided how to answer yet. What a customer is owed is "this is fixed and released",
written by a person in the customer conversation.

CI status is reported only when a repository actually reports it. `null` renders as *No CI reported*,
never as a pass — a green tick for a repository that runs no checks would be a lie told in the most
convincing possible form.

---

## Slack notifications

Configured **per board**, on `/boards/{board}/integrations`, because each board belongs to a
different customer with a different delivery team and a different channel.

| Event | Default |
| --- | --- |
| A customer raises a ticket | on |
| A customer comments | on |
| A ticket is moved to done | on |
| An AI run finishes | **off** — a board with automation on produces one per customer ticket |

### What travels, and what does not

A Slack channel is outside this application's permission model: its membership is managed in Slack,
by different people, with no relationship to board membership. So a message carries an identifier, a
title, who did it, and a **link** — and the link is the access control, because following it requires
signing in and the ordinary rules then apply.

Never included: ticket descriptions, comment bodies, AI analysis, AI failure reasons, or pull
request URLs. **Internal notes are never announced, in any configuration** — the stream check is at
the top of [`CommentObserver`](app/Observers/CommentObserver.php) rather than buried in the notifier.

Ticket titles are escaped for Slack's `mrkdwn`, which treats `<`, `>` and `&` as link syntax. Without
that, a ticket titled `<https://evil.example|Click here>` renders as a link in a room full of people
who trust the bot.

### The webhook URL is a credential

A Slack incoming-webhook URL is a bearer token wearing a URL's clothes: anyone holding it can post
into that channel as the app, for ever, and Slack has no read-only variant. It is therefore stored
**encrypted** with the application key, never rendered back to the browser (the field is blank on
every load; blank means "unchanged", and removing it is a separate button), and an undecryptable
value — what an `APP_KEY` rotation leaves behind — degrades to "not configured" rather than throwing
on every ticket save.

Delivery is queued with widening backoff. A 4xx other than 429 is logged and dropped rather than
retried, because a revoked webhook URL will still be revoked in three minutes.

---

## Critical ticket SMS

One trigger, and deliberately only one: **a ticket raised at Critical priority**. An SMS reaches
somebody who is not at a computer and possibly asleep, and the fastest way to make an alerting
channel useless is to send anything else down it.

[`SmsProviderInterface`](app/Services/SMS/SmsProviderInterface.php) has the same shape as the AI
provider abstraction and for the same three reasons: nothing above it names 46elks, tests bind a fake
and therefore cannot reach the network, and the credentials are read in exactly one class.

| Driver | Behaviour |
| --- | --- |
| `unavailable` | **the default.** Refuses, and records a failure naming exactly what to set. Nothing is faked |
| `log` | writes to the log and records status **`logged`, never `sent`** |
| `46elks` | the real provider |

The `logged`/`sent` distinction is enforced all the way to the database column. A development
convenience must not be able to launder itself into evidence that an on-call engineer was paged.

### Duplicate suppression

Two guards, because they fail differently:

- a **unique index** on `(event, ticket_id, recipient)` — one alert per ticket per number. The insert
  *is* the claim, so two workers racing is settled by the database rather than by a
  select-then-insert;
- a **board cooldown** (`SMS_BOARD_COOLDOWN`, 5 minutes) — a script filing forty critical tickets
  rings the phone once, not forty times.

Recipients are E.164 only. `0701234567` is refused rather than guessed at, because it is a different
person in every country. Numbers are stored in full — the table exists to answer "was this number
contacted" — masked in every log line and in the delivery history, and pruned after
`SMS_RETENTION_DAYS`.

Nothing here can fail a ticket save:
[`AlertCriticalTicket`](app/Actions/Notifications/AlertCriticalTicket.php) never throws, and the
provider call happens in a worker.

---

## Queues

Everything slow or external is queued. That is not a performance nicety — it is what stops a third
party's outage becoming this product's outage.

| Queue | Carries | Worker flag |
| --- | --- | --- |
| `ai` | AI runs (minutes each) | must be named explicitly |
| `default` | broadcasts, in-app notifications, GitHub webhook processing, Slack messages, SMS | the default |

```bash
php artisan queue:work --queue=ai,default --tries=3 --max-time=3600
```

**A worker is required.** Without one, realtime updates never arrive, no Slack message is posted, no
SMS is sent, no pull request is ever linked to a ticket, and no AI run executes — and nothing errors,
because all of it is queued by design. The `ai` queue must be named: a bare `queue:work` listens only
to `default`.

The scheduler ([`routes/console.php`](routes/console.php)) is housekeeping only —
`workspace:prune` removes webhook deliveries and SMS records past their retention, and prunes failed
jobs. The application works without it; it simply accumulates rows. Failed jobs are deliberately
**not** replayed automatically: an AI run or an SMS that exhausted its attempts has already recorded
why, and replaying a batch would re-post notes and re-send messages.

---

## Database and performance

Every board-scoped table carries `board_id` — denormalised where it could have been joined — because
the visibility rule is applied per board on every read, and a join on the hot path is a join on every
path.

Indexes added for Phase 5, each for a specific query rather than on principle:

| Index | Query |
| --- | --- |
| `ticket_events (board_id, to_column_id, created_at)` | throughput, cycle time, the weekly chart |
| `tickets (board_id, created_at)` | tickets raised in a range — the whole customer summary |
| `tickets (board_id, updated_at)` | recent-activity panels, and cross-board search ordering |
| `github_links (ticket_id, type)` and `(repository, type, external_id)` | the ticket panel; updating a pull request's state |
| `sms_messages (event, ticket_id, recipient)` unique | duplicate suppression |
| `webhook_deliveries (source, delivery_id)` unique | replay suppression |

Deliberately **not** added, having been considered: `tickets (priority)` — five distinct values over
a partition already being read; `tickets (assignee_id)` — covered by the existing
`(board_id, assignee_id)`; `tickets (customer_visible)` — selects roughly half the table, which no
planner would use.

An index is written on every insert and update, and `tickets` is the most frequently written table
here, so the bar for adding one is a named query that scans without it.

On query counts: the team statistics page runs about 30 queries and the customer page about 15 — a
*fixed* number of distinct aggregates, not an N+1. Repeated sub-queries are memoised per report
(`StatisticsScope::doneColumnIds`, `FlowMetrics::closures`), and everything that renders a list eager
loads what the template touches. `Model::shouldBeStrict()` is on outside production, so a forgotten
eager load fails loudly in development instead of becoming a slow page in production.

---

## Tests

```bash
php artisan test                          # everything (670 tests)
php artisan test --testsuite=Security     # authorization and isolation only — the release gate
php artisan test --testsuite=Feature      # behaviour
php artisan test --testsuite=Unit         # value objects and pure logic
php artisan test tests/Feature/Stats      # one directory
php artisan test --filter=cycle_time      # one topic
composer run test                         # config:clear, then the full suite
composer run test:security                # the Security suite alone
composer run lint                         # Pint, applying fixes
composer run lint:test                    # Pint, check only (use this in CI)
```

`tests/Security` pins the product's access rules. Treat a failure there as a release blocker.

| Area | Where |
| --- | --- |
| Authentication, registration, password reset | `tests/Feature/Auth` |
| Roles and direct-URL authorization | `tests/Security/RoleAccessTest` |
| Board membership and visibility | `tests/Security/BoardMembershipTest`, `BoardVisibilityTest` |
| Ticket CRUD, movement, filtering, links, subtasks | `tests/Feature/Tickets` |
| Customer boundary — tickets, comments, docs, attachments, search | `tests/Security/*VisibilityTest`, `CustomerBoundaryTest` |
| Comment stream isolation | `tests/Feature/Comments`, `tests/Security/CommentVisibilityTest` |
| Notification leakage | `tests/Security/NotificationVisibilityTest` |
| Realtime channel authorization and broadcast audience | `tests/Security/RealtimeAuthorizationTest`, `RealtimeBroadcastAudienceTest` |
| AI: internal-only output, caps, authorization, failures | `tests/Feature/AI`, `tests/Security/AiVisibilityTest`, `AiChatSecurityTest` |
| Statistics: figures, flow from history, customer boundary | `tests/Feature/Stats`, `tests/Security/StatisticsVisibilityTest` |
| GitHub: linking, idempotency, signature, cross-board isolation | `tests/Feature/GitHub`, `tests/Security/WebhookSecurityTest` |
| Slack and SMS | `tests/Feature/Notifications` |
| Credential exposure across every screen | `tests/Security/SecretExposureTest` |

**No test reaches a third party, and that is enforced rather than agreed.** The suite loads your
`.env`, so a developer with a working `ANTHROPIC_API_KEY` would otherwise have any un-faked AI path
make a real, billed, forty-second call — and pass. So `Tests\TestCase::setUp()` blanks the credential
and binds [`UnreachableAiProvider`](tests/Support/UnreachableAiProvider.php), which throws with an
explanation. `fakeAiProvider()` replaces it for tests that mean to exercise an AI path; anything else
that reaches one fails loudly and says what to do. The SMS provider needs no equivalent — its default
driver already refuses.

The tests that touch an outbound path
(`ApplyModeTest`, `WebhookProcessingTest`, `WebhookSecurityTest`, `CriticalTicketSmsTest`,
`SecretExposureTest`) additionally call `Http::preventStrayRequests()`, so a code path that *tried*
to reach GitHub, Anthropic or 46elks fails the test rather than quietly making a network call.

---

## Authorization model

Two independent axes decide what a user sees.

1. **Board membership** — which boards exist for you.
   Administrators see every board. Everyone else sees a board only when a `board_members` row links
   them to it. There is one SQL definition of this rule, `Board::scopeAccessibleBy()`, reached
   through [`App\Services\BoardAccess`](app/Services/BoardAccess.php).

2. **Internal content visibility** — what you see inside a board you can reach.
   Customers never observe content flagged as internal, including on boards they belong to.

Non-membership is reported as **404, never 403**: a 403 would confirm that a board with that slug
exists.

Later phases must not re-implement either rule. Board-scoped models should use the
[`BelongsToBoard`](app/Models/Concerns/BelongsToBoard.php) trait and query through
`visibleTo($user)`, or call `BoardAccess::constrain()` / `restrictToCustomerVisible()` directly.

Each kind of content has exactly one reader, and none of them has a method that returns an unscoped
query — so board membership and the customer rule cannot be skipped by forgetting a scope:

| Content | Reader | Also enforces |
| --- | --- | --- |
| Tickets | [`TicketFinder`](app/Services/TicketFinder.php) | `customer_visible` |
| Comments | [`CommentReader`](app/Services/CommentReader.php) | the stream, and the ticket |
| Documentation | [`DocPageFinder`](app/Services/DocPageFinder.php) | `customer_visible`, and every ancestor |
| Notifications | [`NotificationReader`](app/Services/NotificationReader.php) | re-reads the subject and drops what is gone |
| Attachments | `AttachmentPolicy` | whatever the owner's policy says |
| AI runs | [`AiRunReader`](app/Services/AI/AiRunReader.php) | staff only — the scope refuses customers outright |
| AI chat | [`WorkspaceChatService`](app/Services/AI/WorkspaceChatService.php) | staff only, same rule |

Linked tickets are the one place a record points across that boundary, and they are read through
[`TicketLinkReader`](app/Services/TicketLinkReader.php), which re-queries the far end and omits what
the viewer may not see entirely — no placeholder and no count, because even "2 tickets you cannot
see" confirms that they exist.

Attachments deserve their own line: they carry **no visibility flag at all**. `AttachmentPolicy`
asks the owning ticket, comment or page instead, so a file cannot become more readable than its
parent by the two drifting apart.

## Drag and drop

The Kanban board uses `wire:sort`, the Alpine Sort plugin that ships inside Livewire 4 — no extra
frontend dependency. Each column is a sort group sharing one group name, so cards move between
columns; SortableJS moves the DOM immediately (the optimistic part) and Livewire then persists it.

The handler is bound per column, so the server is always told where the card landed:

```blade
wire:sort="$wire.moveTicket($item, $position, {{ $column->id }})"
```

All three arguments come from the browser and none is trusted: the ticket is re-fetched through the
visibility scope, `TicketPolicy::move` decides whether this user may move it, and the column is
looked up within this board so a foreign id resolves to nothing.

---

## Dialogs

There are no `window.confirm()` or `alert()` calls in the product, and
[a test scans for them](tests/Feature/UI/DialogTest.php) so there cannot be. A
native dialog is unstyleable, blocks the whole tab, prefixes the message with
"127.0.0.1:8000 says", and on mobile is a system sheet that looks nothing like
the page underneath.

One modal exists in the document — [`x-ui.dialog`](resources/views/components/ui/dialog.blade.php),
rendered once per layout — driven by an Alpine store in
[`resources/js/dialog.js`](resources/js/dialog.js). Screens add a confirmation
by describing it, never by adding markup.

### Asking before an action

On a button, as a bound prop:

```blade
<x-ui.button
    wire:click="removeMember({{ $member->id }})"
    :confirm="[
        'title' => 'Remove '.$member->name.' from this board?',
        'body' => 'They lose access immediately. You can add them back later.',
        'confirmText' => 'Remove member',
    ]"
>Remove</x-ui.button>
```

On a plain HTML element, as the directive the prop forwards to:

```blade
<button wire:click="remove({{ $comment->id }})" x-confirm="@js([...])">Delete</button>
```

Two spellings for one mechanism, and the reason is a Blade detail worth knowing:
**Blade does not compile directives inside component attributes.** The tag
compiler treats an unbound attribute as a literal string, so `x-confirm="@js(…)"`
on an `<x-ui.button>` reaches the browser verbatim and the button fires without
asking. A bound prop is evaluated as PHP, which is the whole difference. Both
produce the same `x-confirm` attribute and are handled by one directive.

The directive intercepts the click in the **capture** phase and calls
`stopImmediatePropagation()`, so Livewire's own handler never sees it; on
confirmation the element is re-clicked with a one-shot flag and passes straight
through. That keeps `wire:click` as the single description of what the button
does — the alternative, `x-on:click="$dialog.confirm(…).then(() => $wire.remove(1))"`,
states the action twice in two languages, and a change to one silently misses
the other.

### The other four types

```js
await window.dialog.confirm({ title: '…', body: '…', confirmText: 'Delete' })  // => boolean
window.dialog.success('Saved', 'Your changes are live.')
window.dialog.error('That did not work', 'Try again in a moment.')
window.dialog.warning('Careful', '…')
window.dialog.info('For reference', '…')
```

Also reachable as the `$dialog` magic inside any Alpine expression, and from a
Livewire component with no JavaScript at the call site:

```php
$this->dispatch('dialog', type: 'error', title: 'Could not save', body: '…');
```

Existing page-load messages still use the inline [`x-ui.flash`](resources/views/components/ui/flash.blade.php)
banner rather than a modal. A success that interrupts you and demands a click is
worse than one that appears in place and fades, so those were left alone.

### Tone

Confirmations carry one of three tones, because the product has three kinds of
consequential action rather than two:

| Tone | For | Looks like |
| --- | --- | --- |
| `danger` (default) | destructive and irreversible — a comment, a file, a label | red icon, red confirm button |
| `warning` | reversible, but changes **who can see something** — publishing a ticket or a page to a customer | amber icon, ordinary confirm button |
| `brand` | a plain "are you sure" with no particular weight | blue question mark |

The middle one earns its place. Nothing is destroyed by publishing a ticket, but
it cannot be un-seen — and using the red button for it would cry wolf and make
the genuinely destructive dialogs read as routine.

### Design and behaviour

Nothing here is a new design system. It reuses the card shell
(`rounded-xl` / `border-slate-200` / `bg-white`), the tinted-circle icon
treatment from `x-ui.empty-state`, the existing badge colour families, and
`x-ui.button` itself for the actions — so a dialog's button is literally the
same component as the button that opened it, not a copy of its classes. The one
deliberate departure is elevation: cards sit at `shadow-xs`, a modal floats, so
it uses the `shadow-xl` the board already uses for a card being dragged.

Focus trapping, background scroll locking (with scrollbar compensation, so the
page does not jump sideways) and inerting the rest of the document come from
`x-trap.noscroll.inert` — the Alpine Focus plugin, which Livewire already
bundles. Escape and a backdrop click both resolve as *declined*, so the easy
exit is always the safe one; `'dismissible' => false` removes both for a message
that must be acknowledged. Focus returns to the element that opened the dialog,
handled in the store rather than the markup because Livewire may re-render the
panel underneath a closing dialog.

**On dark mode:** the application does not have one — there are no `dark:`
variants anywhere in `resources/views`, and the dark sidebar is a fixed design
choice rather than a theme. The dialog matches the light surface it sits on. If
a theme is added later, this component follows the same tokens as every card and
button and needs no special handling.

---

## Deployment (Railway)

The image is built from the `Dockerfile` (nginx + php-fpm + supervisor). `railway.json` selects the
Dockerfile builder and points the health check at `/health`.

### Required services

Every service below runs the **same image** with a different start command.

| # | Service | Start command | Required? |
| --- | --- | --- | --- |
| 1 | Web | `supervisord -c /etc/supervisord.conf` (the image default) | yes |
| 2 | MySQL 8 | Railway plugin | yes |
| 3 | Queue worker | `php artisan queue:work --queue=ai,default --tries=3 --max-time=3600` | **yes in practice** — see below |
| 4 | Scheduler | `php artisan schedule:work` | recommended |
| 5 | Redis | Railway plugin | only for multi-replica cache/sessions, or a busy queue |
| 6 | Reverb | `php artisan reverb:start --host=0.0.0.0 --port=$PORT` | only for realtime |
| 7 | S3-compatible storage | any provider (R2, B2, MinIO, AWS) | **yes** for attachments |

Service 3 is listed as required because without it realtime updates never arrive, no Slack message
is posted, no SMS is sent, no pull request is linked and no AI run executes — and nothing errors,
because all of it is queued by design. Service 4 only prunes data; the application works without it.

Service 7 is not optional: the container filesystem is replaced on every deploy, so uploads written
to local disk vanish at the next release. The application **refuses to boot in production** with a
non-durable attachment disk rather than losing files silently.

### Steps

1. Create a Railway project, add a **MySQL** service, and deploy this repository.
2. Set the web service's variables from `.env.example`. Minimum for a working deployment:

   ```
   APP_KEY=base64:…            # php artisan key:generate --show
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-app.up.railway.app
   LOG_CHANNEL=stderr          # so Railway captures the log
   SESSION_SECURE_COOKIE=true

   DB_CONNECTION=mysql
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_DATABASE=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

   QUEUE_CONNECTION=database   # or redis
   CACHE_STORE=database        # or redis
   SESSION_DRIVER=database     # or redis

   FILESYSTEM_DISK=s3
   AWS_ACCESS_KEY_ID=…  AWS_SECRET_ACCESS_KEY=…  AWS_BUCKET=…  AWS_DEFAULT_REGION=…
   AWS_ENDPOINT=…              # for R2 / B2 / MinIO
   LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3

   MAIL_MAILER=smtp  MAIL_HOST=…  MAIL_PORT=…  MAIL_USERNAME=…  MAIL_PASSWORD=…
   MAIL_FROM_ADDRESS=…
   ```

3. Set `RUN_MIGRATIONS=true` on **exactly one** service, so migrations run once under
   `migrate --force --isolated`. Setting it on several would have them race.
4. Add the queue worker service. Copy every variable from the web service — it needs the same
   database, the same `APP_KEY` (to decrypt board Slack settings) and the same
   `ANTHROPIC_API_KEY` — and set `RUN_MIGRATIONS=false`.
5. Add the scheduler service the same way.
6. For realtime, add the Reverb service, expose it publicly, and set on the **web** service:
   `BROADCAST_CONNECTION=reverb`, the three `REVERB_APP_*` values (shared with the Reverb service),
   `REVERB_HOST` = the Reverb service's public hostname, `REVERB_PORT=443`, `REVERB_SCHEME=https`,
   and the matching `VITE_REVERB_*` values.

   The `VITE_` ones are compiled into the browser bundle at **build** time, so they must be set
   before the image is built. Changing them later requires a rebuild, not a restart.
7. Seed an administrator once, on a service with database access:
   `php artisan db:seed --class=AdminUserSeeder --force` with `SEED_ADMIN_EMAIL` and
   `SEED_ADMIN_PASSWORD` set. Outside local development the seeder refuses to fall back to a default
   password.

### Health check

`GET /health` returns `200` when the container can serve a request end to end — the database
answering, and the cache store (which also backs sessions) writable and readable. It is polled every
thirty seconds for the life of the deployment, so it deliberately does **not** call any third party:
a probe that reached Anthropic or GitHub would turn their outage into a restart loop.

It reports `schema: pending` when migrations are outstanding, but does not fail on it — migrations
run from one service, and failing every other service during a deploy would have the platform
restart them while waiting for the migration to finish.

`GET /up` is the framework's own probe, which checks only that PHP booted.

### Features requiring credentials

Everything below is architecture-complete and off until configured. Each refuses visibly rather than
pretending to work.

| Feature | Needs | Without it |
| --- | --- | --- |
| **AI suggest mode, workspace chat** | `ANTHROPIC_API_KEY` on web **and** worker | runs are refused with a recorded reason; every other feature is unaffected |
| **AI repository reading** | `AI_REPOSITORY_CLONE_ENABLED=true`, `GITHUB_TOKEN` (`contents: read`), and **`git` in the worker image** — not installed by the `Dockerfile` | suggest mode still runs and states in its own note that it did not read the code |
| **AI apply mode** | the above plus `AI_CODE_DRIVER=claude_code` and the Claude Code CLI in the worker image, and `GITHUB_TOKEN` with `contents: write` + `pull_requests: write` | an apply run fails in ~1s with an internal note naming exactly what to set. No empty pull request is opened |
| **GitHub webhooks** | `GITHUB_WEBHOOK_SECRET`, and the webhook pointed at `POST /webhooks/github` | every delivery is rejected with `401`. Nothing else is affected |
| **Slack** | a per-board incoming webhook URL on `/boards/{board}/integrations`; `SLACK_NOTIFICATIONS_ENABLED=true` | nothing is posted; the board's screen says it is not configured |
| **SMS** | `SMS_ENABLED=true`, `SMS_DRIVER=46elks`, `SMS_46ELKS_USERNAME`, `SMS_46ELKS_PASSWORD`, `SMS_FROM`, and numbers on the board | alerts are recorded as **failed** with the reason. Nothing is claimed to have been sent |
| **Realtime** | a Reverb service and the `REVERB_*` / `VITE_REVERB_*` values | boards and the bell do not refresh by themselves; everything still works |
| **Attachments** | S3-compatible storage | the app refuses to boot in production |

Two things are **not verified against a live third party**, and are marked as such in their own
source files:

- [`ClaudeCodeGenerator::generate()`](app/Services/AI/CodeGeneration/ClaudeCodeGenerator.php) — one
  `Process` call whose arguments, working directory and scrubbed environment are written but never
  run against the real CLI, which needs the binary in the image and a real repository.
- [`ElksSmsProvider`](app/Services/SMS/ElksSmsProvider.php) — written from 46elks' published
  documentation. The request shape, auth scheme and response fields have not been exercised against
  a paid account, because doing so sends real messages to real phones.

Everything around both — isolation, branch safety, the empty-diff refusal, validation ordering,
duplicate suppression, failure recording, the internal note — is covered by tests.

### Enabling GitHub webhooks

1. Generate a secret: `openssl rand -hex 32`. Set it as `GITHUB_WEBHOOK_SECRET` on the **web**
   service (which verifies) and the **worker** (which processes).
2. In the repository: *Settings → Webhooks → Add webhook*.
   - Payload URL: `https://your-app.up.railway.app/webhooks/github`
   - Content type: `application/json`
   - Secret: the value above
   - Events: *Pushes*, *Branch or tag creation*, *Pull requests*, *Check suites*, *Statuses*
3. Attach the repository to a board on `/boards/{board}/ai/settings`, using the exact
   `owner/name` GitHub sends. **A delivery for a repository no board has attached links nothing** —
   that is the security boundary, not an oversight.
4. GitHub's *Recent Deliveries* tab shows the response. A `401` means the secret does not match; a
   `202` with nothing appearing on a ticket almost always means step 3.

### Enabling Slack

1. In Slack: *Apps → Incoming Webhooks → Add to Slack*, pick the channel, copy the URL.
2. On `/boards/{board}/integrations`, paste it, choose the events, and save.
3. `SLACK_NOTIFICATIONS_ENABLED=true` (the default) on the **worker**, which is what actually posts.

Turn the deployment switch **off** on any staging environment restored from a production database
dump, or it will start posting into the real team's channel within minutes of booting. The URLs are
encrypted with `APP_KEY`, so a dump restored with a different key cannot post at all — but do not
rely on that as the only guard.

### Enabling SMS

1. Create a 46elks account and copy the API username and password.
2. On the **worker**: `SMS_ENABLED=true`, `SMS_DRIVER=46elks`, `SMS_46ELKS_USERNAME`,
   `SMS_46ELKS_PASSWORD`, `SMS_FROM` (an alphanumeric sender ID of up to 11 characters, or an E.164
   number). Set the same on the web service so the settings screen reports the driver correctly.
3. Add on-call numbers on `/boards/{board}/integrations`, in full international form, and switch
   alerts on.
4. Verify with a real critical ticket. The delivery history on that screen shows what happened; a
   status of `logged` means `SMS_DRIVER` is still `log`.

Several mobile networks reject alphanumeric sender IDs. Check with 46elks for the countries the
on-call numbers are in.

Local disk is ephemeral: uploads must go to S3-compatible storage, and sessions/cache must use the
database or Redis.

### Enabling AI

Set `ANTHROPIC_API_KEY` on **both** the web service (which queues runs and answers chat) and the
queue worker (which executes them). Nothing else is required: suggest mode works from the ticket
alone, states in its own note that it did not read the repository, and every board defaults to
`auto_run_mode = off` until somebody turns it on.

The key is read only by `App\Services\AI\ClaudeService`, which hands it straight to the SDK. It
never reaches Blade, Livewire, JavaScript or a log line; the settings screen reports only whether it
is *present*, and the exception translation rebuilds its own prose from the HTTP status rather than
forwarding a message that might quote a request.

### Enabling repository reading (suggest mode)

Suggest mode reasons about the code rather than only the ticket once a checkout is possible. On the
**worker** service:

| Variable / requirement | Why |
| --- | --- |
| `AI_REPOSITORY_CLONE_ENABLED=true` | opt-in; off by default |
| `GITHUB_TOKEN` | `contents: read` is enough for suggest mode |
| `git` in the worker image | not installed by the `Dockerfile` — the web service does not need it. Add `git` to the `apk add` list, or use a worker image that has it |

The token is applied per git command and the stored remote is rewritten to the clean URL, so no
credential is left in `.git/config` inside a tree the model can read. Every byte of git output is
redacted before it can reach a note or a log.

### Enabling apply mode

Apply mode is **built end to end** — isolated checkout, branch, validation, commit, push, draft pull
request, internal note — and is wired up except for one step: the runtime that actually edits files,
which is an agentic coding process rather than an HTTP call. Until it is configured, an apply run
fails in about a second with an internal note naming exactly what to set. Nothing is faked.

On the **worker** service, in addition to the repository-reading requirements above:

| Variable / requirement | Why |
| --- | --- |
| `AI_CODE_DRIVER=claude_code` | selects `ClaudeCodeGenerator` in place of the refusing default |
| the Claude Code CLI in the image | or `AI_CLAUDE_CODE_BINARY` pointing at its full path |
| `ANTHROPIC_API_KEY` | the CLI authenticates with its own copy; it is the only secret passed to the child process |
| `GITHUB_TOKEN` with `contents: write` **and** `pull_requests: write` | push a branch, open a pull request. Merge rights are neither needed nor used |
| `AI_JOB_TIMEOUT` ≥ clone + generation + validation | a run killed mid-flight is retried, which is wasteful |
| `AI_VALIDATION_COMMANDS` (recommended) | e.g. `composer install --no-interaction\|php artisan test`. A non-zero exit fails the run *before* the push, so an unbuildable branch never becomes a pull request |

The **exact integration point still needed** is
[`ClaudeCodeGenerator::generate()`](app/Services/AI/CodeGeneration/ClaudeCodeGenerator.php) — one
`Process` call whose argument list, working directory and environment are already written. It is
untested against a live binary, because doing so needs the CLI in the image and a real repository;
everything around it (isolation, branch safety, the empty-diff refusal, validation ordering, the
pull request body, the internal note) is covered by `tests/Feature/AI/ApplyModeTest`.

Two limitations worth knowing before switching it on:

- the CLI does not report token usage on stdout, so an apply run stores **null** tokens and null
  cost rather than an estimate;
- a repository large enough that a shallow clone plus a test suite exceeds `AI_JOB_TIMEOUT` will
  have its runs killed and retried. Raise the timeout, or narrow the validation commands.

---

## Troubleshooting

**Nothing happens when I create a ticket — no Slack, no SMS, no AI run, no realtime.**
There is no queue worker running, or it was started without `--queue=ai,default`. All of that work
is queued by design, so it fails silently rather than erroring. Check `php artisan queue:monitor`
and the `jobs` table.

**A pull request does not appear on its ticket.**
In order: does the branch, commit or PR title actually contain the key (`AQD-142`)? Is that exact
`owner/name` attached to the board on `/boards/{board}/ai/settings`? Does GitHub's *Recent
Deliveries* tab show a `202`? A `401` there means `GITHUB_WEBHOOK_SECRET` does not match. The
`webhook_deliveries` table records every verified delivery and what the processor made of it.

**The webhook returns 401 for everything.**
`GITHUB_WEBHOOK_SECRET` is unset on the web service. With no secret the endpoint rejects every
delivery — that is deliberate, not a bug.

**Cycle time looks wrong, or a column shows no time at all.**
Time in column measures **completed** stays only, so a ticket that has never left its current column
contributes nothing. Cycle time measures tickets that *reached a done column within the range*, so a
board with no column marked `is_done` reports nothing at all — check that on
`/boards/{board}/settings`.

**Statistics show fewer tickets than the board does.**
For a customer that is correct: internal tickets are excluded everywhere. For staff, check the board
filter and the date range in the URL — a board slug you cannot reach produces an empty report rather
than a silent fallback to all boards.

**A Slack message was never posted.**
Check, in order: `SLACK_NOTIFICATIONS_ENABLED` on the worker; the board's own switch; that the
specific event is ticked (AI-run messages default to **off**); and that a webhook URL is stored. A
URL saved before an `APP_KEY` rotation cannot be decrypted and shows as "not configured" — paste it
again. A revoked URL is logged once and not retried.

**An SMS shows as `logged` rather than `sent`.**
`SMS_DRIVER` is still `log`. That status exists precisely so a development message cannot be mistaken
for a delivered one.

**An SMS shows as `failed` with "No SMS provider is configured".**
`SMS_DRIVER` is `unavailable` (the default) or the 46elks credentials are missing on the **worker**,
which is where the send happens.

**The same critical ticket did not alert twice.**
By design: one alert per ticket per number (a unique index), plus a five-minute per-board cooldown.
Set `SMS_BOARD_COOLDOWN=0` to disable the second guard.

**An AI run fails immediately with "Apply mode is not configured".**
Expected until `AI_CODE_DRIVER=claude_code` and the Claude Code CLI are set up on the worker. The
note names every variable required.

**Suggest mode says it did not read the repository.**
`AI_REPOSITORY_CLONE_ENABLED` is false, `GITHUB_TOKEN` is unset, or `git` is missing from the worker
image. The note is accurate rather than apologetic: an assessment written from the ticket text alone
otherwise reads exactly like one written from the code.

**The app refuses to boot in production: "Attachment storage … does not survive a deploy".**
`FILESYSTEM_DISK` (or `ATTACHMENT_DISK`) is `local`. The container filesystem is replaced on every
deploy, so this fails at boot rather than losing a customer's file weeks later.

**Realtime does not update.**
`BROADCAST_CONNECTION` is `null`, the Reverb service is not running, or the `VITE_REVERB_*` values
were changed without rebuilding the image — they are compiled into the browser bundle at build time.
A worker is also required: broadcasts are queued.

**`/health` returns 503.**
The database or the cache store is unreachable. The response body says which. `schema: pending` is
reported but never causes a 503 — see [Health check](#health-check).

**Tests fail with a timezone-related date mismatch.**
Construct moments with an explicit timezone. `Carbon::parse('2026-06-15 00:30')` means whatever the
machine's local time is, which on a European laptop is a different instant — and a different week —
from the same string on a CI runner. See `tests/Unit/StatsPeriodTest`.
