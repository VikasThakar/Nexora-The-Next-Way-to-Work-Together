# Statistics and Reporting

## Two screens, not one screen with a role switch

| Screen | Route | Gate | Service |
|---|---|---|---|
| **Team statistics** | `/stats` | `role:admin,team` | `TeamStatistics`, `FlowMetrics`, `AiStatistics` |
| **Customer summary** | `/stats/customer` | auth — **everyone** | `CustomerStatistics` only |

> [!IMPORTANT]
> The separation **is** the safety property. A shared class with a `$forCustomer` flag would
> mean every future metric somebody adds is exposed by default and has to be remembered *not*
> to be — the wrong way round. Here the customer screen can only render what
> `CustomerStatistics` offers, and that class offers six things. **There is no branch on
> either page that a careless edit could invert.**
>
> What `CustomerStatistics` deliberately *cannot* answer, because the methods do not exist:
> internal ticket counts, per-assignee workload, cycle time, time in column, AI runs, AI
> cost, token usage, the internal/visible split, or anything at all about work the customer
> is not shown.

A member of staff may open the customer screen too, and should — it is the only way to see
what a customer actually sees. When they do they see **their own** visibility, not a
simulated customer's, and the screen says so.

## Metrics

### Present-state metrics — `TeamStatistics`

Everything here is a question about *now*, read from `tickets`.

| Metric | Output |
|---|---|
| `counts` | Total, open, closed, created in the period |
| `byColumn` | Tickets per status column, in board order — tab by tab, labelled `Tab › Column`, on a board split into tabs |
| `byPriority` | Tickets per priority, most urgent first, including zeros |
| `byAssignee` | Open tickets per assignee, busiest first — **staff only** |
| `byLabel` | Tickets per label, most used first — **staff only** |
| `createdByWeek` | Tickets raised per week |
| `visibilitySplit` | Shared with the customer versus internal — **staff only** |
| `recentTickets`, `labelCount` | Supporting detail |

Each breakdown is **one grouped query**. A per-assignee table built by counting tickets per
member in PHP would be one query per person on a page that already runs a dozen.

Statistics are **board-wide**: a board split into tabs is still counted whole, with no tab
filter. Where a column is named — `byColumn`, time in column, the CSV exports — it reads
`Tab › Column` on a board with several tabs (two tabs can both have a "To Do"), and exactly as
before on a board with one.

### Flow metrics — `FlowMetrics`

Read from `ticket_events`, the append-only history — **never from `tickets.board_column_id`**.

> [!NOTE]
> The distinction is what makes this class worth having. *"Twelve tickets are in Done"* is
> **not throughput**: those twelve might have arrived over two years, and the eight finished
> last month and then reopened are invisible. Flow is a property of **transitions**.
>
> The history was designed for this from the first migration — `ticket_events` carries
> `board_id`, `to_column_id` and `from_column_id` as indexed columns precisely so that "which
> tickets entered a done column on this board last week" is one index range scan.

| Metric | Output |
|---|---|
| `closures` | Tickets that entered a done column in the period — see below for deleted columns and tidying |
| `throughputByWeek`, `throughputPerWeek` | Completion rate over time |
| `cycleTime`, `cycleTimeTrend` | How long work takes, and whether that is moving |
| `timeInColumn` | Where work waits |
| `wasTruncated` | Whether the fold hit its bound |

Two limitations are **surfaced to the reader rather than hidden**:

- a ticket that has never left its column contributes no interval to the time-in-column
  figures. Only completed stays are measured, because an open-ended one has no length yet and
  counting "so far" would make a healthy board look worse the longer it stays healthy;
- the fold is bounded. A range wide enough to exceed it **reports that it was truncated**
  instead of quietly averaging a subset.

**Restructuring a board is not finishing work.** Deleting a column or a tab moves its tickets
(reason `column_deleted` / `tab_deleted` in the event); those moves never count as closures,
so done tickets carried into another done column are not counted as closed again. And
finished work does not drop out when its column is deleted: each transition records whether
its column was done at the time (`to_done`), which is what decides for a column that no longer
exists. (Events written before this was recorded cannot be recovered that way.)

### AI metrics — `AiStatistics`

`summary`, `byBoard`, `byWeek`, and cost formatting. **Staff only, and not by a check in the
class**: every query starts from `StatisticsScope::aiRuns()`, which starts from
`AiRun::visibleTo()`, which refuses customers outright. A customer asking for a total gets
zero rows — not a filtered subset, and not an exception that would itself confirm the feature
exists.

