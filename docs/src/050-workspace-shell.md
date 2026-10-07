# Dashboard and Workspace Shell

## There is no "workspace" entity

This is worth stating first, because it shapes everything else: **there is no workspace
table and no tenant model**. One deployment *is* one workspace. The unit of access is the
**board**, and almost everything that looks like a workspace-level setting is in fact a
board setting. The two genuine exceptions are the global AI settings (`ai_settings`, a
single row) and user administration.

## Navigation

The application shell (`resources/views/layouts/app.blade.php`) provides:

| Element | Contents |
|---|---|
| **Sidebar** | Dashboard, Boards, Activity (staff), Statistics, Settings, plus the theme switcher at its foot |
| **Top bar** | Page title, breadcrumbs, command-palette trigger, AI assistant trigger, notification bell, user menu |
| **AI panel** | A persistent drawer, mounted once for the whole application |
| **Command palette** | Mounted once, opened with `Cmd/Ctrl + K` |

Links a viewer cannot use are hidden rather than shown-and-refused, but that is a courtesy
only — every destination re-authorizes on arrival.

Navigation uses Livewire's `wire:navigate`, so pages are swapped without a full reload. This
is why the AI panel, the command palette, the theme store and the dialog system are all
implemented as **Alpine stores** rather than per-page component state: they must survive the
page underneath them being replaced.

## Dashboard

`/dashboard` — `App\Livewire\Dashboard\Index`

The landing page is role-aware in its **copy and actions**, never in its data. There is no
branching on role for the board list: `BoardAccess::query()` already returns every board for
an administrator and only explicit memberships for everybody else.

## Command palette and global search

`Cmd/Ctrl + K` — `App\Livewire\Search\Palette`, backed by `App\Services\Search\GlobalSearch`.

Four categories, at most five hits each:

| Category | Source | Visibility applied by |
|---|---|---|
| Tickets | `tickets` | `Ticket::visibleTo()` — board scope plus the customer flag |
| Documentation | `doc_pages` | `DocPageFinder` — flag **and** the ancestor rule |
| Boards | `boards` | `BoardAccess::query()` |
| People | `users` | Staff only, narrowed to people the viewer shares a board with |

Implementation notes that matter:

- Ticket search runs **two queries rather than one**. `"NL-123"` is an identifier and
  deserves an index — the prefix resolves to a board and `(board_id, number)` is unique, so
  that lookup is a key seek however large the table gets. Everything else is prose and has
  to be scanned. The exact match is placed first and then excluded from the scan, so pasting
  a key puts that ticket at the top.
- Minimum term length is **2 characters**; the term is bounded to 120 characters before it
  reaches a `LIKE`.
- The term is debounced in the template, not in PHP — a palette that queried on every
  keystroke would be four table scans per character.
- Open/closed state is an **Alpine store**, not Livewire state, because pressing the
  shortcut has to put a focused input on screen immediately.

> [!NOTE]
> **Search is plain SQL, on purpose.** There is no Scout, Meilisearch, Algolia or
> Elasticsearch. An external index has no idea that a ticket is internal, so the filtering
> would have to be reproduced in the engine's query language, correctly, for ever — and it
> would hold a second copy of every ticket description outside this application's visibility
> rules. The documented next step, when `LIKE '%term%'` stops scaling, is a **MySQL FULLTEXT
> index** on `tickets.title` and `description_md`, which stays inside MySQL and needs no new
> service. Scout is the step after that.

## Notifications

`App\Livewire\Notifications\Bell`, backed by `App\Services\NotificationReader`.

The bell holds **no notification data of its own**. Everything is read on each render and
re-checked against the visibility scopes, so what is shown is what the viewer may see *now*,
not what they were allowed to see when the notification was written.

With realtime enabled the bell re-reads when the server says something arrived; without it,
it polls slowly. Either way the work is bounded — at most `NotificationReader::WINDOW` rows
and three lookups.

## Activity feed

`/activity` — `App\Livewire\Activity\Index`. **Staff only** (`role:admin,team`).

A workspace-wide history — ticket movement, assignments, comments, documentation changes and
board configuration — newest first, backed by `spatie/laravel-activitylog` in the
`activity_log` table.

There is deliberately **no per-board activity route**. A board is a filter on this screen
(`?board=slug`), which keeps one query, one authorization path and one set of filters rather
than two of each.

> [!IMPORTANT]
> Customers do not get this screen at all, and that is a design decision rather than an
> oversight. Every description in the feed is written for the delivery team and names
> internal tickets, internal notes and board configuration; there is no per-row rewriting
> that would make it customer-safe. It is enforced three times — route middleware, a
> component check on mount and on render, and `Activity::readableBy()` refusing a non-staff
> viewer in SQL regardless.

## Settings

`/settings` — `App\Livewire\Settings\Index`.

A **directory, not a store**. Settings in this product are spread across seven screens
because each grew next to the thing it configures; what was missing was one page that knows
they all exist. This component owns no settings of its own and writes nothing.

| Group | Destination | Who sees the link |
|---|---|---|
| Personal | `/settings/profile` | Everyone |
| Board | `/boards/{board}/settings` — columns and labels | Staff members of the board |
| Board | `/boards/{board}/ai/settings` | Staff members of the board |
| Board | `/boards/{board}/integrations` — Slack and SMS | Staff members of the board |
| Board | `/boards/{board}/edit` — name, prefix, members | Administrators |
| Workspace | `/admin/ai` | Administrators |
| Workspace | `/admin/users` | Administrators |

The board selector exists because most of what looks like a workspace setting is in fact a
board setting. The selection is resolved through `BoardAccess` on every render, so a slug in
the query string can only ever name a board the viewer already has.

## Profile

`/settings/profile` — `UpdateProfile` and `UpdatePassword`.

Name and email, and a separate password form. Changing a password invalidates every other
session for that account, because `AuthenticateSession` binds a session to the password hash
it was created with.

## Responsive behaviour

The interface is built with Tailwind's responsive utilities throughout and works at phone
width. The AI panel is a full-height drawer on desktop and an overlay on small screens.
There is **no native mobile application** and no offline mode.

<!-- pagebreak -->
