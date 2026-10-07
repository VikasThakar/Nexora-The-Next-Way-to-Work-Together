# User Workflows

Six end-to-end walkthroughs, each describing what the implementation actually does.

## Workflow 1 — Standing up a new customer project

**Actor:** Administrator

```diagram
 1. /admin/users → Create user            Customer contact, role = customer
                                          (password confirmation required)
 2. /boards/create → "NutriLens"          slug nutrilens, prefix NL, auto-derived
                                          5 default columns created
 3. /boards/nutrilens/edit → Members      Add the delivery team, then the customer
 4. /boards/nutrilens/settings            Rename columns, create labels
 5. /boards/nutrilens/ai/settings         Attach acme/nutrilens as the primary repository
                                          Write the project context
                                          Leave automatic runs OFF
 6. /boards/nutrilens/integrations        Paste the Slack webhook URL (write-only)
                                          Choose events; add the on-call rota
 7. GitHub → Settings → Webhooks          URL, secret, JSON, five event types
 8. /boards/nutrilens/docs                Write the first pages; publish the ones
                                          the customer should read
```

**What has happened underneath:** a `board_members` row per person (the entire access grant);
`BoardCreated` and `BoardMemberAdded` events in the activity feed; an encrypted Slack webhook
URL in `boards.settings.slack`; a `board_repositories` row that is now the **security boundary**
for inbound GitHub deliveries.

## Workflow 2 — A customer request, from filing to delivery

**Actors:** Customer, then Team member

```diagram
  CUSTOMER                                      SYSTEM
  --------                                      ------
  New ticket:                            CreateTicket enforces:
   "Login fails on Safari"                 customer_visible = TRUE   (forced)
   priority: critical                      column = board's first    (forced)
                                           assignee = none           (forced)
        |                                        |
        |                                  ticket NL-42 (number under a row lock)
        |                                  ticket_created event
        |                                        |
        |                                  TicketObserver fans out:
        |                                    broadcast → customer + internal channels
        |                                    notify   → team members, policy-checked
        |                                    Slack    → "customer raised" (if enabled)
        |                                    SMS      → on-call, because priority=critical
        |                                               (unique index prevents duplicates)
        |
  TEAM MEMBER
  -----------
  Drags NL-42 to In Progress             TicketPolicy::move  (staff only)
  Assigns it to themselves               TicketPolicy::assign
  Internal note:                         PostComment → stream = internal
   "Reproduced — Safari 17 only"           Customer cannot see, count or detect it
  Customer reply:                        PostComment → stream = customer
   "Confirmed, fixing today"               Customer is notified
        |
  git checkout -b NL-42-safari-login
  git push                               → Workflow 3
        |
  Drags NL-42 to Done                    Column is_done = true
                                         → Slack "moved to done" (if enabled)
  CUSTOMER SEES
  -------------
  Their ticket, its status and priority, their conversation, the due date.
  NOT: the internal note, the branch, the commits, the pull request, the CI result.
```

## Workflow 3 — GitHub activity reaching a ticket

**Actor:** Developer

```diagram
  git checkout -b NL-42-safari-login
  git commit -m "NL-42 fix cookie SameSite on Safari"
  git push
        |
        v
  GitHub sends `push` with X-Hub-Signature-256
        |
        v
  POST /webhooks/github
   1. throttle:github-webhooks                            429 if exceeded
   2. HMAC-SHA256 over the RAW body, hash_equals          401 if wrong
   3. INSERT webhook_deliveries (unique = the claim)      200 if already seen
   4. dispatch ProcessGitHubWebhookJob → queue `default`
   5. 202 Accepted                                        (well under GitHub's 10s)
        |
        v  worker
  WebhookProcessor
   repository "acme/nutrilens" → board_repositories → boards in scope
       └─ no attachment anywhere? → 0 links, recorded as ignored   ◄── the boundary
   extract keys from the branch name AND every commit message
   "NL-42" → prefix NL + number 42, resolved ON THOSE BOARDS ONLY
   upsert github_links (ticket, repository, type, external_id)
   write a ticket event ONLY if something actually changed
        |
        v
  NL-42 timeline: branch created, commit pushed
  Later: pull request opened → state, CI status, author, merged_at
```

## Workflow 4 — Asking the assistant, and confirming a change

**Actor:** Team member. Workspace at **AI Operator**.

