# Workspace AI

This is Nexora's largest subsystem: roughly a third of the application's classes. It is
worth reading the three orthogonal settings first, because almost every question about AI
behaviour resolves to one of them.

## Three settings that are not the same question

| Setting | Enum | Who chooses it | What it decides |
|---|---|---|---|
| **Capability mode** | `AiCapabilityMode` | An administrator (workspace) or the team (board) | The **ceiling** on what the AI may ever *attempt* here |
| **Chat mode** | `AiChatMode` | The person asking, per conversation | What this conversation is *for* — reading, writing, or both |
| **Knowledge scope** | `AiKnowledgeScope` | The person asking, per conversation | Where an answer may be *sourced* from |

They are not substitutes, and **none of them can grant what another refuses**.

### Capability mode — the security dial

Three cumulative levels, expressed as ordered levels rather than a matrix of switches
because a matrix reads well on a settings screen and is impossible to reason about in a
security review.

| Mode | Badge | May read and answer | May propose writes | May run a ticket analysis | May write code / open a PR | May run unattended |
|---|---|---|---|---|---|---|
| **AI Observer** | slate | Yes | **No** | **No** | No | No |
| **AI Operator** | brand | Yes | Yes — confirmed by a person | Suggest runs | No | No |
| **AI Agent** | amber | Yes | Yes | Suggest and apply | Yes | Yes |

> [!NOTE]
> Observer refuses ticket runs entirely, and that is stricter than people expect **on
> purpose**: a suggest run ends by posting an internal note, and a note is a write.

The shipped default is `agent` (`AI_DEFAULT_MODE`), which is a compatibility decision: it is
exactly the set of capabilities the product had before modes existed. The database column
default for a new `ai_settings` row is `observer`. A fresh deployment wanting the cautious
posture should set `AI_DEFAULT_MODE=operator` or `=observer`.

> [!IMPORTANT]
> **The capability mode is a ceiling, never a floor.** Every method on
> `AiCapabilityGuard` can only ever *deny*. None of them grants anything, none is consulted
> instead of a policy, and raising a workspace to AI Agent gives no user a capability they
> did not already have by hand. Order of checks on any write:
>
> 1. May this person use the assistant at all? — route group, `BoardPolicy`, SQL scopes.
> 2. Does the mode permit the AI to attempt this? — `AiCapabilityGuard`.
> 3. Is **this person** allowed to make **this** change? — `TicketPolicy`, `DocPagePolicy`,
>    `AiRunPolicy`, applied against the confirming user by the ordinary action.

### Chat mode — what the conversation is for

| Mode | Read tools offered | Write tools offered | Default model |
|---|---|---|---|
| **Reading** *(default)* | Yes | **No** | `claude-sonnet-5` |
| **Writing** | **No** | Yes | `claude-opus-5` |
| **Everything** | Yes | Yes | `claude-opus-5` |

Reading is the default, so the setting somebody gets by not choosing is the one that changes
nothing. Writing withholds the lookups so that "write me a ticket for the login bug" does
not become four searches and a summary before it gets to the draft — the board context block
still goes in on every turn, so the model is never asked to draft blind.

Each mode names its own model because the two jobs have different shapes: lookup-and-
summarise is bounded and mostly retrieval, whereas drafting a ticket somebody will act on is
the turn worth spending on. The mapping is deployment configuration
(`AI_CHAT_READING_MODEL`, `AI_CHAT_WRITING_MODEL`, `AI_CHAT_EVERYTHING_MODEL`), and the
chosen id is still resolved against the effective provider.

### Knowledge scope — "Outside Project"

| Scope | Behaviour |
|---|---|
| **Project only** *(default, and not configurable away)* | Answers only from this project and what the asker can see in it |
| **Outside Project** | Answers from this project **and** from general knowledge outside it |

Outside is **additive, never a replacement**. The board context still goes in, the read
tools are still offered, and a question about NL-5 is still answered from NL-5 — because
"compare our architecture with a typical Laravel SaaS" needs both halves at once.

