/*
 * Offline PDF generator for the Nexora system documentation.
 *
 * Pipeline:
 *
 *   docs/src/*.md  ->  concatenate  ->  markdown-it  ->  post-process
 *                  ->  HTML + print CSS  ->  Paged.js (in-browser pagination)
 *                  ->  Chrome/Edge (headless, already installed)  ->  PDF
 *
 * Nothing here calls an online service. Paged.js polyfills CSS Paged Media so
 * the table of contents can carry real page numbers via target-counter(), and
 * so running headers pick up the current section title.
 *
 * Usage:  node build.mjs [--html-only] [--keep]
 */

import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import MarkdownIt from 'markdown-it'
import puppeteer from 'puppeteer-core'

const HERE = dirname(fileURLToPath(import.meta.url))
const DOCS = resolve(HERE, '..')
const SRC = join(DOCS, 'src')
const OUT_PDF = join(DOCS, 'Nexora-Complete-System-Documentation.pdf')
const OUT_MD = join(DOCS, 'Nexora-Complete-System-Documentation.md')
const OUT_HTML = join(HERE, 'render.html')

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

  for (const path of candidates) {
    if (existsSync(path)) return path
  }
  throw new Error(
    'No Chromium-based browser found. Set NEXORA_DOC_BROWSER to a Chrome or Edge executable.'
  )
}

/* ------------------------------------------------------------------ */
/* Source                                                              */
/* ------------------------------------------------------------------ */

const parts = readdirSync(SRC).filter((f) => f.endsWith('.md')).sort()
if (parts.length === 0) throw new Error(`No Markdown sources found in ${SRC}`)

const rawParts = parts.map((f) => readFileSync(join(SRC, f), 'utf8'))
const combined = rawParts.join('\n\n')

// Front matter lives only at the top of the first file.
const fmMatch = combined.match(/^---\n([\s\S]*?)\n---\n/)
const meta = {}
if (fmMatch) {
  for (const line of fmMatch[1].split('\n')) {
    const i = line.indexOf(':')
    if (i > 0) meta[line.slice(0, i).trim()] = line.slice(i + 1).trim()
  }
}
const body = fmMatch ? combined.slice(fmMatch[0].length) : combined

// The synchronised Markdown deliverable keeps the front matter.
writeFileSync(OUT_MD, combined.replace(/\n{3,}/g, '\n\n'), 'utf8')
console.log(`  Markdown  ${OUT_MD}`)

/* ------------------------------------------------------------------ */
/* Markdown -> HTML                                                    */
/* ------------------------------------------------------------------ */

const md = new MarkdownIt({ html: true, linkify: false, typographer: false })

// ```diagram fences render as figure boxes rather than code blocks.
const defaultFence = md.renderer.rules.fence
md.renderer.rules.fence = (tokens, idx, options, env, self) => {
  const token = tokens[idx]
  if ((token.info || '').trim() === 'diagram') {
    return `<div class="diagram">${md.utils.escapeHtml(token.content.replace(/\s+$/, ''))}</div>\n`
  }
  return defaultFence(tokens, idx, options, env, self)
}

// Tables get a wrapper so they can be constrained independently.
md.renderer.rules.table_open = () => '<div class="table-wrap"><table>'
md.renderer.rules.table_close = () => '</table></div>'

let html = md.render(body)

/* ------------------------------------------------------------------ */
/* Post-processing                                                     */
/* ------------------------------------------------------------------ */

// GitHub-style callouts: > [!NOTE] / [!IMPORTANT] / [!WARNING] / [!TIP]
const CALLOUTS = {
  NOTE: { cls: '', label: 'Note' },
  TIP: { cls: '', label: 'Tip' },
  IMPORTANT: { cls: 'callout--important', label: 'Important' },
  WARNING: { cls: 'callout--warning', label: 'Warning' },
}

html = html.replace(
  /<blockquote>\s*<p>\[!(NOTE|TIP|IMPORTANT|WARNING)\]\s*(?:<br\s*\/?>)?\s*([\s\S]*?)<\/blockquote>/g,
  (_m, kind, inner) => {
    const { cls, label } = CALLOUTS[kind]
    return `<div class="callout ${cls}"><span class="callout__label">${label}</span><p>${inner}</div>`
  }
)

// Explicit page breaks.
html = html.replace(/<!--\s*pagebreak\s*-->/g, '<div class="pagebreak"></div>')
html = html.replace(/<!--\s*cover\s*-->/g, '')

