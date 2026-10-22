# Administrator Guide

*Written for the person who runs the Nexora deployment.*

## Your responsibilities

| Area | What only you can do |
|---|---|
| Users | Create, edit, deactivate, change roles |
| Boards | Create, rename, delete, manage membership |
| AI credentials | Hold the provider API keys |
| AI trust level | Set the workspace capability mode |
| Spending | Set token limits and run caps |

Everything else — tabs, columns, labels, documentation, board AI settings, integrations — is
the delivery team's own work on their own boards.

## Managing users

`/admin/users` — requires re-entering your password.

| Task | Notes |
|---|---|
| Create | Name, email, role, initial password. Production passwords need 12+ characters, mixed case, and are checked against known breaches |
| Change a role | A separate gate from editing, because it is the privilege-escalation path |
| Deactivate | Sets `deactivated_at`. **Takes effect on the person's very next request**, not at their next login |
| Delete | Preserves their history — tickets, comments and pages keep existing with a null creator |

> [!IMPORTANT]
> You **cannot change your own role** and **cannot delete your own account**. Both are
> explicitly forbidden, so the workspace cannot be left with no administrator.

## Managing boards

`/boards/create` and `/boards/{board}/edit` — both require re-entering your password.

The slug and ticket prefix are derived automatically and are globally unique. New boards get
one tab, **Main**, with the standard five columns; the team can split the board into more tabs
from the "+" on its tab strip. Add members from the board edit screen; **membership is the
access grant**, and there is nothing else to configure for access.

Adding a **customer** to a board lets them see that board's customer-visible tickets and
published documentation, raise tickets, take part in the customer conversation, and use a
read-only assistant. It grants nothing internal, ever.

## Global AI settings

`/admin/ai` — `role:admin` plus password confirmation, and the component re-authorizes on
mount, on every action and on every render.

| Setting | What it does |
|---|---|
| Enabled | Master switch for the workspace |
| Provider | Anthropic or OpenAI |
| Default model | Inherited by every board that does not override it |
| **Capability mode** | AI Observer / AI Operator / AI Agent — the trust ceiling |
| Session token limit | Per conversation |
| Daily user token limit | Per person per day |
| Credentials | Anthropic and OpenAI keys |

### Choosing a capability mode

| Choose | When |
|---|---|
| **AI Observer** | You want answers and nothing else. No writes at all — not even an internal note |
| **AI Operator** | You want the AI to draft changes that a person confirms, and to run manual analyses |
| **AI Agent** | You want unattended automation: automatic runs, apply-mode pull requests, and reversible changes carried out as soon as somebody asks |

Raising the mode **grants no user a capability they did not already have by hand**. It raises
the ceiling on what the AI may attempt; every write is still authorized against the person
who confirmed it.

> [!NOTE]
> Credential fields are **write-only**. They start blank and are blanked again after every
> save. You can replace a key — which is recorded in `rotated_at` and `updated_by_id` — but
> nobody, including you, can read one back out through the browser.
>
> There is deliberately **no "test key" button**. It would spend money on every press and be
> pressable by anybody who reaches the page; the first real question already tells you whether
> the credential works, because the provider's own refusal is reported verbatim.

## Monitoring

| Question | Where |
|---|---|
| Is the deployment healthy? | `GET /health` |
| What has been happening? | `/activity` |
| How much is the AI costing? | `/stats` — the AI section |
| Did a webhook arrive? | `webhook_deliveries` |
| Was the on-call rota reached? | `sms_messages` |
| What did the AI look up? | `ai_tool_invocations` |
| What failed? | `failed_jobs`, and the application log |

## Routine maintenance

| Task | Frequency | How |
|---|---|---|
| Review users and deactivate leavers | Monthly | `/admin/users` |
| Review AI spend | Monthly | `/stats` |
| Check failed jobs | Weekly | `failed_jobs` |
| Rotate provider keys | Per your policy | `/admin/ai` — replace, never read |
| Verify the scheduler is running | After each deploy | Check that `workspace:prune` ran |

<!-- pagebreak -->

# Team Member Guide

*Written for the delivery team.*

## What you can do

You see every board you have been **added to**. Ask an administrator to add you; there is no
way to join one yourself.

