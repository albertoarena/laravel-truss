import { test, expect } from '@playwright/test';
import { categories, posts, users } from '../js/fixtures.js';

// A self-referential foreign key (categories.parent_id → categories) must render
// through the real vendored Mermaid: the "self-ref" attribute note is valid ER
// grammar, and no self-loop edge is drawn (Mermaid draws those as a big curve).
const schema = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  // posts.category_id → categories would be nice, but users/posts give a normal
  // edge to prove ordinary relationships still render alongside the self-ref.
  tables: [users, posts, categories],
};

test.beforeEach(async ({ page }) => {
  await page.route('**/api/schema**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify(schema),
  }));
  await page.goto('/tests/e2e/harness.html');
});

const canvas = (page) => page.locator('#truss-canvas');

test('renders a self-referential foreign key as a column note without a self-loop', async ({ page }) => {
  // If Mermaid rejected the `FK "self-ref"` attribute the SVG would never mount.
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
  await expect(canvas(page)).toContainText('categories');
  await expect(canvas(page)).toContainText('self-ref');

  // The self-loop edge is gone; ordinary edges still render.
  const relCount = await page.locator('#truss-canvas svg .relationshipLine').count();
  expect(relCount).toBe(1); // only users → posts, not the categories self-loop
});
