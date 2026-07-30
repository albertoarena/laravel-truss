import { test, expect } from '@playwright/test';
import { users, posts } from '../js/fixtures.js';

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts],
  diff: null,
};

// posts has two findings (worst: error); users has none.
const doctor = {
  summary: { total: 2, error: 1, warning: 1, info: 0 },
  findings: [
    { code: 'TRUSS-INT-001', severity: 'error', table: 'posts', column: null, message: 'No primary key.', confidence: 'high', category: 'integrity' },
    { code: 'TRUSS-IDX-002', severity: 'warning', table: 'posts', column: 'posts_slug_index', message: 'Duplicate index.', confidence: 'high', category: 'index' },
  ],
};

async function load(page, query = '') {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ...base, doctor }) }));
  await page.goto(`/tests/e2e/harness.html${query}`);
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

test('always flags a table with findings, even with the panel closed', async ({ page }) => {
  await load(page);

  await expect(page.locator('#truss-health-panel')).toBeHidden(); // no panel open

  const flag = page.locator('#truss-canvas svg .truss-health-flag--error');
  await expect(flag).toHaveCount(1);
  await expect(flag).toContainText('2'); // the finding count, worst-severity coloured

  // The dot must actually take the severity fill, not Mermaid's node default.
  const fill = await flag.locator('circle').evaluate((n) => getComputedStyle(n).fill);
  expect(fill).toBe('rgb(170, 17, 17)');
});

test('does not flag a table that has no findings', async ({ page }) => {
  await load(page);

  // Only posts is flagged; users has no findings.
  await expect(page.locator('#truss-canvas svg .truss-health-flag')).toHaveCount(1);
});

test('the flag persists when the Health panel is opened and closed', async ({ page }) => {
  await load(page);

  await page.locator('#truss-health-btn').click();
  await expect(page.locator('#truss-canvas svg .truss-health-flag')).toHaveCount(1);

  await page.locator('#truss-health-btn').click(); // close
  await expect(page.locator('#truss-canvas svg .truss-health-flag')).toHaveCount(1);
});

test('honours the flag-tables config being turned off', async ({ page }) => {
  await load(page, '?flag=0');

  await expect(page.locator('#truss-canvas svg .truss-health-flag')).toHaveCount(0);
});