Project is the default for two reasons, and the second is the one that matters: the failure
modes are asymmetric. A project-only assistant asked "what is Laravel?" says it cannot
answer that here, which is a mild annoyance with an obvious remedy. An assistant that has
quietly reached outside says something confident about somebody's project that is not true
of their project, with no signal that it happened.

> [!IMPORTANT]
> Knowledge scope has **nothing to do with authorization**. Switching it does not widen what
> a person may read by a single row and does not permit a change they could not otherwise
> make. It is enforced by the **data layer** — `AiToolRegistry` decides which tools exist for
> a turn — not by the prompt.
>
> One honest limit is written down in the code: a language model's own trained knowledge is
> not a data source this application can withhold. Under Project the assistant is
> *instructed* to decline general questions, and that instruction is a prompt rather than a
> wall. What the data layer does guarantee is narrower and stronger: **no external service is
> contacted, and no project data is widened either way.**

## AI architecture

```diagram
   PERSON
     |  types or speaks a question
     v
  +----------------------------------------------------------------+
  |  SURFACE                                                        |
  |    Livewire\Ai\Assistant   the persistent panel, everyone       |
  |    Livewire\Ai\Chat        full-page board chat, staff only     |
  |    AiVoiceController       speech in / speech out               |
  +----------------------------------------------------------------+
     |
     v
  +----------------------------------------------------------------+
  |  PERMISSION AND CONTEXT LAYER                                    |
  |                                                                  |
  |   AiSessionManager      whose conversation, which scope,        |
  |                         token budget, chat mode                 |
  |   AiConfigurationResolver   config/ai.php -> ai_settings        |
  |                                          -> boards.settings.ai  |
  |   AiCapabilityGuard     the ceiling: may this be attempted?     |
  |   BoardAccess           may this person see this board?         |
  |   AssistantContextBuilder / BoardContextBuilder /               |
  |   WorkspaceContextBuilder   context built AS THE ASKER          |
  |   AiAttachmentContext   uploaded files, full once then digest   |
  +----------------------------------------------------------------+
     |
     v
  +----------------------------------------------------------------+
  |  PROVIDER LAYER                                                  |
  |   AiProviderRegistry -> ClaudeService  (anthropic-ai/sdk)       |
  |                      -> OpenAiService  (HTTP)                   |
  |   AiCredentialVault  board key -> workspace key -> environment  |
  +----------------------------------------------------------------+
     |
     v   model asks for a lookup
  +----------------------------------------------------------------+
  |  TOOL LOOP     AiToolRunner, bounded: 4 rounds, 6 calls/round,  |
  |                60,000 characters total                          |
  |                                                                  |
  |   AiToolRegistry -> availableTo(context) -> schema validation   |
  |                  -> tool runs WITH THE ASKER AS THE VIEWER      |
  |                  -> one audit row in ai_tool_invocations        |
  +----------------------------------------------------------------+
     |
     v
  +----------------------------------------------------------------+
  |  READ LAYER      TicketFinder . DocPageFinder . ActivityReader  |
  |                  GithubLinkReader . TeamStatistics              |
  |                  CustomerStatistics . FlowMetrics               |
  |                  every one applies the viewer's own scope       |
  +----------------------------------------------------------------+
     |
     v
             NEXORA DATABASE  (scoped SQL, never raw)
     |
     v
  +----------------------------------------------------------------+
  |  ANSWER          RichResponseParser -> prose / table / chart /  |
  |                  KPI blocks, plus AT MOST ONE proposal (a batch |
  |                  of new tickets counts as one)                  |
  |                                                                  |
  |  A proposal is a PREVIEW. It becomes a change only when         |
  |  ExecuteChatAction re-resolves it, authorizes it against the    |
  |  confirming person, and calls the ORDINARY action.              |
  +----------------------------------------------------------------+
```

<!-- pagebreak -->

## The two surfaces