/* ------------------------------------------------------------------ */
/* Section numbering and the table of contents                         */
/* ------------------------------------------------------------------ */

let h1 = 0
let h2 = 0
const toc = []

html = html.replace(/<h([12])>([\s\S]*?)<\/h\1>/g, (_m, levelStr, inner) => {
  const level = Number(levelStr)
  const text = inner.replace(/<[^>]+>/g, '').trim()

  if (level === 1) {
    h1 += 1
    h2 = 0
    const id = `s${h1}`
    toc.push({ level: 1, num: String(h1), text, id })
    // The eyebrow sits OUTSIDE the h1 so that `string-set: section-title
    // content(text)` captures the title alone — inside, the running header
    // would read "Section 3System Architecture".
    return `<p class="section-eyebrow">Section ${h1}</p><h1 id="${id}">${inner}</h1>`
  }

  h2 += 1
  const num = `${h1}.${h2}`
  const id = `s${h1}-${h2}`
  toc.push({ level: 2, num, text, id })
  return `<h2 id="${id}"><span class="h-num">${num}</span>${inner}</h2>`
})

const tocHtml = `
<section class="toc">
  <h1 class="toc__heading" style="border:none;padding:0;margin-bottom:1.5mm;">Contents</h1>
  <p class="toc__sub">Nexora — Complete System Documentation · ${toc.filter((t) => t.level === 1).length} sections</p>
  <ol>
    ${toc
      .map(
        (t) => `<li class="toc__l${t.level}"><a href="#${t.id}">
        <span class="toc__num">${t.num}</span><span class="toc__text">${t.text}</span><span class="toc__dots"></span></a></li>`
      )
      .join('\n    ')}
  </ol>
</section>`

/* ------------------------------------------------------------------ */
/* Cover                                                               */
/* ------------------------------------------------------------------ */

const coverHtml = `
<section class="cover">
  <div class="cover__mark"><span class="cover__glyph">N</span><span>Nexora Platform</span></div>
  <div class="cover__body">
    <h1 class="cover__title">${meta.title || 'NEXORA'}</h1>
    <p class="cover__tagline">${meta.subtitle || ''}</p>
    <div class="cover__rule"></div>
    <p class="cover__doctype">${meta.doctype || 'Complete System Documentation'}</p>
    <dl class="cover__meta">
      <div><dt>Version</dt><dd>${meta.version || '—'}</dd></div>
      <div><dt>Documentation date</dt><dd>${meta.date || '—'}</dd></div>
      <div><dt>System status</dt><dd>${meta.status || '—'}</dd></div>
      <div><dt>Source commit</dt><dd>${meta.commit || '—'}</dd></div>
    </dl>
    <p class="cover__foot">
      Laravel 12 · Livewire 4 · Tailwind CSS 4 · MySQL · Reverb · Anthropic &amp; OpenAI ·
      GitHub · Slack · Docker on Railway
    </p>
  </div>
</section>`

const css = readFileSync(join(HERE, 'style.css'), 'utf8')
const pagedJs = readFileSync(join(HERE, 'node_modules/pagedjs/dist/paged.polyfill.js'), 'utf8')

const document = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Nexora — Complete System Documentation</title>
<style>${css}</style>
</head>
<body>
${coverHtml}
${tocHtml}
<main>
${html}
</main>
<script>${pagedJs}</script>
</body>
</html>`

writeFileSync(OUT_HTML, document, 'utf8')
console.log(`  HTML      ${OUT_HTML}`)

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

try {
  const page = await browser.newPage()
  page.on('pageerror', (e) => console.warn('  ! page error:', e.message))

  await page.goto(pathToFileURL(OUT_HTML).href, { waitUntil: 'networkidle0', timeout: 180000 })

  // Paged.js sets class="pagedjs_clearfix" work aside; wait for its completion flag.
  await page.waitForFunction(
    () => window.PagedPolyfill === undefined || document.querySelector('.pagedjs_pages') !== null,
    { timeout: 180000 }
  )
  // Give the layout engine a moment to settle the final page.
  await new Promise((r) => setTimeout(r, 1500))

  const pageCount = await page.evaluate(
    () => document.querySelectorAll('.pagedjs_page').length
  )

  await page.pdf({
    path: OUT_PDF,
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: false,
    timeout: 180000,
  })

  console.log(`  Pages     ${pageCount}`)
  console.log(`  PDF       ${OUT_PDF}`)
} finally {
  await browser.close()
  if (!args.has('--keep')) {
    // render.html is an intermediate; keep it only when asked.
  }
}
