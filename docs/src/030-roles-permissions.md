# User Roles and Permissions

## The three roles

Nexora has exactly three application-wide roles, defined in `App\Enums\UserRole`. The role
is stored on the `users` row and answers *"what kind of person is this?"* — **not** *"what
may they do on board X?"*, which is answered separately by board membership.

| Role | Value | Sees every board? | May see internal content? | May administer the workspace? |
|---|---|---|---|---|
| Administrator | `admin` | **Yes**, without membership | Yes | Yes |
| Team member | `team` | Only boards they are a member of | Yes | No |
| Customer | `customer` | Only boards they are a member of | **Never** | No |

The enum groups the first two as **staff** (`isStaff()`). The internal-content rule is
written as a deny-list of one, so that any future role is internal-unsafe unless it is
deliberately added to `isStaff()`.

> [!IMPORTANT]
> **Customer is the security boundary of the product.** Being a member of a board never
> grants a customer access to internal tickets, internal notes, internal documentation, AI
> runs, repository data or the activity feed — on any board, including boards they belong
> to. This is enforced independently of membership, in
> `BoardAccess::canSeeInternalContent()`.

## How access is decided

Four rules, implemented once in `App\Services\BoardAccess` and delegated to by every
policy, reader and channel-authorization callback:

1. An administrator may see every board.
2. Any other user may see a board only when an explicit `board_members` row links them.
3. A deactivated or unauthenticated user may see nothing at all.
4. Customers may never observe content flagged as internal, on any board.

```diagram
   Can this person see this record?
   --------------------------------
        Is the account active?              no  -->  deny (404)
               | yes
               v
        Is the user an administrator?       yes -->  board access granted
               | no
               v
        Is there a board_members row?       no  -->  deny (404)
               | yes
               v
        Is the record internal?             no  -->  allow
               | yes
               v
        Is the user staff?                  no  -->  deny (404)
               | yes
               v
             allow
```

> [!NOTE]
> **Denials are 404, not 403, wherever existence is itself a secret.** A 403 on
> `/boards/acme-platform` would confirm that a board with that slug exists. Boards,
> tickets, documentation pages, AI runs, AI conversations, the board AI chat and the board
> AI settings screen all deny as *Not Found*.

<!-- pagebreak -->

## Permission matrix — workspace and boards

Derived from the `EnsureUserHasRole` middleware on routes, `BoardPolicy`, `UserPolicy`,
`BoardAccess`, and the gates registered in `AppServiceProvider`.

| Capability | Administrator | Team member | Customer |
|---|---|---|---|
| Sign in | Yes | Yes | Yes |
| See the board index | Yes — all boards | Own memberships | Own memberships |
| Open a board | Yes — any | Member only | Member only |
| Create a board | **Yes** (+ password confirm) | No | No |
| Rename or edit a board | **Yes** (+ password confirm) | No | No |
| Delete a board | **Yes** | No | No |
| Add or remove board members | **Yes** | No | No |
| Create, rename, reorder, delete tabs | Yes | Member only | No |
| Create, rename, reorder, delete columns | Yes | Member only | No |
| Create, edit, delete labels | Yes | Member only | No |
| Configure board AI settings | Yes | Member only | No — screen 404s |
| Attach or remove GitHub repositories | Yes | Member only | No — screen 404s |
| Configure Slack and SMS for a board | Yes | Member only | No — screen 404s |
| Global AI settings (`/admin/ai`) | **Yes** (+ password confirm) | No — 403 | No — 403 |
| Create, edit, deactivate users | **Yes** (+ password confirm) | No — 403 | No — 403 |
| Change another user's role | **Yes** | No | No |
| Change **own** role | **No** — explicitly forbidden | No | No |
| Delete **own** account | **No** — explicitly forbidden | No | No |
| Edit own profile and password | Yes | Yes | Yes |
| Set own theme preference | Yes | Yes | Yes |

## Permission matrix — tickets

Derived from `TicketPolicy`, `App\Actions\Tickets\CreateTicket` and `UpdateTicket`.

