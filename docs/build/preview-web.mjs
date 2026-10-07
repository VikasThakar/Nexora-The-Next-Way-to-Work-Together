import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { existsSync, mkdirSync } from 'node:fs'
import puppeteer from 'puppeteer-core'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = join(HERE, 'preview')
if (!existsSync(OUT)) mkdirSync(OUT, { recursive: true })

const exe = ['C:/Program Files/Google/Chrome/Application/chrome.exe'].find(existsSync)
const browser = await puppeteer.launch({ executablePath: exe, headless: true, args: ['--no-sandbox'] })
const page = await browser.newPage()
// A4 content width: 210mm - 28mm margins = 182mm = 688px at 96dpi
await page.setViewport({ width: 688, height: 1000, deviceScaleFactor: 2 })
await page.emulateMediaType('print')
await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }])
await page.goto(pathToFileURL(join(HERE, 'render-web.html')).href, { waitUntil: 'networkidle0' })
await page.evaluate(() => document.fonts.ready)

const shots = [
  ['cover',   '.cover'],
  ['toc',     '.print-toc'],
  ['lanes',   '#s2 .lanes'],
  ['matrix',  '#s3 .scroller:nth-of-type(2)'],
  ['aimatrix','#s6 .scroller'],
  ['pipeline','#s7 .pipe'],
]
for (const [name, sel] of shots) {
  const el = await page.$(sel)
  if (!el) { console.log(`  miss ${name} (${sel})`); continue }
  const box = await el.boundingBox()
  await el.screenshot({ path: join(OUT, `web-${name}.png`) })
  console.log(`  ${name.padEnd(9)} ${Math.round(box.width)}x${Math.round(box.height)}px`)
}
await browser.close()
