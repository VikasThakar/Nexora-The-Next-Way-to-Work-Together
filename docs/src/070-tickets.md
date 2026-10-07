# Ticket Management

## Anatomy of a ticket

| Field | Type | Notes |
|---|---|---|
| `number` | integer | Per board, allocated under a row lock. **Unique with `board_id`** |
| `title` | string(200) | Required |
| `type` | enum | `bug`, `task`, `feature` — default `task` |
| `description_md` | text | Markdown, written through the rich-text editor |
| `priority` | enum | `critical`, `high`, `medium`, `low`, `nice_to_have` — default `medium` |
| `assignee_id` | FK users | Active non-customer members of the board only |
| `estimate` | decimal(6,2) | Staff-only field |
| `due_date` | date | Indexed |
| `customer_visible` | boolean | **Defaults to `false`** — the customer boundary |
| `board_column_id` | FK columns | Status. `restrictOnDelete` |
| `position` | integer | Order within the column |
| `created_by_id` | FK users | Nullable on user deletion |

The human key is `PREFIX-NUMBER` — `AQD-42` — built from the board's `ticket_prefix`.

> [!IMPORTANT]
> `customer_visible` is stored **positively and defaults to `false`**, so a row created by
> code that forgets to set it is internal rather than exposed. It is deliberately **not
> mass-assignable**: only `App\Actions\Tickets\*` can set it, which is where the rule "a
> customer may never create or reveal an internal ticket" lives and is tested.

## Ticket numbering

Numbers are allocated inside `CreateTicket` with `SELECT ... FOR UPDATE` on the board row,
then `next_ticket_number` is incremented. Combined with the unique `(board_id, number)`
index, this makes concurrent creation safe: two simultaneous requests cannot receive the
same number, and a lost update cannot produce a duplicate key.

## Ticket lifecycle

```diagram
   CREATED                                                    CLOSED
      |                                                          ^
      |  board.firstColumn (customer) or chosen column (staff)   |
      v                                                          |
  +---------+    +-------+    +-------------+    +--------+   +------+
  | Backlog | -> | To Do | -> | In Progress | -> | Review |-> | Done |
  +---------+    +-------+    +-------------+    +--------+   +------+
                                                                is_done = true
                                                                       |
   Along the way, appended to ticket_events (append-only):             v
     ticket_created, ticket_updated, ticket_moved,           TicketMovedToDone
     assignee_changed, priority_changed,                     -> Slack (if enabled)
     visibility_changed, label_changed, link_changed,
     attachment_changed, checklist_converted,
     ai_run_queued / completed / failed / skipped,
     github_branch_created, github_commit_pushed,
     github_pull_request_opened / merged / closed,
     github_check_completed
```

## Creating a ticket

`/boards/{board}/tickets/create` — `App\Livewire\Tickets\Create`. A quick-add control on the
board does the same thing inline.

The rules live in `App\Actions\Tickets\CreateTicket`, not in the form, so they hold no
matter which screen — or which AI proposal — calls it:

| Rule | Customer | Staff |
|---|---|---|
| Visibility | **Always customer-visible** | Defaults to internal |
| Column | **Always the board's first column** | Any column on this board |
| Assignee | **Always unassigned** | Any assignable member |
| Estimate | Not settable | Settable |

A tampered `board_column_id` cannot file a ticket into another customer's board: the action
re-resolves the column against *this* board and refuses anything else.

## Editing a ticket

`/boards/{board}/tickets/{number}` — `App\Livewire\Tickets\Show`.

`TicketPolicy::update` allows staff always, and a customer **only for a ticket they raised
themselves**. `UpdateTicket` then narrows which fields a customer may touch, silently
dropping `customer_visible`, `assignee_id` and `estimate`.

The diff is taken from Eloquent's own dirty tracking, so an "update" that changes nothing
records nothing and the timeline stays readable.

## Ticket addressing

A ticket is addressed by its **per-board number**, so the URL reads the way the team talks:
`/boards/aqueduct-platform/tickets/42` is `AQD-42`. The number is bound as a plain integer
rather than as a model — resolving it is `TicketFinder`'s job, which applies board
membership and the customer rule and raises 404 when either fails. An internal ticket and a
number that was never used therefore look identical from outside.

## Labels, checklists, links and attachments

| Feature | Model | Who may change it |
|---|---|---|
| Labels | `ticket_label` pivot | Staff only |
| Checklist | `ticket_subtasks` — title, completed, position, `completed_at`, `completed_by_id` | Follows ticket update, so a customer may tick items on their own ticket |
| Links | `ticket_links` — `blocks`, `relates_to` | Staff only, and only to tickets they can already see |
| Attachments | Polymorphic `attachments` | Follows ticket update |