| Capability | Administrator | Team member | Customer |
|---|---|---|---|
| See a customer-visible ticket | Yes | Member only | Member only |
| See an internal ticket | Yes | Member only | **Never** |
| Create a ticket | Yes | Yes | **Yes** — with the constraints below |
| Choose the tab at creation | Yes | Yes | **Yes** — they see every tab's name |
| Choose the column at creation | Yes | Yes | **No** — always the first column of its tab |
| Set visibility at creation | Yes (defaults to customer-visible) | Yes (defaults to customer-visible) | **No** — always customer-visible |
| Assign at creation | Yes | Yes | **No** — always unassigned |
| Edit a ticket raised by somebody else | Yes | Yes | **No** |
| Edit a ticket they raised themselves | Yes | Yes | **Yes** — restricted fields |
| Change title, description, priority, type, due date | Yes | Yes | Own tickets only |
| Change assignee | Yes | Yes | **No** — silently dropped |
| Change estimate | Yes | Yes | **No** — silently dropped |
| Change customer visibility | Yes | Yes | **No** — silently dropped |
| Move between columns (drag and drop) | Yes | Yes | **No** |
| Move to another tab (drop on a tab, the Tab choice, AI, MCP) | Yes | Yes | **No** |
| Add or remove labels | Yes | Yes | **No** |
| Link tickets to one another | Yes | Yes | **No** |
| Manage the checklist | Yes | Yes | Own tickets only |
| Attach files | Yes | Yes | Own tickets only |
| Delete a ticket | Yes | Yes | **No** |

> [!NOTE]
> A customer's restricted fields are **silently dropped** by `UpdateTicket` rather than
> rejected. `TicketPolicy::update` permits a customer to edit a ticket they raised; the
> action is what narrows *which fields* they may touch. So a crafted request that submits
> `assignee_id` cannot set it, even though the update itself is authorized.

## Permission matrix — conversations, documentation, files

| Capability | Administrator | Team member | Customer |
|---|---|---|---|
| Read the customer conversation | Yes | Yes | Yes |
| Post to the customer conversation | Yes | Yes | Only if the board allows customer comments |
| Read internal notes | Yes | Yes | **Never** — not even the count |
| Post an internal note | Yes | Yes | **No** |
| Edit a comment | Own only | Own only | Own only |
| Delete a comment | Own, **and anybody's** | Own only | Own only |
| Read published documentation | Yes | Yes | Yes — only if every ancestor is also published |
| Read internal documentation | Yes | Member only | **Never** |
| Create a documentation page | Yes | Member only | **No** |
| Edit, move or delete a page | Yes | Member only | **No** |
| Publish a page to customers | Yes | Member only | **No** |
| Attach a file to a page | Yes | Member only | **No** |
| Download an attachment | If the owning record is readable | Same | Same |
| Delete an attachment | The uploader, or anyone who may edit the owner | Same | Same |

<!-- pagebreak -->

## Permission matrix — AI

Derived from `BoardPolicy::useAiChat` and `::useAssistant`, `AiRunPolicy`,
`AiCapabilityGuard`, and each tool's `availableTo()`.

| Capability | Administrator | Team member | Customer |
|---|---|---|---|
| Open the assistant panel | Yes | Yes | **Yes** — read-only |
| Open the full-page board AI chat | Yes — member boards | Member only | **No** — 404 |
| Ask about a board | Yes | Member only | Member only |
| Ask about the whole workspace | Yes | Yes | Yes — own boards only |
| Attach files to a conversation | Yes | Yes | **Yes** — their own conversation |
| Read somebody else's conversation | **No** — ownership is absolute | No | No |
| Use the voice interface | Yes | Yes | **Yes** |
| Be offered *write* tools by the model | Yes, mode permitting | Yes, mode permitting | **Never, in any mode** |
| Confirm an AI proposal | Yes, mode permitting | Yes, mode permitting | **No** |
| See AI runs on a ticket | Yes | Member only | **Never** — 404 |
| Start a suggest run | Yes | Member only | **No** |
| Start an apply run (opens a pull request) | Yes | Member only | **No** |
| Cancel a queued run | Yes | Member only | **No** |
| Set the workspace capability mode | **Yes** | No | No |
| Set a board's capability mode | Yes | Member only | No |

