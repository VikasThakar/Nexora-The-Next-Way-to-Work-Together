# Implementation Status

Every capability discussed in this document, with its honest state. This is the section to
read before making a commitment to anybody about what Nexora does.

## Fully implemented

| Capability | Evidence |
|---|---|
| Three-role model with an absolute customer boundary | `UserRole`, `BoardAccess`, 28 security tests |
| Board membership as the access-control list | `board_members`, `BoardPolicy` |
| Kanban boards, columns, drag and drop | `Boards\Show`, `x-sort` |
| Tickets: type, priority, assignee, estimate, due date, position | `tickets` table, `TicketPolicy` |
| Labels, checklists, ticket links, attachments | Four dedicated tables |
| Two-stream conversation, enforced in the action | `CommentStream`, `PostComment` |
| Append-only ticket history with 20 event types | `ticket_events`, `TicketEventType` |
| Nested documentation with the ancestor visibility rule | `DocPageFinder`, `SetPageVisibility` |
| WYSIWYG editing over Markdown, with audience-aware autosave | TipTap, `EditorHtml`, `SaveDraft` |
| Workspace activity feed | `spatie/laravel-activitylog`, `ActivityLogger` |
| Command palette across four categories | `GlobalSearch` |
| In-app notifications, policy-checked per recipient | `NotificationDispatcher` |
| Realtime with audience-split private channels | Reverb, `BoardBroadcaster`, `channels.php` |
| Light / dark / system theme, persisted per user | `ThemePreference`, `theme.js` |
| Team and customer statistics as separate services | `TeamStatistics`, `CustomerStatistics` |
| Flow metrics from history, not current state | `FlowMetrics` |
| CSV export (team and customer), PNG chart export | `StatisticsExport`, `chart-export.js` |
| Workspace AI assistant, panel and full page | `Assistant`, `Chat` |
| Three-way AI settings model | `AiCapabilityMode`, `AiChatMode`, `AiKnowledgeScope` |
| Ten retrieval tools, bounded loop, full audit | `AiToolRegistry`, `ai_tool_invocations` |
| Seven propose-and-confirm write actions | `AiActionType`, `ExecuteChatAction` |
| AI runs: suggest mode, end to end | `TicketAnalysisService`, `ExecuteAiRunJob` |
| AI charts, tables and KPI rows, server-rendered from validated specs | `RichResponse\*` |
| Voice: speech-to-text and text-to-speech | `AiVoiceController`, `OpenAiVoiceProvider` |
| AI attachments across seven kinds | `Attachments\*`, `ProcessAiAttachment` |
| Token accounting and cost calculation | `ai_usage_records`, `CostCalculationService` |
| Two providers with independent field inheritance | `AiConfigurationResolver`, `AiCredentialVault` |
| Daily run caps with no bypass for automatic runs | `AiRunCap` |
| GitHub inbound: five event types, signed, de-duplicated | `WebhookProcessor`, `WebhookSignature` |
| GitHub outbound: push a branch, open a draft pull request | `PullRequestClient` |
| Slack: per-board, per-event, encrypted webhook URL | `SlackNotifier`, `BoardSlackSettings` |
| SMS alerts for critical tickets, with retention | `AlertCriticalTicket`, `sms_messages` |
| Three-queue architecture with a disk-aware worker split | `supervisord.conf`, `railway*.json` |
| Scheduled housekeeping | `routes/console.php` |
| Signed-URL downloads with policy delegation | `AttachmentController`, `AttachmentPolicy` |
| Production storage-durability enforcement | `AppServiceProvider` |
| Docker multi-stage build, three Railway services | `Dockerfile`, `entrypoint.sh` |

## Partially implemented

