# Nexora documentation

This directory holds the complete system documentation and the tooling that produces it.

There are **two editions**, built by two independent pipelines from two different sources.

## Web edition — for sending to somebody

| Path | What it is |
|---|---|
| `Nexora-System-Documentation.pdf` | **The deliverable.** 51 pages, A4 |
| `Nexora-System-Documentation.html` | **The source of truth for this edition.** Also published as a shareable link |
| `build/build-web-pdf.mjs` | The generator — wraps the HTML above for print and renders it |
| `build/preview-web.mjs` | Screenshots individual components in print mode, for checking layout |

17 numbered sections covering scope, the customer boundary, permission matrices, every
functional module, architecture, security, the data model, deployment, testing, implementation
status and findings. Written for somebody deciding what the product does and what state it is
in, rather than for somebody about to change the code.

The HTML is the source and the PDF is generated from it, so the shared link and the PDF cannot
drift. Edit the HTML, then rebuild.

## Reference edition — for working on the code

| Path | What it is |
|---|---|
| `Nexora-Complete-System-Documentation.pdf` | 168 pages, A4 |
| `Nexora-Complete-System-Documentation.md` | The same document as Markdown, generated from `src/` |
| `src/*.md` | **The source of truth for this edition.** Edit these, never the generated files |
| `build/build.mjs` | The generator |
| `build/style.css` | Print stylesheet |
| `build/preview.mjs` | Renders individual pages to PNG, for checking layout |

The exhaustive version: every environment variable, every table, every index, every refusal
path. Both editions describe the same system at the same commit; they differ in depth, not in
claims.

## Rebuilding

```bash
cd docs/build
npm install               # first time only: markdown-it, puppeteer-core, pagedjs

node build-web-pdf.mjs    # web edition:       HTML -> PDF
node build.mjs            # reference edition: src/*.md -> PDF + Markdown
```

`build.mjs` regenerates both the PDF and the Markdown, so the two cannot drift.
`build-web-pdf.mjs` never rewrites content — it only changes the shell around it: it forces the
light palette, drops the sticky contents rail, inserts a paginated contents page derived from
the document's own navigation, and adds a running footer.

The build is entirely offline. It drives a Chrome or Edge installation that is already on the
machine — nothing is downloaded at build time and no online service is contacted. Set
`NEXORA_DOC_BROWSER` to override the browser path.

| Flag | Effect |
|---|---|
| `--html-only` | Stop after writing `render.html`; skip the PDF |

To check a page visually without a PDF viewer:

```bash
node preview.mjs 1 2 17 60      # writes build/preview/page-NNN.png
```

## How it works

```
src/*.md  ->  concatenate (filename order)  ->  markdown-it
          ->  post-process  ->  HTML + print CSS
          ->  Paged.js (paginates in the browser)
          ->  headless Chrome  ->  PDF
```

Paged.js polyfills CSS Paged Media, which is what makes the table of contents carry **real
page numbers** (`target-counter()`) and the running header pick up the current section title
(`string-set`). Chrome's own `--print-to-pdf` cannot do either.

## Source conventions

| Convention | Meaning |
|---|---|
| `# Heading` | A numbered section. Starts a new page, sets the running header |
| `## Heading` | A numbered subsection (`3.2`) |
| `### `, `#### ` | Unnumbered |
| ` ```diagram ` | Rendered as a bordered figure box in monospace, not as code |
| `> [!NOTE]` / `[!TIP]` / `[!IMPORTANT]` / `[!WARNING]` | Callout boxes |
| `<!-- pagebreak -->` | Forces a page break |
| YAML front matter | Only in `010-front-matter.md`; drives the cover |

Section and subsection numbers are assigned by the build script from document order, so
inserting a section renumbers everything automatically. **Cross-references in prose are
written by hand** — check them after reordering.

Files are concatenated in filename order, which is why they are numbered in tens.

## Editorial standard

This document records what is **actually implemented** at the commit named on its cover.
Anything incomplete is marked *Partially Implemented*, anything absent is marked *Planned /
Not Yet Implemented*, and section 32 collects every such marking in one table. Please keep
that standard: a documentation set that overstates the product is worse than none.
