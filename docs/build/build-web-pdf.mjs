/*
 * PDF generator for the web edition of the Nexora system documentation.
 *
 * Pipeline:
 *
 *   docs/Nexora-System-Documentation.html   (the published artifact, verbatim)
 *        -> wrap as a complete print document
 *        -> force the light palette, drop the sticky contents rail
 *        -> insert a paginated contents page
 *        -> Chrome/Edge (headless, already installed) -> A4 PDF
 *
 * The source HTML is the artifact itself, so the PDF and the shared link cannot
 * drift: this script never rewrites content, only the shell around it.
 *
 * Usage:  node build-web-pdf.mjs [--html-only]
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import puppeteer from 'puppeteer-core'

const HERE = dirname(fileURLToPath(import.meta.url))
const DOCS = resolve(HERE, '..')
const SRC = join(DOCS, 'Nexora-System-Documentation.html')
const OUT_PDF = join(DOCS, 'Nexora-System-Documentation.pdf')
const OUT_HTML = join(HERE, 'render-web.html')

const args = new Set(process.argv.slice(2))

/* ------------------------------------------------------------------ */
/* Locate a Chromium-based browser already installed on this machine.  */
/* ------------------------------------------------------------------ */

function findBrowser() {
  const candidates = [
    process.env.NEXORA_DOC_BROWSER,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ].filter(Boolean)

  for (const path of candidates) if (existsSync(path)) return path

  throw new Error('No Chromium-based browser found. Set NEXORA_DOC_BROWSER to a Chrome or Edge executable.')
}

/* ------------------------------------------------------------------ */
/* Source                                                              */
/* ------------------------------------------------------------------ */

if (!existsSync(SRC)) throw new Error(`Missing source: ${SRC}`)
const artifact = readFileSync(SRC, 'utf8')

// The artifact is a fragment: head-ish elements, then the page. Split at the
// end of its stylesheet so the print layer can be appended after it and win.
const splitAt = artifact.indexOf('</style>')
if (splitAt < 0) throw new Error('Could not find the artifact stylesheet.')

const head = artifact.slice(0, splitAt + '</style>'.length)
const rest = artifact.slice(splitAt + '</style>'.length)

/* ------------------------------------------------------------------ */
/* Contents page, derived from the artifact's own navigation           */
/* ------------------------------------------------------------------ */

const entries = [...artifact.matchAll(
  /<a href="#(s\d+)"><span class="n">(\d+)<\/span><span>([^<]+)<\/span><\/a>/g
)].map((m) => ({ id: m[1], n: m[2], title: m[3] }))

if (entries.length === 0) throw new Error('Could not derive the contents from the artifact navigation.')

const tocHtml = `
<nav class="print-toc" aria-label="Contents">
  <h2>Contents</h2>
  <ol>
${entries.map((e) => `    <li><span class="tn">${e.n}</span><a href="#${e.id}">${e.title}</a></li>`).join('\n')}
  </ol>
  <p class="toc-foot">Every entry is a live link. Section&nbsp;15 collects the implementation status of
  every capability discussed; section&nbsp;16 lists the findings raised while compiling this document.</p>
</nav>`

// The cover closes with </header>; the contents belongs immediately after it.
const headerEnd = rest.indexOf('</header>')
if (headerEnd < 0) throw new Error('Could not find the end of the cover block.')

const bodyHtml =
  rest.slice(0, headerEnd + '</header>'.length) +
  tocHtml +
  rest.slice(headerEnd + '</header>'.length)

/* ------------------------------------------------------------------ */
/* Print layer                                                         */
/* ------------------------------------------------------------------ */

