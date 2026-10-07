/*
 * Screenshot individual paginated pages, so the layout can be inspected without
 * a PDF viewer. Usage:  node preview.mjs 1 2 3 12
 */
import { existsSync, mkdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import puppeteer from 'puppeteer-core'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = join(HERE, 'preview')
if (!existsSync(OUT)) mkdirSync(OUT, { recursive: true })

const wanted = process.argv.slice(2).map(Number).filter(Boolean)
if (wanted.length === 0) wanted.push(1, 2, 3)

const candidates = [
  process.env.NEXORA_DOC_BROWSER,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].filter(Boolean)
const executablePath = candidates.find((p) => existsSync(p))

const browser = await puppeteer.launch({
  executablePath,
  headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
})

const page = await browser.newPage()
await page.setViewport({ width: 1240, height: 1754, deviceScaleFactor: 1.5 })
await page.goto(pathToFileURL(join(HERE, 'render.html')).href, {
  waitUntil: 'networkidle0',
  timeout: 180000,
})
await page.waitForFunction(() => document.querySelector('.pagedjs_page') !== null, {
  timeout: 180000,
})
await new Promise((r) => setTimeout(r, 1500))

const total = await page.evaluate(() => document.querySelectorAll('.pagedjs_page').length)
console.log(`total pages: ${total}`)

for (const n of wanted) {
  if (n < 1 || n > total) continue
  const el = await page.$(`.pagedjs_page:nth-of-type(${n})`)
  if (!el) {
    console.log(`page ${n}: element not found`)
    continue
  }
  const file = join(OUT, `page-${String(n).padStart(3, '0')}.png`)
  await el.screenshot({ path: file })
  console.log(`page ${n} -> ${file}`)
}

await browser.close()
