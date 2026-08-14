import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { users, posts, roles, roleUser } from '../js/fixtures.js';

// Accessibility of the diagram surface. Three labels inside the rendered SVG are
// given role="button" and tabindex="0" by truss.js (the table name, an enum type,
// a health marker), which promises assistive technology a button. Until this spec
// existed nothing pressed them with a key, and nothing did: there was no keydown
// handler anywhere in resources/js. WCAG 2.1.1 (Keyboard), 2.1.2 (No Keyboard
// Trap) and 1.1.1 (Non-text Content).

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, roles, roleUser],
  diff: null,
};

const doctor = {
  summary: { total: 1, error: 0, warning: 1, info: 0 },
  findings: [
    { code: 'TRUSS-TYP-002', severity: 'warning', table: 'users', column: 'email', message: 'Example finding for the marker.', confidence: 'heuristic', category: 'type' },
  ],
};

async function load(page, payload = base) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

const tableName = (page, name) => page.locator(`#truss-canvas .truss-table-name`, { hasText: name }).first();
const popover = (page) => page.locator('#truss-popover');

test.describe('keyboard operation of the diagram', () => {
  test('Enter on a table name opens the per-table menu', async ({ page }) => {
    await load(page);
    await tableName(page, 'users').press('Enter');
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toContainText('Focus this table');
  });

  test('Space on a table name opens the per-table menu', async ({ page }) => {
    await load(page);
    await tableName(page, 'users').press(' ');
    await expect(popover(page)).toBeVisible();
  });

  test('Space on a table name does not scroll the page', async ({ page }) => {
    await load(page);
    const before = await page.evaluate(() => window.scrollY);
    await tableName(page, 'users').press(' ');
    expect(await page.evaluate(() => window.scrollY)).toBe(before);
  });

  test('Escape closes the popover and returns focus to what opened it', async ({ page }) => {
    await load(page);
    const trigger = tableName(page, 'users');
    await trigger.press('Enter');
    await expect(popover(page)).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(popover(page)).toBeHidden();
    // Focus must come back to the trigger, or a keyboard user is dropped at the
    // top of the document and has to Tab all the way back in (2.1.2).
    await expect(trigger).toBeFocused();
  });

  test('the per-table menu can be operated to focus a table without a mouse', async ({ page }) => {
    await load(page);
    await tableName(page, 'users').press('Enter');
    await popover(page).getByRole('button', { name: /Focus this table/ }).press('Enter');

    await expect(page.locator('#truss-canvas')).toContainText('role_user');
    await expect(page.locator('#truss-canvas')).not.toContainText('roles');
  });

  // Activating an item hides the popover, which takes the pressed button out from
  // under focus. Without a hand-back the browser drops the user on <body> and the
  // next Tab restarts at the top of the toolbar, several stops from where they
  // were working (2.4.3 Focus Order). Escape already restores; so must this.
  test('activating a menu action returns focus to what opened it', async ({ page }) => {
    await load(page);
    const trigger = tableName(page, 'users');
    await trigger.press('Enter');

    await popover(page).getByRole('button', { name: /Copy JSON/ }).press('Enter');

    await expect(popover(page)).toBeHidden();
    await expect(trigger).toBeFocused();
  });

  // Focusing a table re-renders the diagram, so the label that opened the menu is
  // detached by the time the action finishes. The same table's new label is the
  // equivalent place to land.
  test('focusing a table from the menu lands focus on the re-rendered label', async ({ page }) => {
    await load(page);
    await tableName(page, 'users').press('Enter');

    await popover(page).getByRole('button', { name: /Focus this table/ }).press('Enter');

    await expect(page.locator('#truss-canvas')).not.toContainText('roles');
    await expect(tableName(page, 'users')).toBeFocused();
  });

  // The stored anchor has to die with the popover it belonged to. Left behind, it
  // fires on the next Escape from a menu the user opened with the mouse, throwing
  // focus back to a table they visited earlier.
  test('a menu opened with the mouse does not restore an earlier trigger', async ({ page }) => {
    await load(page);
    const earlier = tableName(page, 'users');
    await earlier.press('Enter');
    await popover(page).getByRole('button', { name: /Copy JSON/ }).press('Enter');

    await tableName(page, 'posts').click();
    await expect(popover(page)).toBeVisible();
    await page.keyboard.press('Escape');

    await expect(popover(page)).toBeHidden();
    await expect(earlier).not.toBeFocused();
  });

  test('Enter on an enum type label opens the value popover', async ({ page }) => {
    await load(page);
    await page.locator('#truss-canvas .truss-enum-type').first().press('Enter');
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toContainText('draft');
  });

  test('Enter on a health marker opens the findings popover', async ({ page }) => {
    await load(page, { ...base, doctor });
    // Markers are only drawn while the Health panel is open (markDoctorRows).
    await page.locator('#truss-health-btn').click();
    await page.locator('#truss-canvas .truss-health-marker').first().press('Enter');
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toContainText('Example finding for the marker.');
  });
});

test.describe('the diagram names itself to assistive technology', () => {
  test('the SVG carries a title and a description', async ({ page }) => {
    await load(page);
    const svg = page.locator('#truss-canvas > svg');
    await expect(svg.locator('title').first()).toContainText(/Database structure diagram/);
    await expect(svg.locator('desc').first()).toContainText(/4 tables/);
  });

  test('the description follows the filter', async ({ page }) => {
    await load(page);
    await page.fill('#truss-search', 'post');
    await expect(page.locator('#truss-canvas > svg desc').first())
      .toContainText(/filtered by "post"/);
  });
});

// Automated WCAG scan. axe-core catches the machine-checkable subset (names,
// roles, relationships, landmark and label structure); it is a floor, not a
// conformance proof, so the behavioural specs above still carry the weight.
//
// color-contrast is excluded deliberately: this harness page carries its own
// inlined styles rather than the shipped truss.css, so a contrast result here
// would describe the harness, not the package. Contrast is measured against the
// real stylesheet and the configurable theme in a later phase.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const scan = (page) => new AxeBuilder({ page })
  .withTags(WCAG)
  .disableRules(['color-contrast'])
  .analyze();

// Report the offending selector, not just the rule id: a bare rule name in a CI
// log sends you hunting through the page to find what tripped it.
const report = ({ violations }) =>
  violations.map((v) => `${v.id} → ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);

test.describe('automated WCAG scan (axe-core)', () => {
  test('the dashboard has no violations', async ({ page }) => {
    await load(page);
    expect(report(await scan(page))).toEqual([]);
  });

  test('no violations with the per-table menu open', async ({ page }) => {
    await load(page);
    await tableName(page, 'users').press('Enter');
    await expect(popover(page)).toBeVisible();
    expect(report(await scan(page))).toEqual([]);
  });

  test('no violations with the Health panel open', async ({ page }) => {
    await load(page, { ...base, doctor });
    await page.locator('#truss-health-btn').click();
    expect(report(await scan(page))).toEqual([]);
  });

  test('no violations with the export menu open', async ({ page }) => {
    // The harness toolbar carries the Export button but not the Legend, so this
    // is the overlay to scan here; the Legend is plain static markup.
    await load(page);
    await page.locator('#truss-export-btn').click();
    expect(report(await scan(page))).toEqual([]);
  });
});