| Surface | Route | Who | Notes |
|---|---|---|---|
| **Assistant panel** | Mounted in the layout | Everyone with at least one reachable board, **customers included** | Persists across `wire:navigate`. Costs nothing until opened — `revealed` starts false and `render()` returns an empty shell |
| **Board AI chat** | `/boards/{board}/ai` | **Staff members of the board only** — 404 otherwise | Full page. Quotes internal tickets, internal notes and internal documentation |

The two exist as **two abilities rather than one loosened ability**
(`BoardPolicy::useAiChat` and `::useAssistant`), because the surfaces differ in what they
are allowed to contain, and a single relaxed check would have quietly opened the staff page
as well.

The panel **never aborts**. Where `Chat` 404s on an authorization failure — right for a page
a customer must not know exists — doing that in a layout component would take down whatever
page it happened to be rendered on. So every failure there narrows the panel to its board
picker instead. The security outcome is the same with no collateral damage.

## Context scope

A conversation is about one of two things (`App\Services\AI\AiContextScope`):

| Scope | Context assembled |
|---|---|
| **One board** | Up to 60 tickets, 40 documentation pages, 40 activity events — all read through the asker's own visibility |
| **All workspace** | Up to 12 boards, 6 tickets each — **titles only**, no descriptions, no documentation, no history |

The workspace scope is much thinner per board on purpose: breadth multiplied by the depth of
a single-board context would spend the whole window on material the question probably does
not need.

The scope is held in the session as a **slug**, never a primary key, and is re-resolved
through `BoardAccess` on every render. An unauthorized slug produces the same panel as no
selection — never another board's conversation, and never an error that confirms the board
exists.

Changing scope drops the remembered session, because a session belongs to one scope. The
chat mode is **not** cleared with it: it is what the person wants the assistant to be doing,
which does not change because they changed the subject.

## Sessions and conversation history

`ai_sessions` — one row per conversation.

| Column | Purpose |
|---|---|
| `uuid` | Public identifier; primary keys are never exposed |
| `user_id`, `board_id` | Owner and scope. A null `board_id` is a workspace conversation |
| `provider`, `model` | What this conversation is actually running on |
| `capability_mode`, `chat_mode`, `knowledge_scope` | The three settings, as they stood |
| `tokens_input`, `tokens_output`, `estimated_cost` | Metering |
| `message_count`, `started_at`, `last_activity_at`, `ended_at` | Lifecycle |

> [!IMPORTANT]
> **A conversation belongs to one person — an administrator included.** There is no reading
> right over a colleague's thread, and therefore none over the documents in it.
> `AiSessionPolicy` checks, in this order: the account is active; **ownership**; the board is
> still reachable. Every denial is a 404.
>
> Sessions replaced a single per-person-per-board thread for one reason: context. A
> month-old thread re-sends a month of turns to answer a question about today, which is
> slower, dearer and worse.

**One conversation on screen per person per context.** The panel and the full-page chat show
the person's current session for the selected board (or the workspace). **New conversation**
— the **+** in the panel header, a button beside *Clear conversation* on the full page — calls
`startNewSession()`: the current session is *ended*, never deleted, and the next question
starts with an empty context. It is disabled on a session nothing has been asked in
(`AiSession::isFresh()`), which already is a fresh one. From 80% of the session token limit
(`AiSession::isNearingTokenLimit()`) a note above the composer suggests starting a new one,
before the ceiling refuses a question; at the ceiling the refusal offers the same remedy.
Ended sessions are kept for metering and audit but are not listed anywhere in the interface.

Transcripts persist in `ai_chat_messages`; **prompts do not**. The system prompt and the
context are rebuilt on every request and never stored, so editing a board's project context
changes the next answer instead of being frozen into an old row.

