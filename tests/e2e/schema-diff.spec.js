import { test, expect } from '@playwright/test';
import { users, posts } from '../js/fixtures.js';

const comments = {
  name: 'comments',
  columns: [{ name: 'id', type: 'bigint unsigned', nullable: false, default: null }],
  primary_key: ['id'],
  indexes: [],
  foreign_keys: [],
};

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, comments],
};

// `comments` is the added table (present now), `posts` gained a `slug` column,
// and `legacy` was removed (absent from the current tables, so list-only).
const diff = {
  tables_added: [{ name: 'comments' }],
  tables_removed: [{ name: 'legacy' }],
  tables_changed: [{
    name: 'posts',
    columns_added: [{ name: 'slug', type: 'varchar(255)', nullable: false, default: null }],
    columns_removed: [],
    columns_changed: [],
    indexes_added: [],
    indexes_removed: [],
    indexes_changed: [],
    foreign_keys_added: [],
    foreign_keys_removed: [],
    foreign_keys_changed: [],
    changes: {},
  }],
  has_changes: true,
  baseline_generated_at: '2026-07-01T00:00:00Z',
  current_generated_at: '2026-07-23T00:00:00Z',
};

async function load(page, payload) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

test('hides the Changes button when the response carries no diff', async ({ page }) => {
  await load(page, { ...base, diff: null });

  await expect(page.locator('#truss-diff-btn')).toBeHidden();
});

test('shows the Changes panel and tints changed tables when a diff is present', async ({ page }) => {
  await load(page, { ...base, diff });

  const button = page.locator('#truss-diff-btn');
  await expect(button).toBeVisible();

  await button.click();

  const panel = page.locator('#truss-diff-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('comments'); // added
  await expect(panel).toContainText('legacy');   // removed
  await expect(panel).toContainText('posts');    // changed
  await expect(panel).toContainText('slug');     // the added column

  // The added and changed tables are tinted in the diagram; removed is list-only.
  await expect(page.locator('#truss-canvas svg g.node.truss-diff-added')).toContainText('comments');
  await expect(page.locator('#truss-canvas svg g.node.truss-diff-changed')).toContainText('posts');
});

test('clicking a changed table in the panel focuses it in the diagram', async ({ page }) => {
  await load(page, { ...base, diff });

  await page.locator('#truss-diff-btn').click();
  await page.locator('#truss-diff-panel [data-diff-focus="posts"]').click();

  await expect(page.locator('#truss-focus')).toHaveValue('posts');
  await expect(page.locator('#truss-canvas')).toContainText('users');     // posts' FK neighbour
  await expect(page.locator('#truss-canvas')).not.toContainText('comments'); // unrelated added table
});

test('removed tables are listed but not focusable', async ({ page }) => {
  await load(page, { ...base, diff });

  await page.locator('#truss-diff-btn').click();

  await expect(page.locator('#truss-diff-panel')).toContainText('legacy');
  await expect(page.locator('#truss-diff-panel [data-diff-focus="legacy"]')).toHaveCount(0);
});

test('toggling the Changes button off hides the panel and clears tints', async ({ page }) => {
  await load(page, { ...base, diff });

  const button = page.locator('#truss-diff-btn');
  await button.click();
  await expect(page.locator('#truss-diff-panel')).toBeVisible();

  await button.click();
  await expect(page.locator('#truss-diff-panel')).toBeHidden();
  await expect(page.locator('#truss-canvas svg g.node.truss-diff-added')).toHaveCount(0);
});

test('explains that Changes is unavailable when the baseline could not be read', async ({ page }) => {
  // The API reports diff_unavailable when the baseline disk failed, as opposed to
  // simply having no baseline yet. Without a notice the panel is just missing and
  // the user assumes nothing has changed, which is a different claim entirely.
  await page.route('**/api/schema**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ...base, diff: null, diff_unavailable: true }),
  }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();

  await expect(page.locator('#truss-banners')).toContainText(/Changes.*unavailable/i);
  await expect(page.locator('#truss-banners')).toContainText('TRUSS_DIFF_DISK');
  // The diagram itself is unaffected: the point of the fix is that a baseline
  // problem costs you one panel, not the tool.
  await expect(page.locator('#truss-canvas')).toContainText('users');
  await expect(page.locator('#truss-diff-btn')).toBeHidden();
});
