# Board Management

## Purpose

A board is the unit of work, the unit of access and the unit of configuration. Everything
in Nexora hangs off one: tickets, documentation, labels, tabs and columns, repositories,
Slack and SMS settings, AI settings, and statistics scope. **Access to Nexora is access to a
set of boards.**

## Who can do what

| Action | Who |
|---|---|
| See a board | Administrators (all), or anyone with a `board_members` row |
| Create, rename, delete a board | Administrators only, with password confirmation |
| Add or remove members | Administrators only |
| Manage tabs, columns and labels | Any staff member of the board |
| Manage AI settings, repositories, integrations | Any staff member of the board |

> [!NOTE]
> The split is deliberate. The board *record* — its name, prefix, membership and existence —
> stays administrator-only. The board's *working surface* — columns, labels, AI settings,
> repositories, integrations — is the delivery team's daily work on their own board. The
> expensive switch inside the AI screen carries a cost cap nobody can bypass, which is what
> makes it safe to hand to the team.

## Board lifecycle

```diagram
  CREATE                    CONFIGURE                     OPERATE                ARCHIVE
  ------                    ---------                     -------                -------
  Admin opens               Team adds/renames             Tickets flow           archived_at
  /boards/create            columns                       across columns         is set
      |                          |                             |                    |
      v                          v                             v                    v
  Name entered             Labels created             Documentation written   Hidden from
      |                          |                             |               notArchived()
      v                          v                             v               scopes
  Slug + prefix            GitHub repo attached       AI runs, Slack posts,
  auto-derived                   |                    GitHub links accumulate
      |                          v
      v                    Slack webhook + SMS rota
  "Main" tab with                |
  5 default columns:             v
  Backlog, To Do,          AI model, capability mode,
  In Progress,             project context, auto-run
  Review, Done                   policy
      |
      v
  Members added
  (BoardMemberAdded event)
```

### Creation

`/boards/create` — `App\Livewire\Boards\ManageBoard`, behind `role:admin` and
`password.confirm`.

| Field | Rule |
|---|---|
| Name | Required |
| Slug | Derived by `BoardIdentifiers`, globally unique, used in every URL |
| Ticket prefix | Derived by `BoardIdentifiers`, globally unique, 2–6 characters, `/^[A-Z][A-Z0-9]*$/` |
| Description | Optional |
| Settings | A JSON column, empty at creation; every option falls back to `config/workspace.php` |

On creation, `CreateDefaultColumns` gives the board one tab, **Main**, with the standard
workflow — **Backlog, To Do, In Progress, Review, Done** — with `Done` marked `is_done`. The
action is idempotent per tab, so it can also repair a tab that somehow ended up with no
columns. Neither the tab nor the columns are logged or broadcast separately: they are part of
creating the board.

Primary keys are never exposed: `Board::getRouteKeyName()` returns `slug`.

### Board settings JSON

`boards.settings` is a JSON column read through `Board::setting()`, which falls back to
`config('workspace.board_defaults')`. New board options can therefore be introduced without
a migration or a backfill.

| Key | Default | Meaning |
|---|---|---|
| `new_tickets_visible_to_customers` | `true` (`WORKSPACE_NEW_TICKETS_VISIBLE`) | A ticket staff create starts visible to the board's customers: the starting value of the box on the New ticket form and in quick add, and the most visible an assistant-created ticket can be |
| `customers_can_comment` | `true` | Whether customers who are members may post to the customer conversation |
| `timezone` | `UTC` | The board's own day, used for daily AI run caps and statistics |
| `ai.*` | see section 10 | Model, provider, capability mode, project context, auto-run policy, per-board credentials |
| `slack.*` | see section 14 | Encrypted webhook URL and per-event switches |
| `sms.*` | see section 15 | On-call rota for this board |

## Tabs

A board is split into **tabs** — "Admin dashboard", "Public website", "Backend API" — like
browser tabs: one board, several boards' worth of work. Each tab has **its own columns**; the
board shows one tab at a time, and the tab strip above the columns switches between them
without leaving the board. Every board has at least one tab (existing boards were given
**Main**, holding all their columns).

A ticket has no tab of its own: **its tab is the tab of its column**. So a ticket can never
sit in a column of another tab, and moving it to another tab is moving it to a column of that
tab — always an explicit act (see *Moving between tabs* below).

