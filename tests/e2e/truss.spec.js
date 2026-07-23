import { test, expect } from '@playwright/test';
import { users, posts, roles, roleUser } from '../js/fixtures.js';

const primary = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, roles, roleUser], // 4 tables > warn_above (3)
};

const secondary = {
  connection: 'secondary',
  fallback: true, // exercises the SQLite-fallback banner
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [{ name: 'audit_log', columns: [{ name: 'id', type: 'bigint unsigned', nullable: false, default: null }], primary_key: ['id'], indexes: [], foreign_keys: [] }],
};

test.beforeEach(async ({ page }) => {
  await page.route('**/api/schema**', (route) => {
    const connection = new URL(route.request().url()).searchParams.get('connection');
    const body = connection === 'secondary' ? secondary : primary;
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/tests/e2e/harness.html');
});

const canvas = (page) => page.locator('#truss-canvas');
const banners = (page) => page.locator('#truss-banners');

test('renders an ER diagram from the fetched schema', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  await expect(canvas(page)).toContainText('users');
  await expect(canvas(page)).toContainText('posts');
});

test('the text filter reduces the diagram to matching tables', async ({ page }) => {
  await expect(canvas(page)).toContainText('roles');

  await page.fill('#truss-search', 'post');

  await expect(canvas(page)).toContainText('posts');
  await expect(canvas(page)).not.toContainText('roles');
  await expect(canvas(page)).not.toContainText('users');
});

test('focus mode reduces to a table and its FK neighbours', async ({ page }) => {
  await page.selectOption('#truss-focus', 'users');

  // depth 1 from users → users + posts + role_user, but not roles.
  await expect(canvas(page)).toContainText('role_user');
  await expect(canvas(page)).toContainText('posts');
  await expect(canvas(page)).not.toContainText('roles');
});

test('the Laravel-types toggle swaps native types for short labels', async ({ page }) => {
  await expect(canvas(page)).toContainText('bigint_unsigned');

  await page.check('#truss-labels');

  await expect(canvas(page)).toContainText('integer');
  await expect(canvas(page)).not.toContainText('bigint_unsigned');
});

test('zoom controls scale the canvas via CSS transform', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();

  await page.click('[data-zoom="in"]');
  await expect(canvas(page)).toHaveAttribute('style', /scale\(1\.2\)/);

  await page.click('[data-zoom="reset"]');
  await expect(canvas(page)).toHaveAttribute('style', /scale\(1\)/);
});

test('the connection switcher re-fetches and re-renders without reload', async ({ page }) => {
  const connection = page.locator('#truss-connection');
  await expect(connection).toBeVisible(); // two connections → switcher shown

  await connection.selectOption('secondary');

  await expect(canvas(page)).toContainText('audit_log');
  await expect(canvas(page)).not.toContainText('posts');
  await expect(banners(page)).toContainText('SQLite'); // fallback banner
});

test('shows the large-schema warning above the configured threshold', async ({ page }) => {
  // primary has 4 tables, warn_above is 3, and nothing is filtered/focused.
  await expect(banners(page)).toContainText('large schema');
});
