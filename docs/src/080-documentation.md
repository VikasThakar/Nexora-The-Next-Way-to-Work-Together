# Documentation System

## Purpose

Each board carries its own documentation workspace: a nested tree of pages with a live
WYSIWYG editor, per-page publication to customers, attachments, and a contents list. It is
where runbooks, architecture notes, decisions and customer-facing guides live next to the
work they describe.

## Layout

Three columns:

```diagram
  +---------------+  +--------------------------------+  +---------------+
  |  PAGE TREE    |  |  DOCUMENT                      |  | ON THIS PAGE  |
  |               |  |                                |  |               |
  |  > Runbooks   |  |  Title (inline editable)       |  |  Overview     |
  |    Deploying  |  |                                |  |  Prereqs      |
  |    Rollback   |  |  Rich-text body, editing live  |  |  Steps        |
  |  > Decisions  |  |  for anyone who may edit it    |  |  Rollback     |
  |    ADR-001    |  |                                |  |               |
  |  Onboarding   |  |  [lock] internal / published   |  |  built from   |
  |               |  |                                |  |  the DOM      |
  |  drag to      |  |  autosaves as you type         |  |               |
  |  reorder      |  |                                |  |               |
  +---------------+  +--------------------------------+  +---------------+
```

One Livewire component (`App\Livewire\Docs\Show`) serves both `/boards/{board}/docs` and
`/boards/{board}/docs/{slug}`, because the tree is on screen either way and reordering has
to work from both. A separate index component would mean two copies of the drag-and-drop
handler — exactly the kind of duplication that lets one copy forget a check.

## Page hierarchy

| Property | Detail |
|---|---|
| Parent | `parent_id`, self-referencing, `restrictOnDelete` |
| Maximum depth | **4** (`DocPage::MAX_DEPTH`); root pages are depth 0 |
| Ordering | `position`, drag-and-drop, within each parent |
| Slug | Unique per board; used in the URL, never the primary key |
| Icon | An optional emoji, chosen from a picker (`icon`, 16 chars) |

The depth bound exists so that ancestor walks terminate in a known number of queries, and so
the sidebar stays legible.

## The ancestor visibility rule

A tree needs one rule a flat table does not: **a page is visible only when it and every one
of its ancestors are visible.** Publishing a child of an internal page would otherwise show
a customer a page whose parent, path and siblings they must not see — and the breadcrumb
alone would leak the internal titles.

That rule is enforced in three deliberately overlapping places:

| When | What happens |
|---|---|
| **On write** | `SetPageVisibility` refuses to publish a page whose parent is internal, and cascades "make internal" down the whole subtree. `MovePage` refuses a move that would put a published page under an internal one |
| **On read of one page** | `DocPageFinder` walks the ancestor chain through the visibility scope and 404s if any link is missing |
| **On read of the tree** | `DocPageFinder` drops any node whose parent is not in the visible set, so a broken invariant cannot surface as an orphan |

> [!IMPORTANT]
> The two visibility operations are deliberately **asymmetric**. Publishing is *refused*
> when an ancestor is internal — rather than silently publishing the ancestors too — because
> exposing a parent page is the author's decision, not a side effect. Un-publishing
> *cascades* down the whole subtree, because anything else would leave published children
> hanging off an internal parent. Retracting always fails closed: more is hidden, never
> less.

For staff the ancestor walk is skipped entirely, since a member of the board can see every
page on it. For a customer it costs at most four extra lookups.

## The editor is not a mode

Anybody who may edit a page gets the editor **immediately, on the page they opened**. There
is no Edit button and no read-only step in front of it.

The reasoning is in the code: a documentation tool whose default state is "looking at"
rather than "working on" makes every small correction a four-click job, which is how
documentation goes stale. A reader who may not edit gets the rendered document, and never so
much as the markup of a control they could not use.