| Property | Detail |
|---|---|
| Name | Up to 60 characters, trimmed, **unique per board ignoring case** (checked in the actions, the database unique is the backstop) |
| Slug | Made from the name when the tab is created, unique per board (`backend-api`, `backend-api-2`) and **never changed on rename**, so a link to a tab keeps working. The board URL carries it: `/boards/{board}?tab=backend-api` |
| Position | Leftmost first. The leftmost tab is the board's **default tab**: what the board opens on, and where a ticket filed without a tab lands |
| Columns | Each tab's own — names unique per tab, so two tabs can both have a "To Do" |

`board_tabs` (`App\Models\BoardTab`); `board_columns.board_tab_id` is NOT NULL, and the
composite foreign key `(board_tab_id, board_id) → board_tabs(id, board_id)` makes the database
itself refuse a column whose tab is on another board. A tab that still has tickets cannot be
deleted underneath them: the cascade to its columns meets `tickets.board_column_id`'s
RESTRICT.

### The tab strip

| Behaviour | Detail |
|---|---|
| Switching | Click a tab, or arrow / Home / End to a tab and Enter (manual activation: each switch is a round trip). `role="tablist"` / `tab` / `tabpanel` with `aria-selected` |
| URL | `?tab=<slug>` (`#[Url(history: true)]`), so a tab is a link, refresh keeps it, and back/forward step between tabs. The default tab keeps the plain URL. A slug that is not one of this board's tabs falls back to the default tab with a notice |
| Remembered | A board opened without `?tab` shows the tab **this person last had open on it** (kept in their session), else the default tab |
| Counts | Each tab shows how many tickets match the current filters — by the same visibility scope as the cards, so a customer's counts never include internal work |
| Filters | Search, assignee, priority, labels and visibility belong to the board and stay on when switching tabs |
| Shown | When the board has more than one tab, or to anyone who may manage tabs (so "+" is always there for them) |
| Realtime | Tab and column changes broadcast `BoardUpdated` to the internal **and** customer channels (tab and column names are visible to customers). If the tab you have open is removed, the board falls back to the default tab and says so |

### Managing tabs

From the tab strip on the board — the same people who may manage columns
(`BoardPolicy::manageTabs` → `manageColumns`: administrators, and staff members of the
board). Every change is re-authorized and resolved within the board (404 otherwise), and
recorded in the workspace feed (*Tab added / renamed / reordered / removed*, internal-only).

