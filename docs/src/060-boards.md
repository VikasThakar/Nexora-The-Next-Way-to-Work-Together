# Board Management

## Purpose

A board is the unit of work, the unit of access and the unit of configuration. Everything
in Nexora hangs off one: tickets, documentation, labels, columns, repositories, Slack and
SMS settings, AI settings, and statistics scope. **Access to Nexora is access to a set of
boards.**

## Who can do what

| Action | Who |
|---|---|
| See a board | Administrators (all), or anyone with a `board_members` row |
| Create, rename, delete a board | Administrators only, with password confirmation |
| Add or remove members | Administrators only |
| Manage columns and labels | Any staff member of the board |
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
  5 default columns              |
  created:                       v
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

On creation, `CreateDefaultColumns` gives the board the standard workflow — **Backlog, To
Do, In Progress, Review, Done** — with `Done` marked `is_done`. The action is idempotent, so
it can also repair a board that somehow ended up with no columns.

Primary keys are never exposed: `Board::getRouteKeyName()` returns `slug`.

### Board settings JSON

`boards.settings` is a JSON column read through `Board::setting()`, which falls back to
`config('workspace.board_defaults')`. New board options can therefore be introduced without
a migration or a backfill.

| Key | Default | Meaning |
|---|---|---|
| `default_content_is_internal` | `true` | New content on the board is internal unless stated otherwise |
| `customers_can_comment` | `true` | Whether customers who are members may post to the customer conversation |
| `timezone` | `UTC` | The board's own day, used for daily AI run caps and statistics |
| `ai.*` | see section 10 | Model, provider, capability mode, project context, auto-run policy, per-board credentials |
| `slack.*` | see section 14 | Encrypted webhook URL and per-event switches |
| `sms.*` | see section 15 | On-call rota for this board |

## Columns

`/boards/{board}/settings` — `App\Livewire\Boards\Settings`.

| Property | Detail |
|---|---|
| Name | Up to 60 characters, **unique per board** |
| Position | Integer, drag-and-drop reorderable |
| `is_done` | Marks a column as terminal — drives *Ticket moved to done* notifications and closure statistics |

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
| Columns and labels | Yes — the board structure is not secret |
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
| Drag and drop | Cards between columns and within a column; columns themselves reorderable. Uses Alpine's `x-sort`, bundled with Livewire 4 |
| Filtering | By search term, assignee, label, priority, type and visibility (`App\Support\TicketFilters`) |
| Quick add | A ticket can be created inline from a column |
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
