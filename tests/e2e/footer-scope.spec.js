import { test, expect } from '@playwright/test';
import { users, posts, roles, roleUser } from '../js/fixtures.js';
import { selectFocus } from './focus-helper.js';

/**
 * What the footer says about how much of the schema is on screen.
 *
 * Two things are being pinned here. One is new: a payload filtered by
 * `truss.excluded_tables` reports how many it removed, and the footer says the
 * diagram is a view of a larger schema instead of presenting itself as all of
 * it. The other is a bug that shipped long before that: the footer was written
 * once per schema load and never on a filter or a focus, so a focused diagram
 * of five tables kept claiming the full count.
 */

const envelope = (extra = {}) => ({
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, roles, roleUser],
  ...extra,
});

async function open(page, body) {
  await page.route('**/api/schema**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify(body),
  }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

const stat = (page) => page.locator('#truss-stat-tables');

test('states a plain count when the diagram draws the whole payload', async ({ page }) => {
  await open(page, envelope());

  await expect(stat(page)).toHaveText('4 tables');
});

test('says the schema is larger when config excluded tables from it', async ({ page }) => {
  await open(page, envelope({ excluded: { count: 2 } }));

  await expect(stat(page)).toHaveText('4 of 6 tables');
});

test('follows the filter, and does not stack exclusions on top of it', async ({ page }) => {
  await open(page, envelope({ excluded: { count: 2 } }));

  await page.fill('#truss-search', 'post');

  // 1 of 4, never 1 of 6: the total is what the filter is narrowing, and three
  // numbers in a footer is one more than anybody reads.
  await expect(stat(page)).toHaveText('1 of 4 tables');
});

test('follows a focus, which it used to ignore completely', async ({ page }) => {
  await open(page, envelope());

  await selectFocus(page, 'posts');

  await expect(stat(page)).toHaveText('2 of 4 tables');
});

test('returns to the plain count when the filter is cleared', async ({ page }) => {
  await open(page, envelope());

  await page.fill('#truss-search', 'post');
  await expect(stat(page)).toHaveText('1 of 4 tables');

  await page.fill('#truss-search', '');

  await expect(stat(page)).toHaveText('4 tables');
});