| Action | Detail |
|---|---|
| Add ("+") | Asks for a name. The new tab starts with a **copy of the open tab's columns** (names, order, which count as done) and opens |
| Rename | Tab menu → Rename. The slug stays |
| Reorder | Drag a tab along the strip, or Tab menu → Move left / Move right (the keyboard's way) |
| Edit columns | Tab menu → Edit columns: board configuration, on that tab |
| Delete | Tab menu → Delete opens a panel built on the server. The last tab cannot be deleted. A tab holding tickets needs a destination tab, and the panel previews where each column's tickets will land |

**Deleting a tab never deletes its tickets.** They move to the chosen tab, each to:

1. the column with the **same name** (ignoring case);
2. otherwise, if it was in a done column, the destination's **first done column**;
3. otherwise the destination's **first column** —

appended at the end, in the order they had. Each move is in the ticket's timeline with the
reason (*its tab was removed*); nobody is notified per card and Slack is not told about tickets
that were already done. A name match wins over the done rule, so a done ticket can land in a
same-named column that is not done (the preview shows it). `App\Support\TabColumnMapping`
holds the rule; `App\Actions\Tabs\DeleteTab` applies it in one transaction under the board
lock.

### Moving between tabs

| From | How |
|---|---|
| The board | Drag a card onto another tab (staff). It lands by the rule above, at the end |
| The ticket page | The **Tab** choice beside Status (staff with `move`). The Status list is always the columns of the ticket's own tab |
| The assistant, MCP | `tab` on update — see section 9 |

`App\Actions\Tickets\MoveTicketToTab` is the one way a ticket changes tab. `MoveTicket`
itself refuses a column in another tab unless told the move is across tabs, so a stale or
forged column id from a drag can never carry a ticket somewhere its board view does not show.

### Customers

Customers see **every tab's name** — the board's structure is not secret, as with columns —
but inside a tab only the tickets they can already see, and the counts count only those. They
may say which tab a request belongs in (New ticket form, quick add on the tab, MCP); it always
starts in that tab's first column. They cannot move tickets between tabs.

## Columns

`/boards/{board}/settings` — `App\Livewire\Boards\Settings` — edits **one tab's columns at a
time**: the tab in `?tab=`, chosen with the pills above the list (shown when the board has
more than one tab).

| Property | Detail |
|---|---|
| Name | Up to 60 characters, **unique per tab** |
| Position | Integer within the tab, drag-and-drop reorderable |
| `is_done` | Marks a column as terminal — drives *Ticket moved to done* notifications and closure statistics |

A tab always keeps at least one column (enforced in `DeleteColumn`, under the board lock), and
a deleted column's tickets can only move to another column **of the same tab**.

Deleting a column moves every ticket out of it one model at a time. The move is recorded in
each ticket's timeline and broadcast, but **the "moved to" notification is suppressed** —
otherwise tidying a board would notify everyone assigned a card on it. This is done with a
declared property on the model (`$withoutStatusNotification`) rather than a flag on a
service, so it can never be left globally switched on by a forgotten `finally`.

The foreign key from `tickets.board_column_id` is `restrictOnDelete`, so a column cannot be
deleted out from under its tickets by accident.

## Labels

Also on `/boards/{board}/settings`.

| Property | Detail |
|---|---|
| Name | Up to 40 characters, **unique per board** |
| Colour | From the fixed `App\Enums\LabelColor` palette, default `slate` |

Labels are board-scoped. Attaching one is staff-only; a customer can see the labels on a
ticket they may read but cannot change them.

## Members

`/boards/{board}/edit` — `App\Livewire\Boards\Members`, administrator-only.

Membership is an explicit `board_members` row with a unique `(board_id, user_id)` index and
an `added_by_id` audit column. Administrators are **not** implicit members —
`Board::hasMember()` answers a factual question about the pivot table, while
`BoardAccess::canView()` answers the authorization question and applies the administrator
bypass.

Only **active, non-customer** members appear in the assignee picker
(`Board::assignableMembers()`), because assignment means "this person is doing the work".

Three events are raised for board lifecycle and feed the workspace activity log:
`BoardCreated`, `BoardMemberAdded`, `BoardMemberRemoved`, plus `BoardUpdated`.

## Board visibility and customers

A board has **no visibility flag**. A customer either is a member or is not. What varies is
what they see *inside* it:

| Inside a board a customer belongs to | Visible to them? |
|---|---|
| Customer-visible tickets | Yes |
| Internal tickets | No — and not their count, not their existence |
| The customer conversation on a ticket | Yes |
| Internal notes | No — and not their count |
| Published documentation pages | Yes, if every ancestor is published too |
| Internal documentation pages | No |
| Tabs, columns and labels | Yes — the board structure is not secret (tab counts include only what they can see) |
| AI runs | No — 404 |
| Attached repositories and GitHub activity | No |
| Board AI chat, AI settings, integrations screens | No — 404 |

## The Kanban board

`/boards/{board}` — `App\Livewire\Boards\Show`.

One component owns the whole board rather than nesting a component per card. A
drag-and-drop move has to re-render the source column, the destination column and both
counts together, and with per-card components that would be several round trips instead of
one.

| Feature | Detail |
|---|---|
| Tabs | One tab at a time; the strip switches between them and shows per-tab counts (see *Tabs*) |
| Drag and drop | Cards between columns and within a column of the tab on screen, and onto another tab in the strip. Uses Alpine's `x-sort`, bundled with Livewire 4 |
| Filtering | By search term, assignee, label, priority and visibility (`App\Support\TicketFilters`) — board-wide, kept across tabs |
| Quick add | A ticket can be created inline from a column; it is filed in the tab on screen |
| Realtime | The board re-reads when `BoardUpdated` is broadcast to a channel this viewer may join |
| Access | Decided once in `mount()` by `BoardPolicy::view` (404 on denial); every ticket read then goes through `TicketFinder` |

## Repository attachment

`board_repositories` — configured on the board AI settings screen.

| Column | Meaning |
|---|---|
| `repository_name` | `owner/name`, unique per board |
| `repository_url` | Optional display URL |
| `default_branch` | Defaults to `main` |
| `is_primary` | Which repository apply mode targets when a run does not name one |
| `description` | Free text, fed to the AI as context |
| `configuration` | JSON — build and test commands surfaced to the assistant |

Several boards may attach the **same** repository, which is legitimate: a shared platform
repository serves more than one customer's board, and each gets its own links when a ticket
key matches.

> [!IMPORTANT]
> Repository attachment is the **security boundary for inbound GitHub webhooks**. A
> delivery may only reach tickets on boards where that repository has already been attached.
> See section 13.

<!-- pagebreak -->
