# AI Ticket Runs

A **run** is the AI working on one ticket, off the request cycle, on its own queue. It is a
different feature from the assistant: the assistant is a conversation, a run is a job with a
result.

## The two modes

| Mode | What it does | Requires |
|---|---|---|
| **Suggest** | Reads the ticket and the repository metadata, writes an opinion as an **internal note** on the ticket | AI Operator or above |
| **Apply** | Works in an isolated clone, on a new branch, runs the configured tests, and opens a **draft pull request** | **AI Agent**, a GitHub token, an attached repository, and a code-generation driver |

`Off` is part of the same enum rather than a separate boolean, so the code that reads a
board's automatic behaviour cannot forget to check an "enabled" flag as well.

## Triggers

| Trigger | Who starts it | Cap | Bypass |
|---|---|---|---|
| **Manual** | A staff member presses a button on the ticket | 100/day (`AI_DAILY_MANUAL_RUN_CAP`) | Administrators may exceed it if `AI_ADMINS_BYPASS_CAP=true` |
| **Automatic** | A customer's ticket is created, if the board opts in | 20/day per board (`AI_DAILY_AUTO_RUN_CAP`), board-configurable up to 500 | **Nobody. Ever.** |

> [!IMPORTANT]
> The asymmetry is the whole design of `AiRunCap`. Nobody chose the automatic runs — a
> customer filing twenty tickets in an afternoon starts twenty runs, and on apply mode that
> is twenty clones and twenty pull requests. **There is no bypass for the automatic cap
> because there is nobody to grant one to.** A manual run is a deliberate human act by
> somebody who knows what it costs, so its cap is a runaway-loop guard rather than a budget.

"Today" is the **board's own day**: a board configured for `Europe/Stockholm` rolls over at
Stockholm midnight, because that is when the people looking at the number think the day
changed. Queued, running, completed and failed runs all count; **cancelled runs do not**,
since they never started and holding them against the day would punish somebody for changing
their mind.

## Run lifecycle

```diagram
  STATUS       queued ──► running ──► completed
                  │          │    └──► failed
                  └──────────┴──────► cancelled  (only while still queued)

  STAGE        queued ─► preparing ─► analysing ─► coding ─► testing ─► pull_request ─► finished
                                                                                     └► failed

  SUGGEST MODE                          APPLY MODE
  ------------                          ----------
  queued                                queued
  preparing   (resolve repository)      preparing     check the coding runtime exists
  analysing   (provider call)           preparing     clone into the run's own directory
  finished    -> internal note          preparing     REFUSE if the base branch is protected
                                        preparing     create a new branch off it
                                        coding        the runtime edits files
                                        coding        re-derive the diff from `git status`
                                        testing       run AI_VALIDATION_COMMANDS
                                        pull_request  commit, push the NEW branch only
                                        pull_request  open a DRAFT pull request
                                        finished      -> internal note with the PR link
```

## Apply mode invariants

Three properties are **structural rather than instructions** — they hold because the code
that could break them does not exist.

| Invariant | How it is guaranteed |
|---|---|
| **Never pushes to main** | The push is always `refs/heads/<new branch>`, and the branch name is generated from the ticket key and the run's UUID. The base branch is only ever read. `assertNotProtected()` refuses outright if a board names a protected branch |
| **Never merges** | `PullRequestClient` has **no merge method**. The capability is absent from the codebase |
| **Never fakes success** | An empty diff, a failed validation command or an unavailable runtime all raise. The run is recorded as failed and an internal note names the step that stopped it. **No pull request is opened for work that did not happen** |

Protected branches, whatever a board is configured to say: `main`, `master`, `production`,
`release`, `develop`.

The failure order matters: **validation runs before the push**, so a change that does not
build never becomes a branch on the remote.

> [!NOTE]
> **Apply mode is Partially Implemented as shipped.** `AI_CODE_DRIVER` defaults to
> `unavailable`, and with that setting an apply run **fails immediately** with an internal
> note naming exactly what is missing. Nothing is faked and no empty pull request is opened.
> The working driver is `claude_code`, which runs the Claude Code CLI inside the isolated
> checkout and requires the binary (installed in the Docker image) plus `ANTHROPIC_API_KEY`
> in the worker's environment.
>
> Repository **cloning** is also opt-in: `AI_REPOSITORY_CLONE_ENABLED` defaults to `false`.
> With it off, suggest mode still runs and reasons from the configured repository metadata
> and the ticket alone — and the run **records that the working tree was unavailable** rather
> than pretending it read the code.

## Isolation and cleanup

Every run that touches a repository gets its own directory, named after the run's UUID,
under `storage/app/ai-runs` (`AI_WORKSPACE_PATH`). It is deleted when the run ends, however
it ends, in a `finally` block.