| Area | Your permissions |
|---|---|
| Boards | Open the ones you are a member of |
| Columns and labels | Create, rename, reorder, delete — on your boards |
| Tickets | Full control: create, edit, move, assign, label, link, delete |
| Visibility | **You decide what the customer sees** |
| Conversations | Both streams — customer and internal |
| Documentation | Write, organise, publish |
| AI assistant | Full access, including write tools (mode permitting) |
| AI runs | Start suggest and apply runs |
| Board AI settings | Model, project context, auto-run policy, repositories |
| Integrations | Slack channel and SMS rota for your boards |
| Statistics and activity | Full team view |
| Board record | **Not yours** — name, prefix, membership and deletion are administrator-only |

## The one thing to get right

> [!IMPORTANT]
> **A ticket you raise is visible to the board's customers by default; comments and pages are
> not.** On the New ticket form and in quick add, "Visible to the customer" starts ticked —
> **untick it for internal work** (the form says how many customers would see the ticket). The
> assistant follows the same default and makes a ticket internal when you ask it to.
>
> A comment you write and a documentation page you add are internal until somebody deliberately
> publishes them. Publishing an existing ticket or page is staff-only and recorded in the
> timeline. A board can keep new tickets internal by default through its
> `new_tickets_visible_to_customers` setting.

The reverse also holds: a ticket a **customer** raises is always customer-visible, always
lands in the first column of its tab, and is never pre-assigned.

## Splitting a board into tabs

A board can hold several areas of work — "Admin dashboard", "Public website", "Backend API" —
as **tabs**, each with its own columns and tickets, like tabs in a browser. Click a tab in the
strip above the columns to switch; your filters stay on, and each tab shows how many tickets
match them.

1. **Add a tab** with "+": name it, and it starts with the same columns as the tab you are on.
   Change its columns from the tab menu (⋯) → *Edit columns*.
2. **Rename, move left/right, delete** from the tab menu, or drag tabs along the strip. The
   leftmost tab is where the board opens and where tickets go when nobody picks a tab.
3. **Move a ticket to another tab** by dragging its card onto that tab, or with the **Tab**
   choice on the ticket page. It lands in the column with the same name — or a done column if
   it was done, or else the first column.
4. **Delete a tab** and its tickets move to the tab you choose — the panel shows where each
   column's tickets will go before anything happens. Tickets are never deleted with a tab.

A link to a board can name a tab (`…/boards/gritch?tab=backend-api`); without one, the board
opens on the tab you last had open there.

## Working a ticket

1. Pick it up from the board; drag it into *In Progress*.
2. Assign it to yourself.
3. Write your working notes in the **internal** stream — the customer cannot see them, cannot
   count them and cannot tell they exist.
4. Reply to the customer in the **customer** stream.
5. Create a branch named after the ticket key — `NL-42-fix-login`. The branch, its commits and
   its pull request appear on the ticket automatically.
6. Move it to *Done*; if the board subscribes, Slack is told.

## Using the assistant

The panel is on every page. Choose what the conversation is about — one board, or the whole
workspace — and what it is for:

| Mode | Use it for |
|---|---|
| **Reading** | "What is overdue?", "What changed on this board this week?", "What does the deployment runbook say?" |
| **Writing** | "Raise a ticket for the Safari login bug, high priority, assign it to me" |
| **Everything** | "What is missing from this board — and make tickets for it" |

Tick **Outside Project** when you want general technical knowledge alongside the project. It
does not widen what you can see by a single row.

You can attach files — specifications, CSVs, screenshots, recordings — and speak your
question instead of typing it.

When the assistant proposes a change you get a preview and a Confirm button. Under **AI
Agent**, reversible changes happen immediately because you asked for them; **deleting a
ticket always requires typing its key by hand.**

> [!NOTE]
> The assistant can only ever do what **you** could do by hand. If you cannot assign tickets,
> confirming a proposal will not assign one — and it will tell you why rather than partly
> succeeding.

## Running the AI on a ticket

| Mode | What you get |
|---|---|
| **Suggest** | An internal note with the AI's analysis of the ticket and the repository |
| **Apply** | An isolated clone, a new branch, your configured tests run, and a **draft** pull request for you to review |

Apply mode **never merges, never pushes to a protected branch, and never opens a pull request
for work that did not happen.** If validation fails, the run fails and nothing is pushed.

