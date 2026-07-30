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

// A per-column finding on posts.user_id, so it maps to a row on the diagram.
const doctor = {
  summary: { total: 1, error: 1, warning: 0, info: 0 },
  findings: [
    { code: 'TRUSS-INT-003', severity: 'error', table: 'posts', column: 'user_id', message: 'Foreign key user_id is bigint but references int.', hint: 'Align the column types.', confidence: 'high', category: 'integrity' },
  ],
};

async function load(page, payload) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

test('marks the offending column on the diagram and opens a finding popover', async ({ page }) => {
  await load(page, { ...base, doctor });
  await page.locator('#truss-health-btn').click();

  const marker = page.locator('#truss-canvas svg .truss-health-marker--error').first();
  await expect(marker).toBeVisible();

  await marker.click();

  const pop = page.locator('#truss-popover');
  await expect(pop).toBeVisible();
  await expect(pop).toContainText('user_id');
  await expect(pop).toContainText('references int');
  await expect(pop).toContainText('Align the column types'); // the hint
});

test('the finding popover coexists with the open Health panel', async ({ page }) => {
  await load(page, { ...base, doctor });
  await page.locator('#truss-health-btn').click();

  await page.locator('#truss-canvas svg .truss-health-marker--error').first().click();

  await expect(page.locator('#truss-popover')).toBeVisible();
  await expect(page.locator('#truss-health-panel')).toBeVisible(); // still open
});

test('clears the in-table markers when the Health view is closed', async ({ page }) => {
  await load(page, { ...base, doctor });
  await page.locator('#truss-health-btn').click();
  await expect(page.locator('#truss-canvas svg .truss-health-marker--error')).toHaveCount(1);

  await page.locator('#truss-health-btn').click(); // close Health
  await expect(page.locator('#truss-canvas svg .truss-health-marker--error')).toHaveCount(0);
});

test('shows no markers for a table-level finding', async ({ page }) => {
  const tableLevel = {
    summary: { total: 1, error: 1, warning: 0, info: 0 },
    findings: [{ code: 'TRUSS-INT-001', severity: 'error', table: 'posts', column: null, message: 'No primary key.', hint: '', confidence: 'high', category: 'integrity' }],
  };
  await load(page, { ...base, doctor: tableLevel });
  await page.locator('#truss-health-btn').click();

  await expect(page.locator('#truss-canvas svg .truss-health-marker')).toHaveCount(0);
});
