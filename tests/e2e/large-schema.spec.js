import { test, expect } from '@playwright/test';

// Step 25: a real large schema must render (Mermaid's maxTextSize/maxEdges
// guards are raised) and focus must reduce it to a fast, legible neighbourhood.
const TABLE_COUNT = 100;

function largeSchema(count) {
  const tables = [];
  for (let i = 0; i < count; i += 1) {
    tables.push({
      name: `entity_${i}`,
      columns: [
        { name: 'id', type: 'bigint unsigned', nullable: false, default: null },
        { name: 'label', type: 'varchar(255)', nullable: false, default: null },
        ...(i > 0 ? [{ name: 'parent_id', type: 'bigint unsigned', nullable: false, default: null }] : []),
      ],
      primary_key: ['id'],
      indexes: [],
      foreign_keys: i > 0
        ? [{ name: `entity_${i}_parent_id_foreign`, columns: ['parent_id'], references_table: `entity_${i - 1}`, references_columns: ['id'], on_update: null, on_delete: 'cascade' }]
        : [],
    });
  }
  return { connection: 'primary', fallback: false, skipped_migrations: [], generated_at: '2026-07-23T00:00:00Z', tables };
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/schema**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify(largeSchema(TABLE_COUNT)),
  }));
  await page.goto('/tests/e2e/harness.html');
});

const scaleOf = (page) => page.locator('#truss-canvas').evaluate((el) => Number(/scale\(([-0-9.]+)\)/.exec(el.style.transform)?.[1] ?? 'NaN'));

test('auto-fits a large schema but not below the readable floor', async ({ page }) => {
  await expect(page.locator('#truss-canvas > svg')).toBeVisible({ timeout: 25_000 });

  // True fit of 100 tables is a tiny speck; the auto-fit floors at min_zoom (0.4)
  // so it stays legible and you pan, rather than dumping an unreadable overview.
  expect(await scaleOf(page)).toBeCloseTo(0.4, 5);
});

test('the Fit button frames the whole large schema, below the floor', async ({ page }) => {
  await expect(page.locator('#truss-canvas > svg')).toBeVisible({ timeout: 25_000 });

  await page.click('[data-fit]');
  expect(await scaleOf(page)).toBeLessThan(0.4); // explicit Fit ignores the floor
});

test(`renders a ${TABLE_COUNT}-table schema without hitting Mermaid limits`, async ({ page }) => {
  const started = Date.now();
  await expect(page.locator('#truss-canvas > svg')).toBeVisible({ timeout: 25_000 });

  // The first and last tables of the chain both made it into the diagram.
  await expect(page.locator('#truss-canvas')).toContainText('entity_0');
  await expect(page.locator('#truss-canvas')).toContainText(`entity_${TABLE_COUNT - 1}`);

  // No Mermaid error text leaked into the canvas (would signal a blown guard).
  await expect(page.locator('#truss-canvas')).not.toContainText('Maximum');
  expect(Date.now() - started).toBeLessThan(25_000);
});

test('focus mode keeps a large schema legible by reducing to a neighbourhood', async ({ page }) => {
  await expect(page.locator('#truss-canvas > svg')).toBeVisible({ timeout: 25_000 });

  await page.selectOption('#truss-focus', 'entity_50');

  // depth 1 → entity_49, entity_50, entity_51 only.
  await expect(page.locator('#truss-canvas')).toContainText('entity_49');
  await expect(page.locator('#truss-canvas')).toContainText('entity_51');
  await expect(page.locator('#truss-canvas')).not.toContainText('entity_0');
  await expect(page.locator('#truss-canvas')).not.toContainText('entity_99');
});