## Documentation

Open a page and start typing — there is no Edit button. Where your work is saved depends on
the audience:

- an **internal** page saves as you type;
- a **published** page saves to a draft and stays there until you press **Publish**, because a
  page a customer is reading should change when you say so.

You cannot publish a page whose parent is internal — publish the parent first, deliberately.
Making a page internal hides its whole subtree.

<!-- pagebreak -->

# Customer Guide

*Written for a customer stakeholder. No technical knowledge assumed.*

## What Nexora is, for you

Nexora is where you can see the work your delivery team is doing for you, raise new requests,
and talk to the team about them — without waiting for a status meeting or an emailed report.

## Signing in

Your delivery team creates your account and adds you to your project. Sign in with your email
address and password; use **Forgot password** if you need to reset it. You can change your
name, email, password and light/dark appearance under **Settings → Profile**.

## Your dashboard

After signing in you see the projects you have been added to. Opening one shows its board: a
set of columns, with a card for each piece of work, arranged left to right roughly in the
order work flows. A large project may be split into **tabs** above the columns — one per area
of work; click a tab to see its columns and the tickets in it that are shared with you.

## Raising a request

1. Open your project (and the tab the request belongs in, if it has tabs).
2. Choose **New ticket**.
3. Give it a clear title, describe what you need, set a priority, and attach any screenshots.
   If the project has tabs, the form starts in the tab you were on; you can change it.
4. Save.

Your ticket is **immediately visible to you and to the team**, appears at the start of their
workflow, and is left unassigned so the team can decide who picks it up. They are notified
straight away — and for a **critical** request, the on-call engineer can be texted.

You can keep editing a ticket you raised: its title, description, priority, type and due
date. Who is working on it and where it sits in the workflow are the team's decisions.

## Following progress

Open any ticket to see its status, priority, labels, due date, the conversation, and its
history.

## Talking to the team

Each ticket has a conversation you take part in. Write a comment, attach a file, and mention a
colleague with `@`. The team is notified.

> [!NOTE]
> **You will not see everything on a ticket, and that is by design.** The delivery team keeps
> separate working notes — technical detail, internal discussion, engineering activity — that
> are not shown to you. This is not information being withheld from you dishonestly; it is the
> team being able to think out loud without every half-formed thought becoming a client
> communication. What is meant for you is in your conversation.

## Documentation

Your project may include documentation the team has **published** to you: guides, processes,
release notes, technical references. Pages are organised as a tree; there is a contents list
for each page. Documentation is written by the team — you can read it, but not change it.

Some pages are internal and you will not see them. There is no indication that they exist.

## Searching

Press **Ctrl + K** (**Cmd + K** on a Mac) anywhere to search your tickets, documentation and
projects. Results only ever include things you can already open.

## Your progress summary

**Statistics** gives you a plain summary of your own project: how many requests are open and
closed, how they break down by priority, how many were raised over recent weeks, and recent
activity. You can download the figures as a spreadsheet.

## Asking the assistant

Nexora includes an AI assistant, available from any page. Ask it things like:

- *"What is the status of my open requests?"*
- *"Which of my tickets are highest priority?"*
- *"What does the documentation say about the release process?"*
- *"Show me how many requests I have raised each week this quarter."*

It can produce charts and tables from your own figures, and read them aloud. You can attach a
file — a specification, a spreadsheet, a screenshot — and ask questions about it.

> [!IMPORTANT]
> **Your assistant is read-only, and it can only see what you can see.**
>
> - It **cannot change anything** — no tickets, no comments, no documentation. Not in any
>   configuration, ever.
> - It **cannot see internal work**: internal tickets, the team's private notes, internal
>   documentation, engineering activity or repository information are all invisible to it,
>   exactly as they are to you.
> - Its numbers count **only the work you can open**, so a chart it draws for you is correct
>   for your view.
> - Your conversation is **private to you**. Nobody else — including administrators — can read
>   it, and you cannot read anybody else's.

## What you cannot do

For completeness: you cannot move tickets between columns or tabs, assign work, add or change labels,
link tickets together, delete tickets, edit somebody else's ticket, write documentation, see
internal notes, or see the team's engineering activity. Those are the delivery team's
workflow.

<!-- pagebreak -->