| Limit | Default | Variable |
|---|---|---|
| Tokens per session | 400,000 | `AI_SESSION_TOKEN_LIMIT` |
| Tokens per user per day | 2,000,000 | `AI_DAILY_USER_TOKEN_LIMIT` |
| Sessions returned by `AiSessionManager::history()` | 25 | `AI_SESSION_HISTORY` |
| Turns replayed as history | 20 | `AI_CHAT_HISTORY` |
| Output tokens per answer | 4,000 | `AI_CHAT_MAX_OUTPUT_TOKENS` |
| Output tokens, Outside Project | 8,000 | `AI_CHAT_MAX_OUTPUT_TOKENS_OUTSIDE` |

Zero means unlimited, and is written as zero rather than null so the settings form has one
shape.

## The retrieval tool loop

The context block is a roll-up — recent tickets, recent activity, documentation titles. It
makes a general question answerable cheaply, and it is **not enough for a specific one**:
"which tickets are overdue" cannot be answered from the sixty most recently updated.

So the model is also given read tools, and `AiToolRunner` runs a bounded loop.

| Bound | Default | Variable |
|---|---|---|
| Rounds before the model must answer | 4 | `AI_TOOLS_MAX_ROUNDS` |
| Lookups in one round | 6 | `AI_TOOLS_MAX_CALLS` |
| Total characters of tool material per question | 60,000 | `AI_TOOLS_MAX_CHARACTERS` |
| Master switch | on | `AI_TOOLS_ENABLED` |

> [!NOTE]
> These are **cost and latency controls, not security controls**. Security is the readers
> each tool calls with the asking person as the viewer, plus `availableTo()` on each tool.
> Turning `AI_TOOLS_ENABLED=false` narrows what the assistant can answer and grants nobody
> anything.

Every invocation goes through `AiToolRegistry::invoke()`, which makes four promises
structural rather than aspirational:

| Promise | How |
|---|---|
| **Schema** | The tool declares one; `AiToolInput` enforces it *before* the tool is entered |
| **Authorization** | A tool is offered only if `availableTo()` says so, and its reads are scoped to the viewer on the context |
| **Validation** | A call that does not fit is answered with prose the model can act on, not an exception |
| **Audit** | **One row in `ai_tool_invocations` per call, whatever the outcome** — a refused call is the interesting one to have a record of |

A tool that throws is contained: the exception is logged in full and reported to the model as
"that lookup failed", with nothing about why — an exception message can carry a query, a
path or a column name, and none of that belongs in a conversation.

**The loop cannot widen what a person may see.** A tool argument names a *subject*; the
viewer comes from the *request*. An assistant with tools answers more questions than one
without, about exactly the same material.

### The eleven tools

| Tool | What it reads | Availability |
|---|---|---|
| `get_board` | Description, columns with counts, labels, prefix. With no argument, lists reachable boards | Everyone |
| `get_ticket` | One ticket in full, plus recent conversation and history | Everyone — scoped |
| `search_tickets` | By text, column, label, priority, assignee, due date, age | Everyone — scoped |
| `get_completed_tickets` | Tickets completed — moved into a done column — in a named period or date range, from the history; flags tickets reopened since | Everyone — scoped |
| `search_documentation` | Page titles and bodies, with extracts | Everyone — scoped |
| `get_documentation_page` | One page in full by slug | Everyone — scoped |
| `get_statistics` | Eight aggregate metrics | Everyone; three metrics staff-only |
| `get_activity` | Workspace activity history | **Staff only** |
| `get_code_activity` | Branches, commits, pull requests, CI state | **Staff only** |
| `get_github_repository` | Attached repositories, default branch, build commands — **never credentials, never repository contents** | **Staff only** |
| `search_external_knowledge` | An outside service | Outside Project scope **and** a configured driver |

### The same tools, from Claude or ChatGPT (remote MCP)

People can add `https://<host>/mcp` to Claude (web, desktop, phone) or ChatGPT as a custom
connector, sign in with their Nexora account, and ask questions there. Those clients call the
**same read tools through the same registry** (`App\Mcp\Tools\RegistryTool` wraps each
contract and calls `AiToolRegistry::invoke()`), so an answer from Claude cannot differ from
what the in-app assistant would have read for the same person.