Nothing durable lives there: the note, the pull request URL, the token counts and the failure
reason are all rows before the directory is deleted. Railway containers are ephemeral anyway.

## Duplicate execution

Guarded twice, because the two guards fail differently:

| Guard | Mechanism | Strength |
|---|---|---|
| `WithoutOverlapping` | A cache lock keyed on the run id | Stops two workers starting the same run at the same moment — but depends on the cache being shared and alive |
| **The status claim** | `claim()` flips `queued → running` in a single conditional `UPDATE` and checks the affected row count | **Authoritative**, because it is the database rather than a lock. A second worker that gets past the lock finds zero rows and returns |

## Retries

Only transient provider failures are worth retrying, and they are the ones that raise
`AiProviderException`. A missing credential, an unavailable coding runtime, a protected
branch or a failed test suite will fail identically three times, so those are marked failed
on the first attempt and the job stops. That distinction keeps a misconfiguration from
producing three identical internal notes twenty minutes apart.

| Setting | Default | Variable |
|---|---|---|
| Attempts | 3 | `AI_JOB_TRIES` |
| Timeout | 900s | `AI_JOB_TIMEOUT` |
| Backoff | 30s, then 120s | `config('ai.queue.backoff')` |
| Queue | `ai` | `AI_QUEUE` |

## Stalled runs

A run is owned by the job that claimed it, so if that job disappears — a deploy, a container
restart, an OOM — nothing can finish the run and the ticket shows "Running" for ever,
blocking any further run on it. Since an apply run takes minutes and **every deploy restarts
the worker**, this is ordinary rather than exceptional.

`ai:reap-stalled` runs **every ten minutes** and recovers them. It refuses to touch a run
that still has a job on the queue.

## Visibility

> [!IMPORTANT]
> **A customer has no relationship with AI runs at all.** Not "cannot start one" — cannot
> see that they exist, cannot see a status, cannot see a cost, cannot see a failure. Every
> ability on `AiRunPolicy` denies a customer as **404**, because a 403 on "AI runs for
> AQD-42" would confirm the feature is being used on their ticket.
>
> Enforced three times over: `AiRun::visibleTo()` refuses customers in SQL; the policy
> refuses for a single loaded run and for "may I start one?"; and the panel renders nothing
> — which is a usability consequence of the rule, never the rule itself.

## Results

| Outcome | What is written |
|---|---|
| Completed (suggest) | An **internal note** on the ticket with the analysis, linked as `result_comment_id` |
| Completed (apply) | An internal note with the branch name and the **draft pull request URL** |
| Failed | An internal note naming the step that stopped it; `error_message` on the run |
| Skipped | A `ai_run_skipped` ticket event, e.g. the cap was reached or the capability mode forbids it |

Every outcome also writes a ticket event, appears in the unified timeline, and can raise a
Slack message if the board subscribes to `ai_run_finished` (off by default).

Refusals are written as prose a member of staff can act on — *"The AI is set to AI Observer,
which reads and answers but writes nothing — not even an internal note. Raise the mode to AI
Operator to let runs post their analysis."* — rather than as a code, because the text ends up
in an internal note and on a button tooltip.

<!-- pagebreak -->

# AI Visualisation, Voice and Attachments

## AI-generated charts, tables and KPI rows

The assistant can answer with structured blocks rather than only prose. An answer is a list
of blocks in the order the model wrote them, so a response can read "throughput improved by
18% in Q3", then a chart, then the explanation.

### How a block gets there

Two routes:

1. A **fenced block** with a known name and JSON inside — a fence opened with
   `nexora-chart`, `nexora-table` or `nexora-kpi`, containing a JSON object such as:

```json
{"type": "bar", "title": "Tickets by month", "labels": ["Jul", "Aug", "Sep"],
 "datasets": [{"label": "Raised", "values": [12, 19, 14]}]}
```

2. For tables only, an **ordinary Markdown pipe table**, which is promoted automatically.
   That second route matters more than it looks: a model asked for a table writes a pipe
   table whatever the prompt says, at least some of the time.

### Supported chart types

`line`, `bar`, `hbar`, `area`, `pie`, `donut`, `scatter`.

`hbar` exists as its own type rather than a flag because it is the right answer often
enough to be asked for by name: any breakdown whose labels are people's names, board names
or label names reads far better with the text beside the bar than rotated underneath it.

| Bound | Value |
|---|---|
| Points per series | 400 |
| Series per chart | 8 |
| Slices in a pie or donut | 12 |
| Rows in a breakdown from `get_statistics` | 30 |
| Weeks in a trend series | 60 |

### The security claim

