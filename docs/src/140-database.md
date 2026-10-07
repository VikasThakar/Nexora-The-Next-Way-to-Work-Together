# Database Architecture

MySQL in production; SQLite in memory for the test suite. 42 migrations, dated from
`0001_01_01` (framework tables) through `2026_09_12`.

## Entity relationship overview

```diagram
                    users
                      | 1
                      |
         +------------+-------------+-----------------+
         | *          | *           | *               | *
   board_members   tickets      doc_pages         ai_sessions
         | *       (assignee,     (creator,            | 1
         |          creator)       editor,             |
         | 1                       draft_by)           | *
      boards ------------------------------------- ai_chat_messages
         | 1                                           | 1
         +-- board_columns          * ---+             | *
         +-- labels                      |        ai_tool_invocations
         +-- tickets  ------------------+
         +-- doc_pages  (parent_id, self-referencing, max depth 4)
         +-- board_repositories
         +-- ai_runs
         +-- attachments  (polymorphic; board_id nullable)
         +-- comments
         +-- ticket_events
         +-- github_links
         +-- sms_messages
         +-- activity_log

   tickets 1 ---* comments            (stream: customer | internal, soft deletes)
   tickets 1 ---* ticket_subtasks
   tickets 1 ---* ticket_events       (append-only)
   tickets * ---* labels              (ticket_label pivot)
   tickets 1 ---* ticket_links ---* tickets   (blocks | relates_to)
   tickets 1 ---* github_links
   tickets 1 ---* ai_runs ---* ai_usage_records
   tickets 1 ---* attachments         (morph alias: ticket)

   ai_sessions 1 ---* ai_attachments 1--1 attachments
   ai_settings      (singleton row)
   ai_credentials   (one row per provider, encrypted secret)
   webhook_deliveries   (source + delivery_id unique)
```

## Core tables

### `users`

| Column | Type | Notes |
|---|---|---|
| `name`, `email` | string | Email unique |
| `email_verified_at` | timestamp | Column exists; **no verification flow is implemented** |
| `password` | string | bcrypt, cast `hashed` |
| `role` | string(32), indexed | Default `team`. **Not mass-assignable** |
| `theme_preference` | string(10) | Default `light`. **Not mass-assignable** |
| `avatar_path` | string(2048), nullable | Generated storage key for the profile picture. Null is the whole of "no picture". **Not mass-assignable** |
| `avatar_disk` | string(40), nullable | Which disk it was written to, recorded per row so changing `ATTACHMENT_DISK` cannot orphan it. **Not mass-assignable** |
| `deactivated_at` | timestamp, indexed | Null means active |

### `boards`

| Column | Type | Notes |
|---|---|---|
| `name` | string, indexed | |
| `slug` | string | **Unique.** The route key |
| `ticket_prefix` | string(12) | **Unique.** 2–6 chars, `/^[A-Z][A-Z0-9]*$/` |
| `next_ticket_number` | unsigned int | Default 1. Allocated under `lockForUpdate` |
| `settings` | JSON | AI, Slack, SMS and board options |
| `created_by_id` | FK users | `nullOnDelete` |
| `archived_at` | timestamp, indexed | |

### `board_members`

`(board_id, user_id)` **unique**, plus an index on `(user_id, board_id)`. Both foreign keys
cascade on delete. `added_by_id` records who granted access.

This table **is** the access-control list.

### `tickets`

| Column | Type | Notes |
|---|---|---|
| `board_id` | FK boards | `cascadeOnDelete` |
| `board_column_id` | FK board_columns | **`restrictOnDelete`** — a column cannot be deleted out from under its tickets |
| `number` | unsigned int | **Unique with `board_id`** |
| `title` | string(200) | |
| `type` | string(20) | Default `task` |
| `description_md` | text | |
| `priority` | string(20) | Default `medium` |
| `assignee_id`, `created_by_id` | FK users | `nullOnDelete` |
| `estimate` | decimal(6,2) | |
| `due_date` | date, indexed | |
| `customer_visible` | boolean | **Default `false`** |
| `position` | unsigned int | |

Indexes: `(board_id, number)` unique · `(board_column_id, position)` ·
`(board_id, customer_visible)` · `(board_id, assignee_id)` · `due_date` ·
`(board_id, created_at)` · `(board_id, updated_at)`.

> [!NOTE]
> `(board_id, customer_visible)` is the index that makes the customer boundary cheap: the
> filter applied to almost every ticket read is a composite index seek rather than a scan.
> The two reporting indexes were added specifically for the statistics screens.

### `comments`

`ticket_id`, `board_id`, `stream` (default `internal`), `author_id`, `body_md`, `edited_at`,
`deleted_by_id`, soft deletes. Indexes: `(ticket_id, stream, created_at)` and
`(board_id, stream)` — both lead with the scoping column so a stream-filtered read is an
index range.

### `doc_pages`

| Column | Notes |
|---|---|
| `parent_id` | Self-referencing, **`restrictOnDelete`** |
| `slug` | **Unique with `board_id`** |
| `icon` | string(16), an emoji |
| `body_md` | longText |
| `customer_visible` | Default `false` |
| `draft_title`, `draft_md`, `draft_saved_at`, `draft_by_id` | The autosave buffer |
| `created_by_id`, `updated_by_id` | Authorship |