`App\Mcp\Servers\NexoraServer` builds the tool context once per request from the OAuth token's
user:

| Context field | MCP value | Why |
|---|---|---|
| `scope` | workspace | An external client has no "current board"; tools take a slug or key |
| `session` | `null` | Not part of an in-app conversation; the audit row records the call without one |
| `mode` | Observer | Only governs write proposals, and none are offered |
| `staff` | `BoardAccess::canSeeInternalContent($user)` | The customer boundary, exactly as in the app |
| `knowledge` | Project | `search_external_knowledge` is never offered |
| `channel` | `mcp` | No `nexora-*` rendering hints in answers; audit rows filterable by channel |

Customers get the seven customer tools; staff also get the three staff-only ones. No
`propose_*` tool exists on this path. Disabling the read tools (`AI_TOOLS_ENABLED=false`)
empties the MCP tool list too. See section 18 for the endpoint and section 4 for the OAuth
details.

<!-- pagebreak -->

## Write tools: propose, then confirm

Eight action types (`App\Enums\AiActionType`), each exposed to the model as
`propose_<name>`. The prefix is deliberate: the name itself tells the model that calling it
does not perform the action.

| Action | Destructive? | Notes |
|---|---|---|
| `propose_create_ticket` | No | Sets every field at once — title, description, priority, type, assignee, column, labels, and `customer_visible: false` to keep it internal |
| `propose_create_tickets` | No | Several new tickets on one board, up to 30 per call; one preview, all or none |
| `propose_update_ticket` | No | Re-assign, move column, re-prioritise, re-label, edit title or body |
| `propose_comment_ticket` | No | Staff comments default to the **internal** stream |
| `propose_create_doc_page` | No | New pages are **always internal** until somebody publishes them separately |
| `propose_update_doc_page` | No | Title and body |
| `propose_code_run` | No | Proposes pressing the existing AI-run button; every refusal that pipeline already has still applies |
| `propose_delete_ticket` | **Yes** | Always confirmed, **by typing the ticket's key by hand** |

### What the schemas deliberately cannot express

> [!IMPORTANT]
> **Visibility can only be narrowed, and only at creation.** The create schemas carry
> `customer_visible`, but a new ticket is never more visible than the board's
> `new_tickets_visible_to_customers` default, so nothing said in a conversation can publish work a
> board keeps internal. No update schema names visibility: publishing an existing ticket stays with
> the people and the screens that already authorize it. There is no field that can name a viewer,
> a user id, a column id or a label id.

Assignees, columns and labels are named **in words** — "Alex Round", "In Progress", "bug" —
rather than by id. That is what the model has to work with, since it is repeating what
somebody said out loud; and a name has to be *resolved*, which is the step where
`ProposalTargets` can refuse an assignee who is not on the board and ask which of two
matching columns was meant. An id would skip that step and land the change somewhere
plausible and wrong.

### Confirmation

```diagram
   Model emits propose_update_ticket
            |
            v
   Is this action destructive?          yes ──► ALWAYS CONFIRM
            | no                                (type the ticket key by hand)
            v
   Is the workspace at AI Agent?
            |
      +-----+------+
      | yes        | no  (Operator)
      v            v
   Carried out   Preview + Confirm button
   immediately          |
      |                 v
      +--------> ExecuteChatAction
                        |
                        +-- re-resolve the target from the stored proposal,
                        |   through the readers that apply visibility
                        +-- AiCapabilityGuard::assertCanExecuteProposal
                        +-- Gate checks against the CONFIRMING user,
                        |   PER FIELD, all before any write
                        +-- call the ORDINARY action
```

Five properties hold on both paths:

**The proposal is not trusted, only read.** Everything is re-resolved at the moment of
execution — the ticket by its number *within this board*, the page by its slug *within this
board* — through readers that apply visibility. A proposal naming ticket 999 on another
board resolves to nothing and is refused. The model's output is treated as a form submission
from an untrusted client, which is exactly what it is.

