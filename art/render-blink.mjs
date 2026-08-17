// Render the animated Truss brand mark (static lockup, animated tagline) from
// art/blink.html into art/blink-dark.gif and art/blink-light.gif, plus an 800px
// cut of each for inline use.
//
//   node art/render-blink.mjs
//   node art/render-blink.mjs variants   # every treatment, into art/variants/, for choosing
//
// Frames are screenshotted one by one with window.__frame(i) driving the
// animation, so the timing is exact and does not depend on CSS animation clocks.
// ffmpeg builds the GIF with a generated palette, which keeps the flat brand
// colours and the mono type clean. Fonts come from the package's own
// resources/fonts, same as render-cover.mjs, so the output stays on-brand with
// the shipped app: no CDN, no external asset.
import { chromium } from '@playwright/test'
import { createServer } from 'node:http'
import { readFile, mkdir, rm } from 'node:fs/promises'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { join, extname, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const run = promisify(execFile)
const here = dirname(fileURLToPath(import.meta.url))
const repo = fileURLToPath(new URL('..', import.meta.url)) // this script lives in art/
const out = here
const frames = join(here, 'frames')

const WIDTH = 1200
const HEIGHT = 675
const SCALE = 2 // render at 2x, downscale with lanczos so the mono type stays crisp
const DELAY_MS = 80 // 12.5 fps

const MIME = { '.html': 'text/html; charset=utf-8', '.woff2': 'font/woff2', '.css': 'text/css', '.png': 'image/png' }

const server = createServer(async (req, res) => {
  const url = decodeURIComponent(req.url.split('?')[0])
  try {
    // The page itself lives next to this script; everything else (fonts) comes
    // from the package so the output stays on-brand with the shipped app.
    const path = url === '/blink.html' ? join(here, 'blink.html') : join(repo, url)
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
const page = await browser.newPage({
  viewport: { width: WIDTH, height: HEIGHT },
  deviceScaleFactor: SCALE,
})

// `node render-blink.mjs variants` renders every blink mode at 1200 into
// variants/, for choosing between them. With no argument it renders the chosen
// mode at both sizes, which is the deliverable.
const variantPass = process.argv[2] === 'variants'

// The deliverable uses a different treatment per palette, and it is a contrast
// decision rather than a stylistic one. The tagline is 31px, so AA large text
// needs 3:1. Dark starts at 11.57:1 and can afford to dip to 45% (3.35:1, still
// passing). Light starts at 4.24:1 and has no headroom: 45% lands at 1.82:1, and
// the floor has to reach 80% before it passes again, by which point there is no
// visible pulse. So light gets the cursor blink, where the line never dims.
// Raising the light tagline's contrast would mean changing the Truss cyan, which
// is a brand decision and not one a GIF gets to make.
const MODE_BY_THEME = { dark: 'pulse', light: 'cursor' }

for (const theme of ['dark', 'light']) {
  const modes = variantPass ? ['tagline', 'cursor', 'pulse'] : [MODE_BY_THEME[theme]]

  for (const mode of modes) {
    await renderOne(mode, theme, variantPass)
  }
}

async function renderOne(mode, theme, variantPass) {
  const outDir = variantPass ? join(here, 'variants') : out
  await mkdir(outDir, { recursive: true })
  await rm(frames, { recursive: true, force: true })
  await mkdir(frames, { recursive: true })

  await page.goto(`http://localhost:${port}/blink.html`, { waitUntil: 'networkidle' })
  await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme)
  await page.evaluate((m) => window.__setMode(m), mode)
  await page.waitForTimeout(400) // let the webfont settle

  const count = await page.evaluate(() => window.__opacities.length)
  for (let i = 0; i < count; i++) {
    await page.evaluate((n) => window.__frame(n), i)
    await page.screenshot({ path: join(frames, `f${String(i).padStart(3, '0')}.png`) })
  }

  const fps = Math.round(1000 / DELAY_MS)
  const gifFilters = (w, h) => `fps=${fps},scale=${w}:${h}:flags=lanczos,split[a][b];`
    + '[a]palettegen=stats_mode=full:max_colors=128[p];'
    + '[b][p]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle'

  const encode = async (path, w, h) => {
    await run('ffmpeg', ['-y', '-framerate', String(fps), '-i', join(frames, 'f%03d.png'),
      '-filter_complex', gifFilters(w, h), '-loop', '0', path])
    console.log(`rendered ${path}`)
  }

  const suffix = variantPass ? `-${mode}` : ''
  await encode(join(outDir, `blink${suffix}-${theme}.gif`), WIDTH, HEIGHT)

  // A smaller cut for inline use in posts and comments. Only for the deliverable:
  // the variant pass is for choosing a treatment, not for producing final files.
  if (!variantPass) {
    await encode(join(outDir, `blink-${theme}-800.gif`), 800, 450)
  }
}

await rm(frames, { recursive: true, force: true })
await browser.close()
server.close()