> [!IMPORTANT]
> **The model never supplies anything that is executed or rendered directly.** It supplies
> numbers and labels. Those are validated into a `ChartSpec`, `TableSpec` or `KpiSpec` — a
> type, a list of labels, and a list of series of floats — and the renderer draws an **SVG
> server-side** from *that*.
>
> Consequently: there is no charting library receiving model-authored configuration, so
> there is no option in such a library that turns a string into a callback; nothing
> model-authored reaches a `<script>`, an event handler, a style attribute or a URL; colours
> come from a fixed palette chosen by index, never supplied; and a "chart" cannot be a vector
> of attack the way an uploaded SVG would be.

Validation is strict about numbers and forgiving about everything else. A non-numeric value
becomes a gap at that point rather than discarding the dataset; an unrecognised chart type
falls back to a bar chart. **Failure is always prose**: a fence whose JSON does not parse, a
chart with no numbers or a table with no columns all fall back to rendering the block as the
text the model wrote.

### Data integrity

The `get_statistics` tool's description instructs the model to **always call it before
emitting a chart about the workspace**, and states that figures must never be estimated or
invented. The numbers it returns are already counted under the asker's own scope, so they
are correct to report to them.

> [!NOTE]
> Access control for charts is not a chart feature. It is the ordinary statistics scope: a
> customer asking for a breakdown gets counts of the tickets **they can open**, and the three
> staff-only metrics are refused with a message naming what they can have instead. The
> dedicated test `tests/Security/AiVisualizationSecurityTest.php` asserts that a customer's
> totals exclude internal tickets and that the hidden count cannot be recovered by
> subtraction.

### Export

| Format | How | Route |
|---|---|---|
| **CSV** | The server re-reads the stored answer and re-parses it with **the same parser the screen used**, then exports block *N* | `GET /ai/messages/{message}/blocks/{block}/export.csv` |
| **PNG** | Produced **in the browser** from the SVG already on the page (`resources/js/chart-export.js`) | No server route |

The CSV route addresses a **block index** rather than accepting a payload. A browser that
could POST the rows it is showing would be a browser that could export rows the model never
produced — exactly the wrong property for a file somebody forwards to a client. The only way
the CSV and the on-screen table could disagree is if the same parser gave two answers for
the same text.

Authorization on that route is four filters deep: the route group's `role:admin,team`,
`visibleTo` (board), `ownedBy` (**an administrator cannot export a colleague's
conversation**), and a non-null session id. A miss is a 404.

PNG export has one hard part worth noting: a chart on the page is coloured by Tailwind
classes resolved by the page's stylesheet, so serialising the node alone would lose every
colour. The clone is walked and the handful of properties that actually draw anything are
copied from `getComputedStyle` onto the elements as presentation attributes.

## Voice

Spoken conversation is **three steps, not a fourth kind of session**: speech-to-text, the
ordinary assistant, text-to-speech. That is what lets a spoken question inherit sessions,
attachments, capability modes, the audit trail and the customer boundary without any of them
being taught about voice.

| Endpoint | Method | Purpose |
|---|---|---|
| `/ai/voice/listen` | POST | A recording in, the words out |
| `/ai/messages/{message}/speech` | GET | A stored answer out, as audio |

| Setting | Default | Variable |
|---|---|---|
| Enabled | `true` | `AI_VOICE_ENABLED` |
| Driver | `openai` | `AI_VOICE_DRIVER` |
| Transcription model | `whisper-1` | `AI_VOICE_TRANSCRIPTION_MODEL` |
| Speech model | `gpt-4o-mini-tts` | `AI_VOICE_SPEECH_MODEL` |
| Voices offered | `alloy,echo,fable,onyx,nova,shimmer` | `AI_VOICE_VOICES` |
| Default voice | `alloy` | `AI_VOICE_DEFAULT` |
| Timeout | 120s | `AI_VOICE_TIMEOUT` |

**No credential is configured for voice.** The provider reads the workspace's existing
OpenAI key through `AiCredentialVault`. A deployment that has already configured OpenAI has
voice; one that has not sees the control disabled with a sentence naming the remedy, and
nothing pretends to listen.

The voice list is an **allow-list, not a suggestion**: the chosen value is interpolated into
a vendor request and comes from a browser, so anything not on the list becomes the default.

Authorization differs between the two endpoints, deliberately:

- `listen` is allowed to **anybody the assistant panel is allowed to, customers included** —
  transcribing reads nothing from the workspace, so the only thing to establish is that this
  person may be talking to the assistant at all. A customer's assistant is read-only, not
  silent.
- `speak` is stricter, because it reads a stored answer: the turn is resolved through
  `visibleTo` **and** `ownedBy`, so it cannot reach a colleague's or an administrator's
  conversation, and only an assistant turn can be spoken.