**Authorization is the real one.** Gate checks run against the confirming user with the same
abilities the ordinary screens use. A person who cannot create a ticket by hand cannot
create one by confirming a proposal.

**Authorization is per field, and happens first.** A request to move a card *and* re-assign
it asks `TicketPolicy::move` and `::assign`, and **both are taken before either write
happens** — so a team member who may move but not assign changes nothing at all and is told
why, rather than getting a moved card, a dropped assignee and a vague error.

**The writes go through the ordinary actions** — `CreateTicket`, `UpdateTicket`, `MoveTicket`,
`SyncTicketLabels`, `PostComment`, `DeleteTicket`, `CreatePage`, `UpdatePage`. Every rule
those own still applies, and there is no second, weaker path into the same tables.

**One proposal per turn is kept** — a preview showing two unrelated changes invites
confirming both while reading one — **with one exception: new tickets.** "Break this plan into
tickets" is one request, so `propose_create_tickets` carries up to `AI_CHAT_MAX_TICKETS_PER_REPLY`
(30) tickets in one call, and any ticket creations the model splits across several calls in a
turn are merged into that one proposal for one board. Its preview lists every ticket; its
button reads *Create N tickets*; under AI Agent it runs at once, like a single ticket.

- **All or none.** Every ticket is authorized and resolved first, writing nothing; one refusal
  (an unknown column, an assignee not on the board) fails the batch with "Ticket 3 ("…"): …
  Nothing was created." Then they are created in order, so the numbers follow the list.
- **Said, not hidden.** Tickets past the cap, or addressed to another board, are left out with a
  note on the card. A reply cut off by the output ceiling becomes a *failed* action saying so,
  never a Confirm button for nothing. Turns that may propose changes get
  `AI_CHAT_MAX_OUTPUT_TOKENS_WRITING` (16,000) so a long list fits.
- **Once only.** `ExecuteChatAction::handle()` takes a per-message cache lock and re-reads the
  row before checking its state, so two tabs or a double click cannot create a batch twice.
- **The model learns outcomes.** Each question's reference data ends with *EARLIER CHANGES IN THIS
  CONVERSATION* — what was created (keys and titles), discarded, refused or still waiting — so
  "continue with the rest" neither repeats nor skips tickets.

**Visibility of new tickets.** A ticket the assistant creates starts from the board's default
(`new_tickets_visible_to_customers`, visible unless the board says otherwise) — the same default as
the New ticket form. The proposal can narrow it (`customer_visible: false` for staff-only work) and
never widen it. Changing an existing ticket's visibility is not available to the assistant.

Re-checking the capability mode at confirmation time is not theoretical: a person can have a
preview on screen when an administrator switches the workspace to Observer, and the confirm
button is a separate request.

## Configuration inheritance

```diagram
   config/ai.php        the deployment's answer  (environment variables)
        |
        v
   ai_settings          the workspace's answer   (global AI settings screen)
        |
        v
   boards.settings.ai   this board's answer      (board AI settings screen)
```

**Every field is resolved independently.** A board that names a different *model* gets the
workspace's provider, the workspace's key and the workspace's capability mode; a board that
names a different *mode* keeps the workspace's model. Copying a configuration down a level
would have been simpler to write and would have duplicated a secret in order to change a
dropdown.

Two coercion rules run after the chain, because a stored setting outlives the thing it names:

- a model the effective provider does not serve is discarded in favour of that provider's
  default, so switching from Anthropic to OpenAI does not leave every board pointed at a
  Claude id;
- a provider the catalogue has no models for resolves to a null model, which surfaces as
  "not configured" on the screen rather than as a 404 from a vendor.

### Credential precedence

`AiCredentialVault` is **the only class in the application that reads or writes a provider
API key**. Three sources, most specific first:

1. The board's own key, in `boards.settings.ai.credentials` — the exception rather than the
   norm, for a project billed to its own account.
2. The workspace key, an `ai_credentials` row — what almost every deployment uses.
3. The environment, via config — still first-class, so a deployment that has always set
   `ANTHROPIC_API_KEY` keeps working with no database row at all.