The editor is **TipTap** (ProseMirror), registered as an Alpine component. It is headless —
no stylesheet to fight Tailwind with, no theme, no toolbar markup — so the toolbar is
ordinary Blade buttons styled like every other button in the product. It is
framework-agnostic vanilla JavaScript, so no React or Vue enters the build.

Supported: headings, bold, italic, underline, strikethrough, inline code, code blocks with
syntax highlighting, links, bullet and ordered lists, task lists, block quotes, horizontal
rules, images and tables.

> [!NOTE]
> **Markdown is the storage format.** The WYSIWYG editor is a surface over Markdown, and the
> HTML-to-Markdown conversion is done **server-side** (`league/html-to-markdown` via
> `App\Support\RichText`). The stored artefact is always `body_md`. This keeps documents
> readable, searchable, diffable and exportable without the editor, and means a future
> change to how documents are rendered cannot alter what was stored.

## Autosave, drafts and publishing

Where an autosave lands depends on **who can read the page**, and this is the load-bearing
decision of the whole editor.

```diagram
                        Author types
                             |
                             v
                  Is the page customer-visible?
                             |
             +---------------+----------------+
             | no                             | yes
             v                                v
   INTERNAL PAGE                      PUBLISHED PAGE
   autosave -> body_md                autosave -> draft_md
   via UpdatePage                     via SaveDraft
             |                                |
   Committed immediately.             Held until somebody presses
   Nobody outside the team            Publish. A page a customer is
   can read it, so there is           reading changes when its author
   no audience to show                says so, not between keystrokes.
   half-written prose to.                     |
                                              v
                                     save() moves draft_md into
                                     body_md, records the edit,
                                     tells other clients, discards
                                     the draft.
```

Supporting columns: `draft_title`, `draft_md`, `draft_saved_at`, `draft_by_id`.

`DocPage::hasDraft()` uses `draft_saved_at` as the marker rather than `draft_md`, so a draft
that deliberately empties a document still counts as unsaved work. A draft identical to what
is saved does not count, so no recovery banner appears over nothing.

Two properties make autosaving on a timer safe:

- `UpdatePage` returns early unless the row is actually dirty;
- `ActivityLogger` coalesces an editing session into **one feed entry per person per page**.

An afternoon's typing is therefore a handful of writes and one line in the activity feed.

> [!NOTE]
> **Autosave never broadcasts**, on either branch. A colleague reading the board should not
> have their screen pulled out from under them every few seconds because somebody two desks
> away is typing.

## Contents list ("On this page")

Built from the **DOM**, not from the Markdown — which is what lets one implementation serve
both states of the page. A reader is looking at rendered HTML; a writer is looking at a live
ProseMirror document that has no server-side representation between keystrokes. Both are
headings in an element.

Headings are addressed **by position, not by id**. Inside the editor ProseMirror owns the
DOM and reconciles away attributes it did not put there, so ids written by the script are
silently stripped and every anchor would resolve to nothing. A heading is therefore
identified by its index among the headings, and the click handler looks the element up
again at click time.

A contents list appears only when the document has at least 3 headings. Nothing about it is
persisted or sent anywhere.

## Permissions

| Action | Administrator | Team member | Customer |
|---|---|---|---|
| Open the documentation section | Yes | Member only | Member only |
| Read a published page | Yes | Yes | Yes — if every ancestor is published |
| Read an internal page | Yes | Member only | **No — 404** |
| Create a page | Yes | Member only | **No** |
| Edit a page | Yes | Member only | **No** |
| Move or reorder a page | Yes | Member only | **No** |
| Delete a page | Yes | Member only | **No** |
| Publish or retract a page | Yes | Member only | **No** |
| Attach a file | Yes | Member only | **No** |
| Import a file as a page | Yes | Member only | **No** |

Documentation is the delivery team's material, which they publish to customers a page at a
time. **A customer never authors it.**

## Importing files as pages

A file somebody already has — a to-do list, a bug list, meeting notes, a spec — becomes a page
without retyping it.