The honesty rule carries through: **a run whose model or token counts were never reported has
no cost, and is reported as an unpriced run rather than folded in as zero.** A cost total
that quietly understates itself by the runs it could not price is worse than one that says so.

### Customer metrics — `CustomerStatistics`

`counts`, `byPriority`, `statusSplit` (open versus closed), `createdByWeek`,
`recentActivity`, `recentTickets`. Underneath, `StatisticsScope::tickets()` starts from
`Ticket::visibleTo($viewer)`, so for a customer the rows are already restricted to
`customer_visible = true` on boards they belong to, **in SQL, before any counting happens**.

## Filters

Four query-string values, resolved by `StatisticsScopeResolver`.

| Filter | Values |
|---|---|
| Board | A slug, or all reachable boards |
| Range | `this_month`, `this_quarter`, `this_year`, `custom` |
| From / To | Dates, for a custom range |

`StatisticsScopeResolver` was extracted from the Livewire trait because the CSV export is a
controller and cannot use a trait — and because an export that resolved its own board and
date range would be a **second definition of "which numbers is this person allowed to ask
for"**, and the copy in the download route is exactly the one nobody would notice going
stale.

Nothing there is trusted:

- the board slug is resolved through `BoardAccess`, so a slug the viewer cannot reach yields
  a scope over **nothing** rather than that board's figures. *"No such board"* and *"not your
  board"* are deliberately the same answer, so the filter cannot be used to discover which
  boards exist;
- the range is parsed by `StatsPeriod`, which **corrects rather than trusts**: an unknown
  preset falls back to the default, reversed dates are swapped, and an absurd span is clamped
  to `MAX_DAYS` (1,096 — three years). A hand-edited URL cannot ask the database for ten
  years of transitions.

## Export

| Export | Route | Gate | Produced by |
|---|---|---|---|
| Team CSV | `/stats/export?dataset=…` | `role:admin,team`, re-checked in the controller | `StatisticsExport` |
| Customer CSV | `/stats/customer/export` | auth | `CustomerStatisticsExport` |
| Chart PNG / SVG | No route | — | The browser, from the inline SVG already on the page |

The dataset names are an **allow-list**. `dataset=…` arrives from a query string and anything
not in `KEYS` is refused rather than reflected — no dynamic method call, no
`$this->{$name}()`.

Every dataset is derived from a `StatisticsScope` resolved for the requesting viewer, so a
CSV cannot contain a figure its requester could not see on screen. There is no path that
takes a board id, a ticket id or anything else a URL could name.

> [!NOTE]
> **There is deliberately no per-ticket export.** These are aggregates. "Every ticket on this
> board as a spreadsheet" is a different feature with a different risk profile — it would
> carry titles and descriptions out of the application in bulk — and nobody has asked for it.

PNG and SVG are not server routes because the chart is already inline SVG on the page:
exporting it is serialising a node that is already there, and rasterising it is drawing that
node into a canvas. No library, no headless browser, no round trip.

<!-- pagebreak -->

# Realtime

## Transport

Laravel Reverb, a first-party WebSocket server, deployed as its own Railway service from the
same image with a different start command.

| Piece | Detail |
|---|---|
| Server | `php artisan reverb:start --host=0.0.0.0 --port=$PORT` |
| Client | Laravel Echo + pusher-js, constructed in `resources/js/echo.js` |
| Authorization | `POST /broadcasting/auth` behind the `web` middleware, decided by `routes/channels.php` |
| Optional | With `BROADCAST_CONNECTION=null` everything still works; screens simply do not refresh by themselves |

> [!WARNING]
> **`VITE_REVERB_*` are build arguments, not runtime variables.** Vite inlines
> `import.meta.env.*` at build time, and `echo.js` only constructs `window.Echo` when a key is
> present. An image built without one ships a browser bundle that never subscribes to
> anything, whatever the server is configured to broadcast. Changing one requires a **rebuild**
> of the web image, not a restart.
>
> Only the **public** key belongs in the build stage. `REVERB_APP_SECRET` must never be
> passed there — everything in that stage is readable in the shipped JavaScript.
>
> Keep the Reverb service at **one replica**. Reverb holds its connections in the memory of
> the process that accepted them, so a second replica would only deliver an event to the half
> of the clients attached to it. Several replicas need `REVERB_SCALING_ENABLED=true` and a
> shared Redis service.

## Channels

Every channel is **private**. There is no public channel and no presence channel.

