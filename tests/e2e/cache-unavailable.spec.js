import { test, expect } from '@playwright/test';
import { users, posts } from '../js/fixtures.js';

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-08-17T00:00:00Z',
  tables: [users, posts],
  diff: null,
};

async function load(page, payload) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

// The server degrades to a live, uncached read rather than a 500, so the diagram
// is complete and the notice explains why it may be slow. See #51.
test('notices an unavailable cache store without hiding the diagram', async ({ page }) => {
  await load(page, { ...base, cache_unavailable: true });

  const notice = page.locator('.truss-banner--warn');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('cache store');
  await expect(page.locator('#truss-canvas svg g.node')).toHaveCount(2);
});

test('shows no cache notice when the snapshot came from the cache', async ({ page }) => {
  await load(page, base);

  await expect(page.locator('.truss-banner--warn')).toHaveCount(0);
});
