import { test, expect } from '@playwright/test';

// Issue #94: one table or column name Mermaid's ER grammar rejects (a leading
// digit, `pk`, `$`, whitespace, a quote) used to blank the whole canvas with
// "Diagram failed to render". The dashboard now shows the real names: tables
// through an alias, columns quoted in backticks.

const col = (name, type = 'int') => ({ name, type, nullable: false, default: null });

const schema = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-10-20T00:00:00Z',
  diff: null,
  tables: [
    {
      name: 'users',
      columns: [col('id', 'bigint unsigned'), col('429_error_date', 'date'), col('pk'), col('full name'), col('429_名前$')],
      primary_key: ['id'],
      indexes: [],
      foreign_keys: [],
    },
    {
      name: '429_logs',
      columns: [col('id', 'bigint unsigned'), col('user_id', 'bigint unsigned')],
      primary_key: ['id'],
      indexes: [],
      foreign_keys: [
        { name: 'logs "user"', columns: ['user_id'], references_table: 'users', references_columns: ['id'], on_update: null, on_delete: null },
      ],
    },
    { name: 'a$b', columns: [col('id')], primary_key: [], indexes: [], foreign_keys: [] },
    { name: '50% "off"', columns: [col('id')], primary_key: [], indexes: [], foreign_keys: [] },
    // Labels go through markdown: unescaped, this alias would render as italic "a b".
    { name: '_a b_', columns: [col('id')], primary_key: [], indexes: [], foreign_keys: [] },
  ],
};

const doctor = {
  summary: { total: 1, error: 1, warning: 0, info: 0 },
  findings: [
    { code: 'TRUSS-INT-003', severity: 'error', table: 'users', column: '429_error_date', message: 'A finding on an awkward column.', hint: '', confidence: 'high', category: 'integrity' },
  ],
};

async function load(page, payload = schema, query = '') {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto(`/tests/e2e/harness.html${query}`);
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

const texts = (page, selector) =>
  page.locator(`#truss-canvas svg ${selector}`).evaluateAll((els) => els.map((el) => el.textContent.trim()));

test('renders a schema of names Mermaid rejects, instead of blanking the canvas', async ({ page }) => {
  await load(page);

  await expect(page.locator('#truss-canvas')).not.toContainText('failed to render');
  await expect(page.locator('#truss-canvas svg .relationshipLine')).toHaveCount(1);
});

test('shows every real table name, and wires the table menu to it', async ({ page }) => {
  await load(page);

  const names = await texts(page, '.truss-table-name');
  expect(names.sort()).toEqual(['429_logs', '50% "off"', '_a b_', 'a$b', 'users'].sort());

  await page.locator('#truss-canvas .truss-table-name', { hasText: '429_logs' }).click();
  await expect(page.locator('#truss-popover')).toBeVisible();
});

test('shows the real column names, not rewritten tokens', async ({ page }) => {
  await load(page);

  const columns = await texts(page, 'g.label.attribute-name .nodeLabel');
  expect(columns).toEqual(expect.arrayContaining(['429_error_date', 'pk', 'full name', '429_名前$']));
  expect(columns).not.toContain('_429_error_date');
  expect(columns).not.toContain('_pk');
  expect(columns.some((name) => name.includes('`'))).toBe(false);
});

test('puts a doctor marker on the real column of a rewritten name', async ({ page }) => {
  await load(page, { ...schema, doctor });
  await page.locator('#truss-health-btn').click();

  const marker = page.locator('#truss-canvas svg .truss-health-marker--error');
  await expect(marker).toHaveCount(1);
  await expect(marker).toHaveText('429_error_date');
});

// A quoted name is measured and painted as itself, so nothing can clip it; this
// pins that for a name mixing rewritten-before and wide characters.
test('a quoted column name is not clipped by its box', async ({ page }) => {
  await load(page, schema, '?css=1');
  await page.evaluate(() => document.fonts.ready);

  const worst = await page.evaluate(() => {
    const range = document.createRange();
    let max = 0;
    let label = '';
    for (const cell of document.querySelectorAll('#truss-canvas svg g.label.attribute-name')) {
      const box = cell.querySelector('foreignObject');
      const el = cell.querySelector('.nodeLabel');
      if (!box || !el?.textContent.trim()) continue;
      range.selectNodeContents(el);
      const rect = box.getBoundingClientRect();
      if (!rect.width) continue;
      const ratio = range.getBoundingClientRect().width / rect.width;
      if (ratio > max) [max, label] = [ratio, el.textContent.trim()];
    }
    return { max, label };
  });

  expect(worst.max, `"${worst.label}" overflows its box`).toBeLessThanOrEqual(1.002);
});