| Capability | What exists | What is missing |
|---|---|---|
| **Apply-mode code generation** | The whole pipeline: clone, branch, validate, commit, push, draft PR, with every refusal point | `AI_CODE_DRIVER` **defaults to `unavailable`**, in which case an apply run fails immediately with an internal note naming what is missing. Requires `claude_code` plus the binary and a key in the worker's environment |
| **Repository cloning** | `GitClient`, `RepositoryCheckout`, context summarisation | `AI_REPOSITORY_CLONE_ENABLED` **defaults to `false`**. Suggest mode then reasons from metadata and the ticket alone, and **records that the working tree was unavailable** rather than pretending |
| **External knowledge lookup** | `ExternalKnowledgeProviderInterface`, a tool, config, and a refusing default | **No driver ships.** `AI_KNOWLEDGE_DRIVER` is empty and resolves to `UnavailableExternalKnowledge`. Outside Project mode still works — what is absent is live third-party lookup. Adding one is a class plus a `match` arm |
| **OpenAI model catalogue** | A working adapter and provider selection | Context windows and pricing are **not stated** for OpenAI models, so they render as blank and report no cost. Vision is opt-in per id |
| **SMS delivery receipts** | `SMS_46ELKS_DLR_URL` may be configured | **No receiving endpoint is exposed.** Setting it would produce retries at the provider |
| **Email** | Password reset works | No other transactional mail. No email notification channel — notifications are in-app, Slack or SMS |
| **Board archiving** | `archived_at`, `notArchived()` scope | No UI to archive or restore a board |
| **Ticket type** | Stored, filterable, in AI schemas | No workflow behaviour differs by type |

## Planned / Not Yet Implemented

| Capability | Status |
|---|---|
| **AI Standup Generator** | **Not implemented.** Searched the whole repository: the only match is a test fixture filename (`standup.mp3`). There is no standup service, command, component, route, view or job. The building blocks exist — `get_activity`, `get_code_activity`, `ActivityReader`, `GithubLinkReader` — so it is buildable, but **nothing generates a standup today** |
| General REST / JSON API for third parties | No `routes/api.php`, no OpenAPI spec. The only machine-facing interface is the remote MCP server (`/mcp`, OAuth), which reads and makes the changes the signed-in person could make by hand |
| Webhooks **out** of Nexora | Only Slack and SMS leave the application |
| Email notifications | Password reset only |
| Two-factor authentication | Not implemented |
| SSO / SAML | Not implemented (OAuth exists only as the authorization server for MCP connectors) |
| Email verification | Column exists, no flow |
| Documentation version history | Only the current body and one draft |
| Documentation page comments | Not implemented |
| Collaborative editing (multiple cursors) | Autosave is single-author |
| Export documentation to PDF/Word | Not implemented |
| Per-ticket CSV export | Deliberately absent — aggregates only |
| Time tracking | `estimate` is stored; nothing logs actual time |
| Sprints / iterations / epics | Not implemented |
| Recurring tickets, templates | Not implemented |
| Multi-tenant organisations | **No workspace entity.** One deployment is one workspace; `BoardIdentifiers` notes the two closures that would need to become organisation-aware |
| Custom fields | Not implemented |
| Ticket import / export | Not implemented |
| Browser / end-to-end tests | None |
| JavaScript unit tests | None |
| CI pipeline configuration | Not present in the repository |
| Content-Security-Policy, HSTS headers | Not set |

## Documentation gaps and inconsistencies found

Issues discovered while writing this document. None is a functional defect in itself, but
each is worth an owner.

