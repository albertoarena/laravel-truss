import { test, expect } from '@playwright/test';
import { users, posts } from '../js/fixtures.js';

// Issue #35: the dark entity outline only survived around the title block, on
// three sides. Mermaid draws each row as a full rect and paints it after the
// outer path, so the row rects repaint the shared left and right edges (and the
// bottom of the last row) in the pale hairline colour. No colour value can fix
// that, because the outline is overpainted rather than mis-coloured, so the fix
// is paint order: the outline is re-drawn last.

const payload = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts],
  diff: null,
};

async function load(page) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

// The harness inlines only the styles its interactions need, so the shipped
// stylesheet has to be injected to assert anything about painted colour. Doing
// it per-spec keeps it out of the way of the other suites.
const withShippedCss = (page) => page.addStyleTag({ path: 'resources/css/truss.css' });

const strokeOf = (page, selector) => page.evaluate((sel) => {
  const el = document.querySelector(sel);
  const style = getComputedStyle(el);
  return { stroke: style.stroke, width: style.strokeWidth };
}, selector);

test('every entity re-draws its outline last, over the row rects', async ({ page }) => {
  await load(page);

  const shape = await page.evaluate(() => [...document.querySelectorAll('#truss-canvas svg g.node')].map((node) => {
    const groups = [...node.children].filter((c) => c.tagName === 'g');
    const last = groups[groups.length - 1];
    return {
      lastClass: last?.getAttribute('class'),
      outlines: node.querySelectorAll('g.truss-entity-outline').length,
      paths: last?.querySelectorAll('path').length ?? 0,
    };
  }));

  expect(shape.length).toBeGreaterThan(0);
  for (const node of shape) {
    expect(node.lastClass).toContain('truss-entity-outline');
    expect(node.outlines).toBe(1);
    expect(node.paths).toBe(1);
  }
});

test('the re-drawn outline carries the entity border colour, not the row hairline', async ({ page }) => {
  await load(page);
  await withShippedCss(page);

  const outline = await strokeOf(page, '#truss-canvas svg g.node g.truss-entity-outline path');
  const rowRect = await strokeOf(page, '#truss-canvas svg g.node g.row-rect-odd path[stroke]:not([stroke="none"])');

  // The whole point: the outline is painted in the entity border colour and the
  // row rects stay on the pale hairline, so the two are visibly different.
  expect(outline.stroke).not.toBe(rowRect.stroke);
  expect(outline.width).toBe('1.3px');
});

test('the outline follows the same geometry as the original outer path', async ({ page }) => {
  await load(page);

  const same = await page.evaluate(() => {
    const node = document.querySelector('#truss-canvas svg g.node');
    const original = node.querySelector('g.outer-path path[fill="none"]')?.getAttribute('d');
    const outline = node.querySelector('g.truss-entity-outline path')?.getAttribute('d');
    return { original, outline };
  });

  expect(same.outline).toBe(same.original);
});

test('a focused entity re-draws its outline in the focus colour and width', async ({ page }) => {
  await load(page);
  await withShippedCss(page);

  // Applied directly rather than through the Focus control: this is about the
  // variant styling reaching the re-drawn outline, not about the picker.
  await page.evaluate(() => {
    document.querySelector('#truss-canvas svg g.node').classList.add('truss-focused');
  });

  const outline = await strokeOf(page, '#truss-canvas svg g.node.truss-focused g.truss-entity-outline path');
  expect(outline.width).toBe('2.4px');
});

test('a health-flagged entity re-draws its outline in the severity colour', async ({ page }) => {
  await load(page);
  await withShippedCss(page);

  await page.evaluate(() => {
    document.querySelector('#truss-canvas svg g.node').classList.add('truss-health-error');
  });

  const outline = await strokeOf(page, '#truss-canvas svg g.node.truss-health-error g.truss-entity-outline path');
  const plain = await strokeOf(page, '#truss-canvas svg g.node:not(.truss-health-error) g.truss-entity-outline path');

  expect(outline.stroke).not.toBe(plain.stroke);
  expect(outline.width).toBe('2.4px');
});

test('re-rendering does not accumulate outlines', async ({ page }) => {
  await load(page);
  await page.fill('#truss-search', 'users');
  await expect(page.locator('#truss-canvas')).not.toContainText('posts');
  await page.fill('#truss-search', '');
  await expect(page.locator('#truss-canvas')).toContainText('posts');

  const counts = await page.evaluate(() => [...document.querySelectorAll('#truss-canvas svg g.node')]
    .map((n) => n.querySelectorAll('g.truss-entity-outline').length));

  expect(counts.every((c) => c === 1)).toBe(true);
});