Nothing above the vault ever holds a plaintext credential.

## Providers and models

| Provider | Adapter | Credential |
|---|---|---|
| Anthropic | `ClaudeService`, using `anthropic-ai/sdk` | `ANTHROPIC_API_KEY` or the vault |
| OpenAI | `OpenAiService`, plain HTTP to `/chat/completions` | `OPENAI_API_KEY` or the vault |

The shipped catalogue:

| Model id | Label | Provider | Context window | Vision | Category |
|---|---|---|---|---|---|
| `claude-opus-5` | Claude Opus 5 | Anthropic | 1,000,000 | Yes | Deep reasoning |
| `claude-sonnet-5` | Claude Sonnet 5 | Anthropic | 1,000,000 | Yes | Balanced |
| `claude-haiku-4-5` | Claude Haiku 4.5 | Anthropic | 200,000 | Yes | Fast and cheap |
| `gpt-5.1`, `gpt-5-mini` | *(as configured)* | OpenAI | not stated | Opt-in | — |

> [!NOTE]
> The OpenAI block is deliberately thin: this deployment has no first-party source for those
> context-window figures, so it states none rather than guessing — a null context window
> renders as nothing in the picker, whereas a guess would render as fact. OpenAI model ids
> come from `AI_OPENAI_MODELS`, and vision support is **opt-in** via
> `AI_OPENAI_VISION_MODELS`, because "most current models take images" is not a capability.

Pricing lives in exactly one place — `config('ai.pricing')` — and
`CostCalculationService` is the only reader. **A model that is not listed produces a null
cost rather than a guess**, because an invented number in a cost report is worse than an
honest blank.

## Usage tracking and audit

| Table | Contents |
|---|---|
| `ai_usage_records` | One row per exchange: session, run, user, board, purpose (`chat` / `ticket_run`), provider, model, input and output tokens, whether the provider actually reported usage, estimated cost, duration |
| `ai_tool_invocations` | One row per tool call: tool, category, target, input, success, outcome, result size, duration |
| `ai_sessions` | Rolled-up totals per conversation |
| `ai_runs` | Per-run tokens, cost, duration, branch, pull request URL, error |

The `usage_reported` boolean is the honesty mechanism: a run whose token counts were never
reported has **no** cost, and is reported as an unpriced run rather than folded in as zero.

## Streaming

Answers stream token by token. Streaming and blocking share one `exchange()` method so the
prompt, the tools, the proposal extraction, the usage record and the two writes are defined
once — the only difference is which provider method is called.

The user's turn is stored **before** the provider is called, so a question that fails is
still in the transcript: somebody scrolling back sees what they asked and that it went
wrong, rather than a gap.

`AI_CHAT_STREAM_TIME_LIMIT` (360s) is applied with `set_time_limit()` only on the streaming
path, so PHP's global `max_execution_time` can stay low for every other request.

## Error handling

| Failure | Behaviour |
|---|---|
| No credential | Refused with a recorded reason; the application still boots and every other feature works |
| Provider error | Reported verbatim where it is safe; `AiProviderException` is the only retryable class. In the chat, staff see the provider's reason and customers a neutral sentence; every failed question is logged as a warning (`Workspace AI could not answer a question.`) |
| Provider account out of credit | Anthropic's "credit balance is too low" (a 400) and OpenAI's `insufficient_quota` (a 429) are reported as **out of credit**, naming the account to top up — not as a malformed request or a rate limit. Nothing needs deploying afterwards: the next question works once credit is added |
| Tool failure | Contained, logged in full, reported to the model as a generic failure |
| External lookup unavailable | The assistant says out loud that it could not look something up, rather than guessing |
| Malformed chart or table JSON | Falls back to rendering the model's text as prose |
| Session over its token budget | The person is asked to start a new conversation |
| Stalled run | `ai:reap-stalled` recovers it every ten minutes |

<!-- pagebreak -->
