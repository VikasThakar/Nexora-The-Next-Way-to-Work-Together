# System Architecture

## The shape of the application

Nexora is a **single Laravel application** rendered server-side, made interactive by
Livewire, and deployed as **one Docker image run three ways**. There is no separate
front-end application, no API gateway and no microservices. The one machine-facing interface
— the remote MCP server at `/mcp` that Claude and ChatGPT connect to — is served by
the same application, from `routes/ai.php`.

```diagram
                              BROWSER
        Blade + Livewire · Alpine stores · TipTap · Laravel Echo
                                 |
                 HTTPS           |        WebSocket (wss)
                                 v
      +----------------------------------------------------------+
      |  WEB SERVICE          nginx  -->  PHP-FPM  -->  Laravel   |
      |  (supervisord)        + attachment queue worker           |
      +----------------------------------------------------------+
                 |                    |                   |
                 v                    v                   v
      +----------------+   +------------------+  +-----------------+
      |  WORKER        |   |  REVERB          |  |  MySQL          |
      |  queue:work    |   |  websocket srv   |  |  + jobs table   |
      |  ai, default   |   |  1 replica only  |  |  + cache table  |
      +----------------+   +------------------+  +-----------------+
                 |
     +-----------+-------------+--------------+--------------+
     v           v             v              v              v
  Anthropic   OpenAI        GitHub         Slack          46elks
  (Claude)    (chat,        (webhooks,     (incoming      (SMS)
              voice,         pull           webhooks)
              whisper)       requests)

                        S3-compatible object storage
                   (AWS S3, Cloudflare R2, MinIO, Backblaze B2)
                     holds attachments and AI uploads
```

## Layers, and where the rules live

The codebase keeps a strict separation, and that separation is itself the security design.

| Layer | Directory | Responsibility | May it decide access? |
|---|---|---|---|
| Routes | `routes/web.php` | URL to component, coarse role middleware | First line only |
| Middleware | `app/Http/Middleware` | Role gate, active-account gate | Coarse only |
| Livewire components | `app/Livewire` | Screen state and interaction | Calls policies; holds no rules |
| Policies | `app/Policies` | Per-record authorization | **Yes — single-record rules** |
| Services | `app/Services` | Readers, scopes, integrations | **Yes — SQL-level scoping** |
| Actions | `app/Actions` | One write each, with its own rules | **Yes — field-level rules** |
| Models | `app/Models` | Relationships and query scopes | **Yes — `visibleTo()` scopes** |
| Support | `app/Support` | Value objects, formatting, parsing | No |

> [!NOTE]
> **Authorization is enforced in SQL first and in policies second.** A collection is
> filtered by a query scope (`Ticket::visibleTo()`, `Comment::visibleTo()`,
> `AiRun::visibleTo()`), and a single loaded record is then checked by the matching policy
> method. Each policy is written to mirror its scope line for line. If the two ever
> disagree, the scope is the one that has actually been protecting the list.

## Why each technology is here

| Technology | Why it is used, per the implementation |
|---|---|
| **Laravel 12** | Policies, queues, broadcasting, notifications and Eloquent scopes are the actual enforcement mechanisms, not conveniences layered on top |
| **Livewire 4** | Server-rendered interactivity without a second application. One component owns a whole board, so a drag-and-drop move re-renders source column, destination column and both counts in one round trip |
| **Alpine (bundled in Livewire)** | Client-only state that must not wait for the network: theme, command palette, AI panel, dialogs |
| **Tailwind CSS 4** | Utility styling with no component stylesheet to fight; the Vite plugin scans Blade and PHP for class names |
| **TipTap / ProseMirror** | A schema-constrained editor: the document can only ever contain nodes the schema declares, which is a security property as much as a correctness one |
| **Reverb** | First-party WebSocket server, so realtime needs no third-party service and channel authorization runs through Laravel's own gates |
| **MySQL** | Relational integrity is load-bearing — foreign keys, unique constraints and the `(board_id, number)` ticket key are what make the rules hold under concurrency |
| **Database queue** | No Redis required for a working deployment; `jobs`, `job_batches` and `failed_jobs` are ordinary tables |
| **Redis (optional)** | Needed only for multi-replica scheduler locks and Reverb scaling |
| **S3-compatible storage** | Container filesystems are replaced on every deploy; a production boot with an ephemeral attachment disk is refused outright |
| **Docker + Supervisor** | One image with three start commands, so the worker image cannot drift from the web image |
| **nginx + PHP-FPM** | Static assets and request-body buffering handled outside PHP |
| **Railway** | Declarative per-service definitions in `railway*.json`; TLS terminated at the edge |

## Request lifecycle

```diagram
  Request
     |
     +- trustProxies('*')          Railway terminates TLS: recover real scheme and client IP
     +- web middleware group       session, cookies, CSRF
     +- AuthenticateSession        session bound to the password hash it was created with
     +- EnsureUserIsActive         a deactivated account is logged out on its next request
     +- role:...  (where present)  coarse role gate for the route group
     +- password.confirm (where present)   re-authentication for administration screens
     |
     v
  Livewire component mount()
     |
     +- Gate / policy check        denies as 404 for anything a person must not know exists
     v
  render()  -->  Reader service  -->  Eloquent scope  -->  SQL with the viewer applied
     |
     v
  Blade  -->  ContentRenderer (Markdown, sanitised, viewer-aware links)
```

> [!IMPORTANT]
> Every Livewire interaction is a **new HTTP request**. Components re-authorize on
> `mount()`, on every action and on every render — they never trust what the previous
> render decided. This is why `Docs\Show` re-resolves its page through `DocPageFinder` on
> each render: Livewire rehydrates models by primary key, which bypasses query scopes.

## Domain model map

```diagram
                              +----------+
                              |   User   |   role: admin | team | customer
                              +----+-----+
                                   |  board_members  (explicit membership)
                                   v
   +--------------------------  Board  -------------------------------+
   |   slug . ticket_prefix . next_ticket_number . settings (JSON)    |
   +--+----------+-----------+------------+------------+--------------+
      |          |           |            |            |
      v          v           v            v            v
  BoardColumn  Label     DocPage    BoardRepository  AiSession
      |                    | tree          |             |
      |                    | (max depth 4) |             v
   +------+                |               |        AiChatMessage
   |Ticket| <--------------+               |             |
   +--+---+                                |             v
      |   number unique per board          |        AiAttachment
      +--> Comment       (stream: customer | internal)
      +--> TicketSubtask                   |
      +--> TicketEvent   (append-only history)
      +--> TicketLink    (to any ticket)   |
      +--> Attachment    (polymorphic)     |
      +--> GithubLink <--------------------+
      +--> AiRun  -->  AiUsageRecord
```

<!-- pagebreak -->
