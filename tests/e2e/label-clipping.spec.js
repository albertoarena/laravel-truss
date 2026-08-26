import { readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { users, posts, roles, roleUser } from '../js/fixtures.js';

// Issue #59: every label in the diagram lost its last character in Firefox on
// Windows, because Mermaid measured the label boxes in the fallback face and
// the webfont painted them ~9 percent wider (Consolas 0.55em against IBM Plex
// Mono 0.60em). The boxes carry no slack, so the overflow is clipped.
//
// These specs assert the *ordering*, not the metrics. A metric assertion would
// be green on every machine we can run it on: macOS falls back to Menlo and
// Linux to DejaVu Sans Mono, both of which agree with IBM Plex Mono to within a
// third of a percent, which is exactly why the suite never caught this. The
// ordering is engine-independent and fails today.
//
// ?css=1 loads the real stylesheet, which is what gives the harness an
// @font-face to race against at all.

const schema = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, roles, roleUser],
};

const FACE = 'ibm-plex-mono-400.woff2'; // the weight the diagram labels paint in

/** Records when the diagram first appeared, and how many times it was drawn. */
const instrument = async (page) => {
  await page.addInitScript(() => {
    window.__firstDrawAt = null;
    window.__faceAtFirstDraw = null;
    window.__draws = 0;
    new MutationObserver((records) => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (node.nodeType === 1 && node.tagName.toLowerCase() === 'svg'
            && node.parentElement?.id === 'truss-canvas') {
            window.__draws += 1;
            window.__firstDrawAt ??= performance.now();
            // The invariant, sampled at the only moment it matters. Resource
            // Timing was tried here and is the wrong instrument: it says when
            // bytes landed, not whether the face was usable when the diagram
            // was measured, and it disagreed with itself across engines.
            window.__faceAtFirstDraw ??= document.fonts.check('13px "IBM Plex Mono"');
          }
        }
      }
    }).observe(document, { childList: true, subtree: true });
  });
};

/**
 * Holds the face back by `ms`, so the race is deterministic.
 *
 * The bytes are served from here rather than passed through, and marked
 * no-store. A cached face is never re-requested, so the route would not fire,
 * the delay would not apply, and a spec whose whole premise is a slow font
 * would quietly test the fast path instead.
 */
const FACE_BYTES = readFileSync(`resources/fonts/${FACE}`);
const delayFace = (page, ms) =>
  page.route(`**/${FACE}`, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, ms));
    await route.fulfill({
      status: 200,
      headers: { 'content-type': 'font/woff2', 'cache-control': 'no-store' },
      body: FACE_BYTES,
    });
  });

const observed = (page) =>
  page.evaluate(() => ({
    faceAtFirstDraw: window.__faceAtFirstDraw,
    draws: window.__draws,
  }));

test.beforeEach(async ({ page }) => {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(schema) }));
  await instrument(page);
});

test('waits for the label face before measuring the diagram', async ({ page }) => {
  // Long enough that an ungated render is certain to draw before the face
  // lands, and short enough that the gate still waits it out rather than
  // timing out. Too short and the unfixed code passes on the faster engine.
  await delayFace(page, 1000);
  await page.goto('/tests/e2e/harness.html?css=1', { waitUntil: 'domcontentloaded' });
  await page.locator('#truss-canvas svg').waitFor();

  const { faceAtFirstDraw } = await observed(page);

  // Unfixed this is false, and for the most direct reason there is: nothing
  // asks for the 400 weight until the labels exist, so at the moment the
  // diagram is measured the face is not there to measure in.
  expect(faceAtFirstDraw, 'the diagram was measured before the label face was usable').toBe(true);
});

// The timeout-then-redraw path is not driven from here on purpose. It only
// happens when the face loses a race, and winning a race in a chosen direction
// from outside the browser is not something a loaded machine does reliably: the
// face is requested when the stylesheet is parsed, while the gate's timer starts
// at the first render, so the two clocks drift apart by however long startup
// takes. That policy is unit-tested with fake timers in
// tests/js/label-face-gate.test.js, where the ordering is decided rather than
// hoped for. What is worth asserting in a real browser is what follows.

test('no label is clipped by its own box', async ({ page }) => {
  await page.goto('/tests/e2e/harness.html?css=1', { waitUntil: 'domcontentloaded' });
  await page.locator('#truss-canvas svg').waitFor();
  await page.evaluate(() => document.fonts.ready);

  // Painted ink against the box it was given, both in screen pixels so the
  // canvas zoom cancels. Anything above 1 is a clipped glyph.
  const worst = await page.evaluate(() => {
    const range = document.createRange();
    let max = 0;
    let label = '';
    for (const box of document.querySelectorAll('#truss-canvas svg foreignObject')) {
      const el = box.querySelector('.nodeLabel') || box.firstElementChild;
      if (!el?.textContent.trim() || el.closest('.edgeLabel')) continue;
      range.selectNodeContents(el);
      const rect = box.getBoundingClientRect();
      if (!rect.width) continue;
      const ratio = range.getBoundingClientRect().width / rect.width;
      if (ratio > max) [max, label] = [ratio, el.textContent.trim()];
    }
    return { max, label };
  });

  expect(worst.max, `"${worst.label}" overflows its box`).toBeLessThanOrEqual(1.002);
});