| # | Finding | Impact | Suggested action |
|---|---|---|---|
| 1 | **`README.md` is empty (0 bytes).** | A developer cloning the repository gets no orientation at all. The `supervisord.conf` comments and `config/ai.php` both refer readers to "the deployment notes in README.md", which do not exist | Populate it, or point it at this document |
| 2 | **Product naming is inconsistent.** `WORKSPACE_NAME` defaults to `Aqueduct Workspace`, the package is `aqueduct/workspace`, but the AI system prompt, comments and one aria-label say *Nexora* | A deployment that has not set `WORKSPACE_NAME` presents as Aqueduct while its assistant calls itself Nexora | Decide the product name; set `WORKSPACE_NAME` and `WORKSPACE_SHORT_NAME` accordingly |
| 3 | **No version identifier anywhere.** No git tag, no `version` in `composer.json`, no application constant | Nothing can state which build is deployed; support and rollback have no vocabulary | Adopt tags, and surface a version in `/health` |
| 4 | **`AiVisualizationSecurityTest` has a date-sensitive assertion.** It forbids the digit `7` anywhere in the customer's answer, but the report header carries today's date | The suite fails on roughly a third of calendar days, for no real reason — and it fails in the *security* suite, which is the one people trust most | Narrow the assertion to `(total 7)` rather than `7` |
| 5 | **Two pre-existing `ThemePreferenceTest` failures.** | Two red tests that are not a regression | Fix or quarantine, so "green" means green |
| 6 | **`DemoUserSeeder` contains passwords in source.** Documented in the class as a deliberate demo trade-off | Anybody who can read the repository can sign in to a demo deployment | Change the accounts before anything real lands, as the class itself instructs |
| 7 | **Default capability mode is `agent` in config but `observer` in the `ai_settings` column default.** Both are deliberate and documented, but they disagree | A deployment's effective posture depends on whether a settings row exists | Decide one, or document the interaction in the admin screen |
| 8 | **No Content-Security-Policy or HSTS header.** | Two cheap defence-in-depth controls are absent | Add both at nginx |
| 9 | **`SESSION_SECURE_COOKIE=false` in `.env.example`.** Correct for local HTTP; wrong for production | An easy-to-miss production hardening step | Note it in a deployment checklist |
| 10 | **No CI configuration in the repository.** A 139-file suite with a dedicated fast security gate exists but nothing runs it automatically | Regressions rely on somebody remembering | Add a workflow running `composer test:security` on every push and the full suite on merge |
| 11 | **Ticket `type` has no behaviour.** Stored and filterable, but nothing differs between a bug and a feature | A field that looks meaningful but is decorative | Either give it behaviour or document it as a label |
| 12 | **Board archiving has no UI.** The column and scope exist | Dead capability | Add the control, or remove the column |

<!-- pagebreak -->

# Glossary

| Term | Meaning in Nexora |
|---|---|
| **Administrator** | `admin`. Sees every board without membership; the only role that may create boards, manage users, hold AI credentials and set the workspace capability mode |
| **AI Agent** | The most permissive capability mode. Adds unattended work: automatic runs, apply-mode pull requests, and reversible changes carried out without a confirmation step |
| **AI Observer** | The most restrictive capability mode. Reads and answers; writes nothing, **not even an internal note**, so no ticket run may start |
| **AI Operator** | The middle capability mode. Proposes changes a person confirms, and permits manual suggest runs |
| **Apply mode** | An AI run that changes code: isolated clone, new branch, validation, commit, push, **draft** pull request. Never merges, never pushes to a protected branch |
| **Assignee** | The staff member doing the work. Customers are never assignable |
| **Attachment** | A file on a ticket, comment, documentation page or AI conversation. Has no visibility of its own — it inherits its owner's |
| **Board** | The unit of work, access and configuration. Everything hangs off one. **There is no entity above it** |
| **Board member** | An explicit `board_members` row. **This is the access grant**; administrators bypass it |
| **Capability mode** | The administrator's ceiling on what the AI may ever attempt. See AI Observer / Operator / Agent |
| **Chat mode** | The asker's choice per conversation: Reading, Writing or Everything |
| **Customer** | `customer`. The security boundary of the product. May never observe internal content, on any board, including boards they belong to |
| **Customer stream** | The ticket conversation the customer takes part in |
| **Customer-visible** | The positive visibility flag on a ticket or page. **Defaults to false**, so anything created by code that forgets to set it is internal |
| **Digest** | The shortened form of an AI attachment sent on turns after the first — name, kind, shape, outline, leading excerpt |
| **Draft (documentation)** | The autosave buffer for a **published** page. Nothing renders it to a reader |
| **Draft PR** | A pull request opened by apply mode, marked draft so it arrives visibly unfinished rather than looking like finished work |
| **Everything mode** | The chat mode offering both read and write tools |
| **Internal** | Not visible to customers. The default for tickets, comments and documentation pages |
| **Internal note** | A comment in the internal stream. Customers cannot read it, count it, or detect that it exists |
| **Knowledge scope** | Where an answer may be sourced from: **Project only** (default) or **Outside Project** (project **and** general knowledge) |
| **Label** | A board-scoped tag. Staff-only to change |
| **Outside Project** | The additive knowledge scope. Never replaces the project — it adds general knowledge alongside it, and widens data access by nothing |
| **Primary repository** | The repository apply mode targets when a run does not name one |
| **Proposal** | A change the AI has *described*. A preview until a person confirms it — at which point it is re-resolved, re-authorized and executed through the ordinary action |
| **Queue** | Background work. Three: `ai` (runs), `default` (broadcasts, notifications, webhooks, Slack, SMS), `attachments` (AI file processing, in the web container) |
| **Reading mode** | The default chat mode. Lookup tools, no write tools |
| **Run** | One AI working session on one ticket. Suggest or apply |
| **Session (AI)** | One conversation. Belongs to **one person**, including against administrators |
| **Slug** | The URL identifier for a board or documentation page. Primary keys are never exposed |
| **Staff** | Administrators and team members together. The set that may see internal content |
| **Stream** | Which conversation a comment belongs to: `customer` or `internal` |
| **Suggest mode** | An AI run that analyses and posts an internal note. Changes no code |
| **Team member** | `team`. Full working access to boards they are a member of; not workspace administration |
| **Ticket** | A unit of work. Addressed by `PREFIX-NUMBER`, e.g. `NL-42` |
| **Ticket key** | The human identifier — the board's prefix, a hyphen, the per-board number. What GitHub branches and commits reference |
| **Ticket prefix** | A board's 2–6 character code, globally unique |
| **Tool** | A read-only lookup the AI may call. Runs **with the asking person as the viewer**, and is audited whatever the outcome. Over MCP an AI app also has **write tools**, which make a change as the person, under their permissions |
| **Webhook (inbound)** | GitHub telling Nexora about code activity. Authenticated by HMAC-SHA256 over the raw body |
| **Webhook (outbound)** | A Slack incoming-webhook URL. A bearer credential, so it is encrypted at rest and write-only in the UI |
| **Workspace** | The whole deployment. **Not an entity** — there is no workspace table. Used in this document to mean "everything in this installation" |
| **Workspace AI** | The assistant. Two surfaces: the panel (everyone, read-only for customers) and the full-page board chat (staff only) |