> [!NOTE]
> Generated audio is **bytes, never a file**. A spoken answer is as private as the answer, so
> the controller streams the bytes in the response to the person's own authorized request and
> leaves nothing behind for a signed URL to leak or a retention policy to forget. There is
> deliberately **no cache**, so the same question asked twice costs the synthesis twice.
>
> `speak` is addressed **by message id rather than by posting text**: what is read aloud is
> what is stored, and a browser that could post the words would be a browser that could make
> the assistant say something it never said.

## AI conversation attachments

Files can be attached to an assistant conversation and are read into the prompt.

| Kind | Extensions | Processor |
|---|---|---|
| `text` | `txt`, `log`, `json`, `yml`, `yaml` | `TextProcessor` |
| `markdown` | `md`, `markdown` | `MarkdownProcessor` |
| `csv` | `csv`, `tsv` | `CsvProcessor` + `CsvAnalyser` |
| `pdf` | `pdf` | `PdfProcessor` (`smalot/pdfparser`) |
| `image` | `png`, `jpg`, `jpeg`, `gif`, `webp` | `ImageProcessor` (GD) |
| `audio` | `mp3`, `m4a`, `wav`, `ogg`, `webm`, `mp4` | `AudioProcessor` + `AudioTranscriber` |
| `document` | `docx`, `xlsx`, `pptx` | `OfficeProcessor` |

Limits are deliberately **their own**, not a reuse of `config('attachments')`: a file
attached to a ticket is stored and served, whereas a file attached to the assistant is
stored, *read*, and put in front of a language model — a wider blast radius, so it gets a
narrower allow-list (no archives, no macro-enabled Office formats) and a different ceiling.

| Limit | Default |
|---|---|
| Per file | 20 MB |
| Per conversation | 10 files |
| Extracted text per file | 40,000 characters |
| Extracted text per conversation | 120,000 characters |
| Digest length | 1,200 characters |
| CSV rows quoted in the prompt | 300 (of 40 columns max) |
| Image longest side | 1,568 px, JPEG quality 82 |

Both the **extension allow-list and the detected MIME type** must pass. A file called
`notes.txt` whose bytes are a PDF is refused, and so is a PDF renamed to `.png`.

### How files reach the model

```diagram
   Upload ──► stored via AttachmentStorage ──► ProcessAiAttachment queued
                                                        │
                        status: pending ──► processing ─┤
                                                        ├──► ready
                                                        ├──► failed
                                                        └──► unsupported
   First turn that uses it:   FULL TEXT
   Every later turn:          DIGEST (name, kind, shape, outline, leading excerpt)
   Unless the question names the filename:  FULL TEXT again
```

Three decisions drive most of the cost and accuracy of an answer about a document:

**Full text once, a digest thereafter.** A conversation about a forty-page specification
would otherwise re-send it on every question, so the tenth follow-up costs ten times the
first for no new information. The escape hatch is naming the file — *"what does
requirements.pdf say about indemnity"* — which is how people naturally ask to go back.

**A budget, spent newest first.** When it runs out, the remaining files still appear as
digests rather than vanishing: a model that is not told a file exists will answer as though
it does not, which is worse than one that knows and says it has not read it. **Every
truncation is stated in the prompt**, because a model handed half a contract and not told
will summarise it as though it read the end.

**Pictures go only to models that can see.** An image is sent as a picture only when the
session's model declares vision in the catalogue. Otherwise it is named and the prompt says
the model cannot see it, so the answer is "I cannot see that image" rather than a confident
invention.

CSV handling is worth calling out: rows are sent as data **and the profile — column types,
ranges, outliers — is computed from *all* of them**, so a question about an average is
answered from every row even when only the first 300 are quoted. The prompt says which is
which, because a model that thinks it has seen every row will state a total it cannot know.

Images are **re-encoded rather than forwarded**. The stored bytes are whatever somebody
uploaded, and handing them to a provider verbatim would make the provider's decoder our
attack surface. GD reads the file, resizes it and writes a fresh JPEG or PNG — so what
leaves the application is a file the application generated, with EXIF (routinely including
GPS coordinates) stripped.

Audio transcription uses the workspace's existing OpenAI credential. With no key configured
an audio upload is **stored and its card says transcription is not configured and who can
configure it** — it does not fail, and it does not silently do nothing.

> [!NOTE]
> An instruction *inside* an uploaded document is framed to the model as **text somebody
> wrote, never a command**. Attachments enter the prompt in the same reference-data block as
> the board context, under the same framing.

`markSent()` is called only **after** the provider has actually answered, so a question that
failed does not consume the one full send its attachment gets.

<!-- pagebreak -->
