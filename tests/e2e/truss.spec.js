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

test('focus centres the focused table in the viewport', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  await page.selectOption('#truss-focus', 'users');
  await expect(canvas(page)).toContainText('role_user'); // wait for the re-render

  const offset = await page.evaluate(() => {
    const svg = document.querySelector('#truss-canvas svg');
    let node = null;
    svg.querySelectorAll('g.node').forEach((n) => {
      const l = n.querySelector('g.label.name .nodeLabel');
      if (l && l.textContent.trim() === 'users') node = n;
    });
    const n = node.getBoundingClientRect();
    const v = document.getElementById('truss-viewport').getBoundingClientRect();
    return {
      dx: Math.abs((n.left + n.right) / 2 - (v.left + v.right) / 2) / v.width,
      dy: Math.abs((n.top + n.bottom) / 2 - (v.top + v.bottom) / 2) / v.height,
    };
  });

  // The focused node's centre should sit near the viewport centre.
  expect(offset.dx).toBeLessThan(0.1);
  expect(offset.dy).toBeLessThan(0.1);
});

test('the focused table is flagged for highlighting', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  await page.selectOption('#truss-focus', 'users');
  await expect(canvas(page)).toContainText('role_user');

  const flags = await page.evaluate(() => {
    const named = (name) => {
      let node = null;
      document.querySelectorAll('#truss-canvas g.node').forEach((n) => {
        if (n.querySelector('g.label.name .nodeLabel')?.textContent.trim() === name) node = n;
      });
      return node;
    };
    return {
      focused: named('users')?.classList.contains('truss-focused'),
      neighbour: named('posts')?.classList.contains('truss-focused'),
    };
  });

  expect(flags.focused).toBe(true);
  expect(flags.neighbour).toBe(false);
});

test('the Laravel-types toggle swaps native types for short labels', async ({ page }) => {
  await expect(canvas(page)).toContainText('bigint_unsigned');

  await page.check('#truss-labels');

  await expect(canvas(page)).toContainText('integer');
  await expect(canvas(page)).not.toContainText('bigint_unsigned');
});

const scaleOf = (page) => canvas(page).evaluate((el) => Number(/scale\(([-0-9.]+)\)/.exec(el.style.transform)?.[1] ?? 'NaN'));
const translateOf = (page) => canvas(page).evaluate((el) => {
  const m = /translate\(([-0-9.]+)px,\s*([-0-9.]+)px\)/.exec(el.style.transform);
  return { x: Number(m?.[1]), y: Number(m?.[2]) };
});

test('the wheel zooms toward the cursor', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  const before = await scaleOf(page);

  const box = await page.locator('#truss-viewport').boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.wheel(0, -300);

  expect(await scaleOf(page)).toBeGreaterThan(before);
});

test('dragging pans the canvas', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  const before = await translateOf(page);

  const box = await page.locator('#truss-viewport').boundingBox();
  await page.mouse.move(box.x + 200, box.y + 200);
  await page.mouse.down();
  await page.mouse.move(box.x + 340, box.y + 260);
  await page.mouse.up();

  const after = await translateOf(page);
  expect(after.x).not.toBe(before.x);
  expect(after.y).not.toBe(before.y);
});

test('the slider sets the zoom level and Fit re-frames', async ({ page }) => {
  await expect(canvas(page).locator('svg')).toBeVisible();
  const fitted = await scaleOf(page);

  await page.locator('#truss-zoom-range').fill('2');
  expect(await scaleOf(page)).toBeCloseTo(2, 1);
  await expect(page.locator('#truss-zoom-pct')).toHaveText('200%');

  await page.click('[data-fit]');
  expect(await scaleOf(page)).toBeCloseTo(fitted, 1);
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