```diagram
  "Which tickets on NutriLens are overdue, and raise one to chase the worst?"
        |
        v
  AiSessionManager resolves the session — owner, scope, budget, chat mode
  AiConfigurationResolver → provider, model, capability mode (board over workspace)
  AiCapabilityGuard → Operator: proposals allowed, unattended work NOT
  Chat mode = Everything → read tools AND write tools
        |
        v
  Context built AS THIS PERSON (their boards, their visible tickets)
        |
        v
  Round 1: model calls search_tickets {board: nutrilens, overdue: true}
             → Ticket::visibleTo(THIS USER) → 3 rows → audit row written
  Round 2: model calls get_ticket {ticket: NL-31}
             → TicketFinder with THIS USER as viewer → audit row written
        |
        v
  Answer: prose + a table of the three overdue tickets
          + ONE proposal: propose_create_ticket
            { title: "Chase NL-31 …", board: nutrilens, priority: high }
        |
        v
  Screen shows a PREVIEW and a Confirm button          ← Operator: nothing written yet
        |
  Person presses Confirm
        v
  ExecuteChatAction
    • re-resolve the board through BoardAccess (the confirming person's membership)
    • AiCapabilityGuard::assertCanExecuteProposal   ← re-checked, in case the mode
                                                      was tightened since the preview
    • Gate: TicketPolicy::create, against the CONFIRMING person
    • call CreateTicket — the ordinary action, with all its own rules
        v
  NL-57 exists. Internal by default. ticket_created event. Observer fans out.
```

Under **AI Agent** the preview step is skipped for this reversible action and the ticket is
created immediately — **but every check above still runs, identically.** Under **AI Observer**
no write tool is sent at all, so no proposal could exist.

A **customer** asking the same question gets the three overdue tickets **they can see** and no
proposal, because `allowsProposals()` refuses a customer in every mode.

## Workflow 5 — An AI coding session

**Actor:** Team member. Workspace at **AI Agent**, `AI_CODE_DRIVER=claude_code`.

```diagram
  Ticket NL-42 → "Run AI" → Apply mode
        |
  AiRunPolicy::create (staff, board, apply → staff)
  AiCapabilityGuard::allowsTicketRun (apply needs Agent)
  AiRunCap (manual cap; admins may exceed if configured)
        |
        v
  ai_runs row: status=queued, mode=apply, uuid
  ticket event: ai_run_queued
  ExecuteAiRunJob → queue `ai`, worker service
        |
        v  claim(): queued → running, conditional UPDATE, affected rows checked
  preparing   coding runtime available?       no → FAIL, note says exactly what is missing
  preparing   clone into storage/app/ai-runs/<uuid>, depth 1
  preparing   base branch protected?          yes → FAIL
  preparing   create branch ai/NL-42-<uuid>
  coding      Claude Code CLI edits the checkout
  coding      re-derive the diff from `git status`  ← not from what it claimed
              empty diff?                     yes → FAIL, no PR opened
  testing     run AI_VALIDATION_COMMANDS
              non-zero exit?                  yes → FAIL, nothing pushed
  pull_request commit as "Workspace AI"
  pull_request push refs/heads/ai/NL-42-<uuid>   ← the NEW branch, never the base
  pull_request POST /repos/acme/nutrilens/pulls  draft: true
  finished    internal note with the PR link
              ai_usage_records row: tokens, cost
              ticket event: ai_run_completed
              Slack if the board subscribes (off by default)
        |
  finally     the isolated directory is deleted, however the run ended
```

**The customer sees none of this.** Not the run, not its status, not its cost, not its
failure — `AiRunPolicy` denies as 404.

## Workflow 6 — Publishing documentation to a customer

**Actor:** Team member

```diagram
  /boards/nutrilens/docs → New page "Release process"
        |
  CreatePage → customer_visible = FALSE     ← always internal at creation
        |
  Author types. Page is internal, so autosave writes body_md directly
  through UpdatePage. No draft, no Publish step, no audience to protect.
        |
  Ready to share → "Publish"
        |
  SetPageVisibility
    • is any ancestor internal?   yes → REFUSED
    │   (exposing the parent is the author's decision, not a side effect)
    • customer_visible = TRUE
    • recorded in the activity feed
        |
        v
  Now the page is customer-visible, so the editor's behaviour CHANGES:
  autosave writes draft_md via SaveDraft and stays there.
  Nothing a customer is reading changes between keystrokes.
        |
  "Publish" again → draft_md moves into body_md, edit recorded,
                    other clients told, draft discarded
        |
  Later, retracting it:
  SetPageVisibility → customer_visible = FALSE
    • cascades DOWN the whole subtree
    • retracting always fails closed: more is hidden, never less
```

<!-- pagebreak -->