Indexes: `(board_id, slug)` unique · `(board_id, parent_id, position)` ·
`(board_id, customer_visible)`.

### `ticket_events` — the history

`ticket_id`, `board_id`, `type`, `payload` JSON, `actor_id`, `from_column_id`,
`to_column_id`, `created_at`.

Indexes: `(ticket_id, created_at)` · `(board_id, type, created_at)` ·
`(board_id, to_column_id, created_at)`.

> [!IMPORTANT]
> This table is **append-only** and is the source for every flow metric. The column ids are
> stored as dedicated indexed columns rather than only inside the JSON payload precisely so
> that "which tickets entered a done column on this board last week" is one index range scan.
> A later migration backfilled them from existing payloads.

### `attachments`

Polymorphic `morphs('attachable')` with an **enforced morph map** (`ticket`, `comment`,
`doc_page`, `ai_session`). `board_id` is **nullable** — an AI conversation can be
workspace-scoped and belong to no board. Stores `disk`, `path`, `filename`, `mime_type`,
`size`, `uploaded_by_id`.

> [!NOTE]
> `Relation::enforceMorphMap()` rather than `morphMap()` means an attachment pointing at a
> type nobody has registered **fails loudly** instead of resolving to something unchecked.
> Enforcement is all-or-nothing, which is why `user`, `board` and `board_column` are also
> registered — they appear in `notifications.notifiable_type` and
> `activity_log.subject_type`.

## AI tables

| Table | Purpose | Key columns |
|---|---|---|
| `ai_settings` | Workspace-wide AI configuration, a **single row** | `enabled`, `provider`, `model`, `capability_mode` (default `observer`), `session_token_limit`, `daily_user_token_limit` |
| `ai_credentials` | One row per provider | `provider` **unique**, `secret` (**encrypted**), `last_four`, `hint`, `rotated_at` |
| `ai_sessions` | One conversation | `uuid` unique, `user_id`, `board_id` (nullable), `provider`, `model`, `capability_mode`, `chat_mode`, `knowledge_scope`, token and cost totals |
| `ai_chat_messages` | Turns | `board_id` **nullable**, `ai_session_id`, `role`, `content`, `metadata` (holds the proposal) |
| `ai_runs` | One ticket run | `uuid` unique, `trigger_source`, `mode`, `status`, `repository`, `model`, `result_comment_id`, `branch_name`, `pull_request_url`, tokens, cost, duration, `error_message` |
| `ai_usage_records` | One row per billed exchange | `purpose` (`chat`/`ticket_run`), `provider`, `model`, tokens, **`usage_reported`**, `estimated_cost` |
| `ai_attachments` | One uploaded file, processed | `attachment_id` **unique**, `kind`, `status`, `checksum`, `extracted_text`, `structured`, `summary`, `characters`, `token_estimate`, `truncated`, `context_sent_at` |
| `ai_tool_invocations` | **The AI audit log** | `tool`, `category`, `target`, `input`, `success`, `outcome`, `result_characters`, `duration_ms` |

## Integration tables

| Table | Purpose |
|---|---|
| `board_repositories` | `repository_name` **unique per board**, `default_branch`, `is_primary`, `configuration` JSON |
| `github_links` | Unique on `(ticket_id, repository, type, external_id)`. Holds `state`, `ci_status`, `author_login`, `merged_at` |
| `webhook_deliveries` | Unique on `(source, delivery_id)` — **the insert is the de-duplication claim** |
| `sms_messages` | Unique on `(event, ticket_id, recipient)` — duplicate suppression enforced by the database, not a cache |
| `activity_log` | `spatie/laravel-activitylog`, with an added `board_id` foreign key and `(board_id, created_at)` index |

## Framework tables

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
`password_reset_tokens`, `notifications`.

## Referential integrity strategy

| Pattern | Used for | Effect |
|---|---|---|
| `cascadeOnDelete` | `board_id` on almost everything, `ticket_id` on children | Deleting a board removes its entire graph |
| `restrictOnDelete` | `tickets.board_column_id`, `doc_pages.parent_id` | Prevents orphaning structure |
| `nullOnDelete` | Every `*_by_id` and `assignee_id` | **Deleting a user preserves the history they created** — a ticket keeps existing with no creator rather than vanishing |

## Notable migration decisions

| Migration | What it did, and why it is interesting |
|---|---|
| `allow_workspace_scoped_ai_conversations` | Made `ai_chat_messages.board_id` nullable so a conversation can be about the whole workspace, and added `(user_id, board_id, created_at)`. The `down()` **deletes** workspace-scoped rows before restoring `NOT NULL` |
| `add_sessions_to_ai_chat_messages` | Backfilled `ai_session_id` by grouping existing messages per user and board into adopted sessions, so no transcript was orphaned |
| `add_column_ids_to_ticket_events` | Backfilled `from_column_id`/`to_column_id` from the JSON payload in chunks of 500 |
| `make_attachment_board_optional` | Made `attachments.board_id` nullable for AI conversation uploads |
| `add_reporting_indexes_to_tickets` | Added the two indexes the statistics screens needed |

> [!NOTE]
> Several `down()` methods are **destructive by necessity** — restoring a `NOT NULL`
> constraint requires removing the rows that violate it. Read a rollback before running one
> in an environment whose data matters.

<!-- pagebreak -->