| Channel | Who may join | Carries |
|---|---|---|
| `board.{id}.internal` | Staff members of the board — `canView()` **and** `canSeeInternalContent()` | Content-free signals about internal changes |
| `board.{id}.customer` | Anyone who may view the board, customers included | Content-free signals about customer-visible changes |
| `users.{id}` | That user, while active | A nudge for the notification bell |

Both board callbacks delegate to `BoardAccess`. Membership and the customer boundary are not
restated in `channels.php`; that file decides **which existing question to ask**.

## The audience rule

`App\Services\BoardBroadcaster` is the whole of the "do not broadcast internal data to
customers" rule, in one place.

> [!IMPORTANT]
> The rule for tickets is subtler than "is it customer-visible?", because **a ticket that has
> just been hidden also changes what customers see** — their card has to disappear. So the
> customer audience is signalled when the ticket is customer-visible **now OR was before the
> change**.
>
> Both are **content-free signals**: what the customer's browser then receives comes from the
> ordinary scoped query. And because the internal channel is only signalled for internal
> changes, a customer's browser is **never even woken** by activity they are not allowed to
> know about — which closes the timing side-channel a single shared channel would leave open
> even with an empty payload.

Events are dispatched **after the surrounding transaction commits**. Broadcasting a change
that then rolls back would tell every open board to re-read something that never happened.

## What updates live

| Surface | Behaviour |
|---|---|
| Kanban board | Re-reads when a ticket on a joined channel changes, and when a tab or column is added, renamed, reordered or removed (`BoardBroadcaster::structureChanged`, both audiences — structure is not secret). Signals raised inside one transaction are coalesced per board and audience, so deleting a tab with fifty tickets wakes each open board once |
| Ticket conversation | New comments appear without a reload |
| Notification bell | Re-reads when `NotificationReceived` fires on the user channel |
| Documentation autosave | **Deliberately does not broadcast** — see section 9 |

<!-- pagebreak -->

# Theme System

Three states, defined in `App\Enums\ThemePreference`: `light`, `dark`, `system`.

Three cases rather than a boolean, because "follow the operating system" is a genuine third
answer and not a default for one of the other two: somebody whose laptop turns dark at sunset
wants Nexora to do the same, and storing that as `dark` at dusk would be wrong by morning.

## Where the preference lives

| Layer | Role |
|---|---|
| `users.theme_preference` | The durable record, so the choice **follows a person between devices** |
| `localStorage` | A cache, so the correct theme is applied before any network request |
| The `<html>` element | **The source of truth at runtime** — both of the above are caches of it |
| An inline script in the layout head | Applies the class **before the bundle downloads**, so there is no flash of the wrong theme |

`light` is the default: it is the appearance the product had before the setting existed, so
anybody who never opens the control sees exactly the Nexora they already know. Following the
device is offered, never assumed.

> [!NOTE]
> The enum deliberately does **not** know which of light or dark `system` currently resolves
> to. Only the browser can answer that, it can change while a page is open, and nothing on the
> server needs it — the server's job is to render the *preference* so `theme.js` can apply it
> without a flash.

## How a change is saved

```diagram
   Click
     |
     +──► set the class on <html>          } synchronous,
     +──► write localStorage               } so the colour changes instantly
     |
     +──► PUT /settings/theme              asynchronous, best-effort
              |
              v
     ThemePreferenceController validates against the enum
              |
              v
     users.theme_preference updated
```

A one-line controller rather than a Livewire action, and that is the whole design: the
appearance has **already changed** by the time the request is sent. A Livewire round trip
would put a server response between the click and the colour changing, and a theme toggle
that waits is a theme toggle that feels broken.

Which also means **this endpoint failing is not an error worth showing anybody**. The theme
is already applied and already in `localStorage`; all that is lost is the cross-device part,
and it will be written again the next time somebody touches the control.

`PUT` rather than `POST` because it replaces a single value on the signed-in person's own
row — there is no user id in the payload and no way to name somebody else.

## Security note

`theme_preference` is cast to the enum but is **deliberately not mass-assignable**, for the
same reason `role` is not. The value is rendered into a class attribute in the layout head,
so an unvalidated string must never reach the column. The controller checks against the enum
and refuses otherwise; there is no path that stores what it was given.

## Coverage

Every component in the product carries both palettes. The one design rule worth recording:

> [!NOTE]
> **Amber means attention, and nothing else, in this design system.** The internal-content
> signal is **slate plus a lock icon**, not amber — so a reader never has to work out whether
> an amber badge means "warning" or "internal". `AiCapabilityMode` follows the same rule:
> Observer is slate because "safe" is the normal state, Operator is brand, and only Agent —
> the mode that runs unattended — is amber.

<!-- pagebreak -->