const printCss = `
/* ---- Shell: the sticky rail is a screen affordance, not a page ---- */
.rail { display: none !important; }
.shell { display: block; max-width: none; margin: 0; }
.doc { padding: 0; }
body { background: #fff; }

/* ---- Contents page ---- */
.print-toc { padding-top: 6mm; }
.print-toc h2 {
  font-family: var(--sans); font-size: 21pt; letter-spacing: -0.025em; font-weight: 600;
  padding-bottom: 8px; border-bottom: 1px solid var(--rule); margin-bottom: 14px;
}
.print-toc ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.print-toc li {
  display: grid; grid-template-columns: 34px 1fr; gap: 10px; align-items: baseline;
  padding: 7px 0; border-bottom: 1px solid var(--rule);
}
.print-toc .tn { font-family: var(--mono); font-size: 10pt; font-weight: 600; color: var(--brand); }
.print-toc a {
  font-family: var(--sans); font-size: 12pt; color: var(--ink); text-decoration: none; letter-spacing: -0.008em;
}
.print-toc .toc-foot {
  font-family: var(--sans); font-size: 9pt; color: var(--ink-3); margin-top: 16px; max-width: 74ch; line-height: 1.5;
}

@media print {
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-size: 9.8pt; line-height: 1.52; }

  /* ---- Pagination ---- */
  .cover { border-bottom: none; padding: 10mm 0 0; break-after: page; }
  .print-toc { break-after: page; }
  .sec { break-before: page; padding-top: 0; }
  .colophon { break-before: page; margin-top: 0; }

  /* ---- Keep composed objects whole ---- */
  .note, .card, .layer, .step, .fig, .lanes, .lane-row, .figures, .meta,
  .gloss > div, .pipe, .cap { break-inside: avoid; }
  tr, thead { break-inside: avoid; }
  thead { display: table-header-group; }
  h2, h3, h4, .sec-num, .deck { break-after: avoid; }

  /* ---- Tables: no horizontal scroller on paper ---- */
  .scroller { overflow: visible !important; }
  table { min-width: 0 !important; font-size: 8.3pt; }
  thead th { font-size: 7.4pt; padding: 8px 9px; }
  tbody td, tbody th { padding: 7px 9px; }

  /* ---- Type, pinned rather than fluid, so A4 is deliberate ---- */
  .cover h1 { font-size: 48pt; }
  .cover .subtitle { font-size: 16pt; }
  .cover .lede { font-size: 12.5pt; }
  .doctype { font-size: 10pt; }
  .sec h2 { font-size: 21pt; }
  .deck { font-size: 11.5pt; }
  .sec h3 { font-size: 13.5pt; }
  .sec h4 { font-size: 9.5pt; }
  .fig .v { font-size: 20pt; }
  .fig .k { font-size: 8.5pt; }
  .card h5 { font-size: 10.5pt; }
  .card p, .layer .ds, .step .sb { font-size: 9pt; }
  .note { font-size: 9.8pt; }
  .cap { font-size: 9pt; }
  .gloss dt { font-size: 9.8pt; }
  .gloss dd { font-size: 8.8pt; }
  .colophon { font-size: 9pt; }

  /* ---- Links read as text on paper, but stay live in the file ---- */
  .sec a, .colophon a { color: var(--ink); }
}
`

/* ------------------------------------------------------------------ */
/* Assemble                                                            */
/* ------------------------------------------------------------------ */

const document = `<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
${head}
<style>${printCss}</style>
</head>
<body>
${bodyHtml}
</body>
</html>`

writeFileSync(OUT_HTML, document, 'utf8')
console.log(`  HTML      ${OUT_HTML}`)
console.log(`  Sections  ${entries.length}`)

if (args.has('--html-only')) {
  console.log('\n  --html-only: stopping before PDF render.')
  process.exit(0)
}

/* ------------------------------------------------------------------ */
/* Render                                                              */
/* ------------------------------------------------------------------ */

const executablePath = findBrowser()
console.log(`  Browser   ${executablePath}`)

const browser = await puppeteer.launch({
  executablePath,
  headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
})

const footer = `
<div style="width:100%;box-sizing:border-box;padding:0 14mm;font-family:Arial,Helvetica,sans-serif;
            font-size:7pt;color:#8a909c;display:flex;justify-content:space-between;align-items:center;">
  <span>Nexora &mdash; Complete System Documentation &middot; commit 30bfc83</span>
  <span class="pageNumber"></span>
</div>`

try {
  const page = await browser.newPage()
  page.on('pageerror', (e) => console.warn('  ! page error:', e.message))

  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }])
  await page.goto(pathToFileURL(OUT_HTML).href, { waitUntil: 'networkidle0', timeout: 180000 })

  // Web fonts decide line breaks, so pagination must wait for them.
  await page.evaluate(() => document.fonts.ready)
  await new Promise((r) => setTimeout(r, 600))

  const loadedPlex = await page.evaluate(() => document.fonts.check('600 16px "IBM Plex Sans"'))
  console.log(`  Webfonts  ${loadedPlex ? 'loaded' : 'FELL BACK to system faces (no network?)'}`)

  await page.pdf({
    path: OUT_PDF,
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: footer,
    margin: { top: '14mm', right: '14mm', bottom: '15mm', left: '14mm' },
    timeout: 180000,
  })

  console.log(`  PDF       ${OUT_PDF}`)
} finally {
  await browser.close()
}