| Where | How |
|---|---|
| Sidebar | **Import file** beside **+ New page**, and the whole sidebar takes a dropped file |
| Empty documentation | **Import a file** beside **Create your first page**, and the card takes a dropped file |
| A page's **More** menu | **Import a file inside this one** files the new page under it |
| The assistant | "Import this into the docs and make the tickets" — section 9 |

Each file becomes an **internal page** (`App\Actions\Docs\ImportDocument`), its title the
document's own (a leading heading, Word's Title, YAML front matter, a text file's opening line)
or else the file name, and **the original is attached to the page** — so anything a conversion
could not keep is one click away. One file that cannot be read is named in the sidebar, and the
rest still import.

`App\Services\Docs\Import\DocumentImporter` turns each format into the Markdown a page stores,
keeping the structure a to-do or bug list is made of:

| Format | What is kept |
|---|---|
| Word `.docx` | Headings (from the styles, found by name or outline level), bullet and numbered lists with nesting, checkbox controls and box bullets as to-dos, bold/italic/strike, web links, tables, footnotes. Pictures are counted and left in the original |
| PDF | The text, with running headers, footers and page numbers removed and wrapped lines joined into paragraphs; bullets and `[ ]` / `☐` as lists and to-dos. A scan (no text layer) is refused with a reason |
| Text `.txt` | Bullets, numbering, checkboxes, `Bugs:`-style and capitalised headings, the author's line breaks |
| Markdown | As written, less YAML front matter |
| CSV / TSV | A table (delimiter detected), first 500 rows |
| Excel `.xlsx` | A heading and a table per sheet, first 500 rows × 30 columns |
| PowerPoint `.pptx` | A heading per slide, its points nested by level, speaker notes as a quote |

The legacy `.doc`, `.xls` and `.ppt` are refused with "save it as .docx" — they are binary
formats and reading them would mean a new parser for untrusted input.

> [!IMPORTANT]
> **Safety.** Office files are ZIPs of XML: they are read inside the existing zip-bomb limits
> (`App\Support\Ooxml\OoxmlPackage`) with a streaming reader that refuses any part declaring a
> document type and loads no DTD or entity (`App\Support\Xml\SafeXml`), so the XXE class of attack
> does not arise. The type is decided by the extension **and** checked against the bytes. Output is
> escaped for the renderer — `<` and `++` would otherwise vanish or turn into underline — and written
> in the editor's own Markdown forms, so opening an imported page in the editor rewrites nothing.

**Limits** (`config/attachments.php`, `imports`): 10 files per drop, 20 MB each; a body holds
200,000 characters — past that it is cut at a paragraph and says the rest is in the original. A
file over 8 MB is converted on the attachments queue (`ImportDocumentFile`) while the sidebar
shows *Importing…*; a drop larger than the server's request limit goes up in batches.

### Create tickets with AI

A page with open to-dos shows **Create tickets with AI · N to-dos** in its header (and the item is
in the **More** menu on every page). It opens the assistant on the page's board, in Writing, with
"Create tickets for the to-dos and bugs on the page …" written in the composer — nothing is sent
until the person presses Send. The page's text reaches the model as *THE PAGE ON SCREEN*; tickets
from an internal page start internal and link back to it. Offered only to somebody the assistant
would let propose changes there.

## Attachments

Pages carry polymorphic attachments through the same pipeline as tickets and comments, with
the same limits and the same authorization — a file on an unpublished page is internal
because the page is. An imported page's original file is one of them.

## Search

Documentation is one of the four command-palette categories, searched by title and body
through `DocPageFinder` so the ancestor rule is applied to results as well as to pages.

## What is not implemented

| Capability | Status |
|---|---|
| Page version history / revisions | **Not implemented** — only the current body and one draft are stored |
| Page comments | **Not implemented** |
| Cross-board page links as first-class relations | **Not implemented** — ordinary Markdown links work |
| Templates | **Not implemented** |
| Export a page or space to PDF/Word | **Not implemented** |
| Real-time collaborative editing (multiple cursors) | **Not implemented** — autosave is single-author |

<!-- pagebreak -->