### AI retrieval tools by role

| Tool | Administrator | Team member | Customer |
|---|---|---|---|
| `get_board` | Yes | Yes | Yes |
| `get_ticket` | Yes | Yes | Yes — customer-visible tickets only |
| `search_tickets` | Yes | Yes | Yes — customer-visible tickets only |
| `search_documentation` | Yes | Yes | Yes — published pages only |
| `get_documentation_page` | Yes | Yes | Yes — published pages only |
| `get_statistics` | Yes | Yes | Yes — five of the eight metrics |
| `get_activity` | Yes | Yes | **No** — the tool is not offered |
| `get_code_activity` | Yes | Yes | **No** — the tool is not offered |
| `get_github_repository` | Yes | Yes | **No** — the tool is not offered |
| `search_external_knowledge` | Only in *Outside Project* scope, and only when a driver is configured | Same | Same |
| `propose_*` (seven write tools) | Mode permitting | Mode permitting | **Never** |

Statistics metrics withheld from customers: `by_assignee`, `by_label` and
`visibility_split`. The five they may ask for are `totals`, `by_status`, `by_priority`,
`created_by_week` and `completed_by_week`.

> [!IMPORTANT]
> A tool a person may not use is **not sent to the model at all**, rather than sent and
> then refused. A model with no write tool cannot produce a proposal, so there is no
> preview, no confirm button and nothing for a crafted request to execute. An unrecognised
> tool name and a tool that exists but is withheld produce the *same* refusal text, so a
> customer cannot tell the two apart.

## Permission matrix — reporting and administration screens

| Screen | Route | Gate | Administrator | Team | Customer |
|---|---|---|---|---|---|
| Dashboard | `/dashboard` | auth | Yes | Yes | Yes |
| Board index | `/boards` | auth | Yes | Yes | Yes |
| Team statistics | `/stats` | `role:admin,team` | Yes | Yes | **403** |
| Team statistics CSV | `/stats/export` | `role:admin,team` | Yes | Yes | **403** |
| Customer statistics | `/stats/customer` | auth | Yes | Yes | **Yes** |
| Customer statistics CSV | `/stats/customer/export` | auth | Yes | Yes | **Yes** |
| Workspace activity | `/activity` | `role:admin,team` | Yes | Yes | **403** |
| Settings directory | `/settings` | auth | Yes | Yes | Yes |
| Profile | `/settings/profile` | auth | Yes | Yes | Yes |
| Global AI settings | `/admin/ai` | `role:admin` + confirm | Yes | **403** | **403** |
| User administration | `/admin/users` | `role:admin` + confirm | Yes | **403** | **403** |
| AI block CSV export | `/ai/messages/{id}/blocks/{n}/export.csv` | `role:admin,team` | Yes | Yes | **403** |
| Voice endpoints | `/ai/voice/*` | auth + throttle | Yes | Yes | **Yes** |

> [!NOTE]
> The customer statistics screen is open to **everyone, staff included**, and that is
> deliberate: it is the only way for a team member to see exactly what a customer sees. It
> is a separate Livewire component backed by a separate service (`CustomerStatistics`)
> which has no method that could return an internal figure — so there is no branch on the
> page that a careless edit could invert.

## Coarse gates

Three named gates are registered for navigation and route groups. Record-level decisions
always go through a policy and never through these.

| Gate | Resolves to | Used for |
|---|---|---|
| `administer-workspace` | `canAdministerWorkspace()` — admin only | Sidebar links, admin route groups |
| `view-internal-content` | `canSeeInternalContent()` — staff only | Hiding internal UI affordances |
| `administer-ai` | `canAdministerWorkspace()` — admin only | The global AI settings screen |

`administer-ai` is defined separately from `administer-workspace` even though the two
currently answer identically, because they are different questions — *"may this person
manage the workspace?"* and *"may this person hold the provider credentials and decide how
far the AI is trusted?"* — and the second is the one a deployment is most likely to want to
narrow later.

<!-- pagebreak -->