Checklist changes are deliberately **not** written to the ticket timeline: a checklist
changes often and would drown the events that matter.

### Checklist conversion

A ticket's standalone checklist can be moved into its description as Markdown checkboxes
(`ConvertSubtasksToChecklist`). This is **per ticket, on a button press** — never an
automatic data migration. The `ticket_subtasks` rows are removed, but the
`checklist_converted` event carries every item exactly as it stood, including the
`completed_at` and `completed_by_id` that a Markdown checkbox cannot express. The list is
*appended* under a heading rather than replacing anything.

## The two conversation streams

Every ticket carries two independent threads, defined by `App\Enums\CommentStream`:

| Stream | Who reads it | Who writes to it |
|---|---|---|
| `customer` | Everyone who can read the ticket | Staff always; customers if `customers_can_comment` is on for the board |
| `internal` | **Staff only** | Staff only |

> [!IMPORTANT]
> Customers must never receive internal rows, **their ids, their authors or their count**.
> The rule has one definition — `CommentStream::isCustomerFacing()` — which the query scope,
> the policy and the notification dispatcher all ask.

`App\Actions\Comments\PostComment` owns the audience rule:

- a comment written by a customer is **always** in the customer stream, whatever the request
  said;
- staff choose their stream explicitly, and the default when nothing is said is **internal**,
  so a code path that forgets to choose keeps quiet rather than publishing to a customer.

Everything that follows a post — who is notified, what is broadcast — is derived from the
stream that was actually stored, never from what the caller asked for.

Comments support `@mentions` of board members (`App\Services\MentionParser`), soft deletion
with a `deleted_by_id`, an `edited_at` marker, and attachments. Editing is author-only;
deletion is author-or-administrator.

## Ticket activity timeline

`ticket_events` is **append-only** and is the ticket's history. It carries `board_id`,
`from_column_id` and `to_column_id` as indexed columns specifically so that flow metrics —
"which tickets entered a done column on this board last week" — are one index range scan.

Twenty event types are defined, spanning human actions, AI runs and GitHub activity. They
appear in one unified timeline, so "branch created" sits between "Vikas created NL-3" and
"Alex changed priority" rather than in a separate panel.

Every event is mirrored into the workspace activity feed through `App\Services\TicketActivity`,
so there is no second activity system to keep in step.

## Filtering and search

`App\Support\TicketFilters` drives board filtering:

| Filter | Values |
|---|---|
| Search | Title, description, and the ticket number — `AQD-42`, `aqd-42` and `42` all match ticket 42 |
| Assignee | Any assignable board member, or unassigned |
| Label | Any label on the board |
| Priority | Any of the five |
| Type | `bug`, `task`, `feature` |
| Visibility | Internal / customer-visible (staff only) |

## What happens when a ticket changes

`App\Observers\TicketObserver` fans every change out. It is an observer rather than four
calls in four actions, because "every change to a ticket is announced" is a property of the
model, not of any one screen.

```diagram
   Ticket saved
        |
        +--> BROADCAST      BoardBroadcaster decides the audience. An internal
        |                   ticket signals only board.{id}.internal, so a
        |                   customer's browser is never even woken.
        |
        +--> NOTIFY         NotificationDispatcher writes one row per recipient,
        |                   each policy-checked. A customer cannot be notified
        |                   about an internal note even if something upstream tried.
        |
        +--> AI             On creation only, and only for a customer's ticket:
        |                   TriggerAutomaticAiRun (subject to board settings,
        |                   capability mode and the daily cap).
        |
        +--> SLACK          Per-board, per-event opt-in. Queued.
        |
        +--> SMS            Critical tickets only. Per-board opt-in. Queued.
```

None of these may throw — nothing here is important enough to fail a ticket save.
Broadcasting is queued; notification writes share the caller's transaction, so a rollback
takes them with it.

Position rewrites during a drag are done with the query builder rather than on models, so
shuffling ten cards produces **one** announcement from the ticket that actually moved, not
eleven.

## In-app notifications

| Notification | Trigger | Recipients |
|---|---|---|
| `TicketAssigned` | Assignee changes | The new assignee |
| `TicketStatusChanged` | Column changes | Assignee and participants |
| `TicketPriorityChanged` | Priority changes | Assignee and participants |
| `CommentPosted` | A comment is posted | Ticket participants who may read that stream |
| `MentionedInComment` | `@mention` in a comment | The mentioned person, if they may read it |

All are stored in the `notifications` table and surfaced by the bell. Every recipient is
checked against the same policy that guards the thing being notified about.

<!-- pagebreak -->
