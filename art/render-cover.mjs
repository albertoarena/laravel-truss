// Render the social cover / README hero from art/cover.html to
// art/cover-light.png and art/cover-dark.png at 1200x630 @2x (2400x1260).
//
//   node art/render-cover.mjs
//
// Uses the Playwright already installed for the e2e tests, and the package's
// own IBM Plex Mono (served from resources/fonts), so the output stays on-brand
// with the shipped app. No external assets, no CDN.
import { chromium } from '@playwright/test'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { join, extname } from 'node:path'

const root = fileURLToPath(new URL('..', import.meta.url))
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.woff2': 'font/woff2',
  '.css': 'text/css',
  '.png': 'image/png',
}

const server = createServer(async (req, res) => {
  try {
    const path = join(root, decodeURIComponent(req.url.split('?')[0]))
    const body = await readFile(path)
    res.writeHead(200, { 'content-type': MIME[extname(path)] ?? 'application/octet-stream' })
    res.end(body)
  } catch {
    res.writeHead(404)
    res.end('not found')
  }
})

await new Promise((resolve) => server.listen(0, resolve))
const port = server.address().port

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 2 })

for (const theme of ['light', 'dark']) {
  await page.goto(`http://localhost:${port}/art/cover.html`, { waitUntil: 'networkidle' })
  await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme)
  await page.waitForTimeout(300) // let the webfont settle
  await page.screenshot({ path: join(root, `art/cover-${theme}.png`) })
  console.log(`rendered art/cover-${theme}.png`)
}

await browser.close()
server.close()