<!-- pagebreak -->

# Appendix — Document Provenance

This document was produced by reading the Nexora repository at commit `30bfc83`. The
following were examined in full or in substantial part:

| Area | Files |
|---|---|
| Configuration | `composer.json`, `package.json`, `.env.example` (183 variables), `phpunit.xml`, `vite.config.js`, all 19 files in `config/` |
| Routing | `routes/web.php`, `routes/channels.php`, `routes/console.php`, `bootstrap/app.php`, `bootstrap/providers.php` |
| Container and deployment | `Dockerfile`, `docker/entrypoint.sh`, `docker/nginx.conf`, `docker/php.ini`, `docker/php-fpm.conf`, `docker/supervisord.conf`, `railway.json`, `railway.worker.json`, `railway.reverb.json` |
| Domain | All 25 models, all 42 migrations, all 8 policies, all 27 enums, key actions and services across AI, GitHub, Slack, SMS, statistics, search and attachments |
| Presentation | All 36 Livewire components (class documentation), the 107-file `resources/` tree, the 16 JavaScript modules |
| Quality | The test-file inventory (139 files) and a full test run |

Verification performed while writing:

- The full test suite was executed: **1,718 passed, 3 failed, 6,436 assertions, 311 seconds.**
- The three failures were isolated, reproduced individually and diagnosed. All three are test
  defects; none is an application defect. See section 25.
- Every environment variable named in this document was checked against `.env.example` and the
  configuration file that reads it.
- Every permission claim was derived from middleware, policies, actions, query scopes or tool
  `availableTo()` methods — never assumed.
- The claim that no AI Standup Generator exists was verified by a repository-wide search.
- One claim in an early draft — that login was not rate limited — was found to be wrong on
  verification and corrected; login is throttled at 5 attempts, keyed on email and IP.

No application code was modified in the production of this document. The repository changes
are limited to two things: the new `docs/` directory, containing this document, its Markdown
source and its build script; and four lines appended to `.gitignore`, so that the build
tooling's `node_modules` and intermediate render artefacts are not committed.
