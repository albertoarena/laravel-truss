import { test, expect } from '@playwright/test';
import { users, posts, categories } from '../js/fixtures.js';

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, categories],
  diff: null,
};

// A doctor payload shaped like the API `doctor` field (see DoctorReport), already
// sorted by severity, then table, then code. `posts` has an error + a warning
// (worst: error), `users` has a heuristic warning.
const doctor = {
  summary: { total: 3, error: 1, warning: 2, info: 0 },
  findings: [
    { code: 'TRUSS-INT-001', severity: 'error', table: 'posts', column: null, message: 'Table "posts" has no primary key.', confidence: 'high', category: 'integrity' },
    { code: 'TRUSS-IDX-002', severity: 'warning', table: 'posts', column: 'posts_slug_index', message: 'Duplicate index covering (slug).', confidence: 'high', category: 'index' },
    { code: 'TRUSS-TYP-002', severity: 'warning', table: 'users', column: 'is_admin', message: 'Boolean-looking column stored as varchar.', confidence: 'heuristic', category: 'type' },
  ],
};

async function load(page, payload) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

test('hides the Health button when the response carries no doctor payload', async ({ page }) => {
  await load(page, { ...base, doctor: null });

  await expect(page.locator('#truss-health-btn')).toBeHidden();
});

test('shows the Health panel, count and node badges when findings are present', async ({ page }) => {
  await load(page, { ...base, doctor });

  const button = page.locator('#truss-health-btn');
  await expect(button).toBeVisible();
  await expect(button).toContainText('3'); // the total count bubble

  await button.click();

  const panel = page.locator('#truss-health-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('posts');
  await expect(panel).toContainText('users');
  await expect(panel).toContainText('TRUSS-INT-001');
  await expect(panel).toContainText('no primary key');
  await expect(panel).toContainText('heuristic'); // the TYP-002 marker

  // Nodes are tinted by their worst severity while the panel is open.
  await expect(page.locator('#truss-canvas svg g.node.truss-health-error')).toContainText('posts');
  await expect(page.locator('#truss-canvas svg g.node.truss-health-warning')).toContainText('users');
});

test('clicking a table in the panel focuses it in the diagram', async ({ page }) => {
  await load(page, { ...base, doctor });

  await page.locator('#truss-health-btn').click();
  await page.locator('#truss-health-panel [data-health-focus="posts"]').click();

  await expect(page.locator('#truss-focus')).toHaveValue('posts');
  await expect(page.locator('#truss-canvas')).toContainText('users');       // posts' FK neighbour
  await expect(page.locator('#truss-canvas')).not.toContainText('categories'); // unrelated table
});

test('toggling the Health button off hides the panel and clears badges', async ({ page }) => {
  await load(page, { ...base, doctor });

  const button = page.locator('#truss-health-btn');
  await button.click();
  await expect(page.locator('#truss-health-panel')).toBeVisible();

  await button.click();
  await expect(page.locator('#truss-health-panel')).toBeHidden();
  await expect(page.locator('#truss-canvas svg g.node.truss-health-error')).toHaveCount(0);
});

test('shows a heart icon and colours the button by worst severity', async ({ page }) => {
  await load(page, { ...base, doctor });

  const button = page.locator('#truss-health-btn');
  await expect(button.locator('svg.truss-health-icon')).toHaveCount(1);
  await expect(button).toHaveAttribute('data-severity', 'error');
});

async function iconAnimation(page) {
  return page.locator('#truss-health-btn .truss-health-icon')
    .evaluate((n) => getComputedStyle(n).animationName);
}

test('the icon pulses only when there are error or warning findings', async ({ page }) => {
  await load(page, { ...base, doctor });

  expect(await iconAnimation(page)).toBe('truss-health-pulse');
});

test('the icon stays still when the schema is clean', async ({ page }) => {
  await load(page, { ...base, doctor: { summary: { total: 0, error: 0, warning: 0, info: 0 }, findings: [] } });

  await expect(page.locator('#truss-health-btn')).toHaveAttribute('data-severity', 'clean');
  expect(await iconAnimation(page)).toBe('none');
});

test('the icon respects reduced motion', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await load(page, { ...base, doctor });

  expect(await iconAnimation(page)).toBe('none');
});

test('reports a clean schema when there are no findings', async ({ page }) => {
  await load(page, { ...base, doctor: { summary: { total: 0, error: 0, warning: 0, info: 0 }, findings: [] } });

  const button = page.locator('#truss-health-btn');
  await expect(button).toBeVisible(); // the panel is enabled, just empty

  await button.click();
  await expect(page.locator('#truss-health-panel')).toContainText('No structural problems found');
});
