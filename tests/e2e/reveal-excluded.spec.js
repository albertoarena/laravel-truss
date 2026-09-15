import { test, expect } from '@playwright/test';
import { users, posts, roles, roleUser } from '../js/fixtures.js';

/**
 * Showing the tables config hides.
 *
 * The server decides whether they may be seen at all: without `reveal_excluded`
 * they never reach the browser and there is nothing to toggle. When they do
 * arrive they come marked and undrawn, and this control is the viewer choosing
 * to look. Revealed tables carry no diff marks and no health badges, by design,
 * so they are drawn muted rather than as ordinary tables.
 */

const marked = { ...roleUser, excluded: true };

const envelope = (tables, extra = {}) => ({
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables,
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

const canvas = (page) => page.locator('#truss-canvas');
const toggle = (page) => page.locator('#truss-show-excluded');
const stat = (page) => page.locator('#truss-stat-tables');

test('offers no control when the server sent nothing to reveal', async ({ page }) => {
  await open(page, envelope([users, posts, roles], { excluded: { count: 8 } }));

  // The count says eight are hidden, but config did not allow sending them, so
  // a control here would promise something the page cannot do.
  await expect(toggle(page)).toBeHidden();
  await expect(stat(page)).toHaveText('3 of 11 tables');
});

test('keeps a revealable table out of the diagram until asked', async ({ page }) => {
  await open(page, envelope([users, posts, roles, marked], { excluded: { count: 1 } }));

  await expect(toggle(page)).toBeVisible();
  await expect(canvas(page)).not.toContainText('role_user');
  await expect(stat(page)).toHaveText('3 of 4 tables');
});

test('draws the hidden tables when the viewer asks, and takes them back', async ({ page }) => {
  await open(page, envelope([users, posts, roles, marked], { excluded: { count: 1 } }));

  await toggle(page).check();

  await expect(canvas(page)).toContainText('role_user');
  await expect(stat(page)).toHaveText('4 tables');

  await toggle(page).uncheck();

  await expect(canvas(page)).not.toContainText('role_user');
  await expect(stat(page)).toHaveText('3 of 4 tables');
});

test('marks a revealed table on the diagram, and only the revealed one', async ({ page }) => {
  await open(page, envelope([users, posts, roles, marked], { excluded: { count: 1 } }));

  await toggle(page).check();
  await expect(canvas(page)).toContainText('role_user');

  // The class is what the stylesheet mutes. The harness loads the real CSS only
  // with ?css=1, so what the rule does with the class is asserted in
  // tests/js/excluded-css.test.js instead.
  await expect(page.locator('#truss-canvas g.node.truss-excluded')).toHaveCount(1);
  await expect(page.locator('#truss-canvas g.node.truss-excluded')).toContainText('role_user');
});

test('lets a revealed table be focused like any other', async ({ page }) => {
  await open(page, envelope([users, posts, roles, marked], { excluded: { count: 1 } }));

  await toggle(page).check();
  await expect(canvas(page)).toContainText('role_user');

  // Revealed means revealed: the focus picker is reading the drawn set, so a
  // table that is on screen must be reachable from it.
  await page.locator('#truss-focus').click();
  await page.locator('#truss-focus').fill('role_user');

  await expect(page.locator('#truss-focus-list [data-name="role_user"]')).toBeVisible();
});
