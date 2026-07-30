import { test, expect } from '@playwright/test';
import { users, posts, categories } from '../js/fixtures.js';

const doctor = {
  summary: { total: 1, error: 1, warning: 0, info: 0 },
  findings: [
    { code: 'TRUSS-INT-001', severity: 'error', table: 'posts', column: null, message: 'Table "posts" has no primary key.', confidence: 'high', category: 'integrity' },
  ],
};

const diff = {
  tables_added: [{ name: 'categories' }],
  tables_removed: [],
  tables_changed: [],
  has_changes: true,
  baseline_generated_at: '2026-07-01T00:00:00Z',
  current_generated_at: '2026-07-23T00:00:00Z',
};

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, categories],
  diff,
  doctor,
};

async function load(page) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(base) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

test('opening the Health panel closes the Changes panel', async ({ page }) => {
  await load(page);

  await page.locator('#truss-diff-btn').click();
  await expect(page.locator('#truss-diff-panel')).toBeVisible();

  await page.locator('#truss-health-btn').click();
  await expect(page.locator('#truss-health-panel')).toBeVisible();
  await expect(page.locator('#truss-diff-panel')).toBeHidden();
});

test('opening the Export menu closes the Health panel', async ({ page }) => {
  await load(page);

  await page.locator('#truss-health-btn').click();
  await expect(page.locator('#truss-health-panel')).toBeVisible();

  await page.locator('#truss-export-btn').click();
  await expect(page.locator('#truss-popover')).toBeVisible();
  await expect(page.locator('#truss-health-panel')).toBeHidden();
});

test('opening the Health panel closes the Export menu', async ({ page }) => {
  await load(page);

  await page.locator('#truss-export-btn').click();
  await expect(page.locator('#truss-popover')).toBeVisible();

  await page.locator('#truss-health-btn').click();
  await expect(page.locator('#truss-health-panel')).toBeVisible();
  await expect(page.locator('#truss-popover')).toBeHidden();
});

test('the Export menu aligns to the top-right cluster', async ({ page }) => {
  await load(page);

  await page.locator('#truss-export-btn').click();
  const menu = page.locator('#truss-popover');
  await expect(menu).toBeVisible();

  const box = await menu.boundingBox();
  const appRight = await page.locator('#truss-app').evaluate((n) => n.getBoundingClientRect().right);

  // Its right edge sits 14px in from the dashboard's right edge, like the panels.
  expect(Math.abs((box.x + box.width) - (appRight - 14))).toBeLessThan(3);
});
