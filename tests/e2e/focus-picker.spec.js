import { test, expect } from '@playwright/test';
import { users, posts, roles, roleUser } from '../js/fixtures.js';

// The searchable Focus picker (issue #39). The native select it replaces could
// only jump by prefix and showed no sign of what it matched, which made it
// unusable on a large schema. Replacing it with a custom widget means the ARIA
// combobox pattern is ours to implement, so these specs cover the state machine,
// not just the happy path.

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-07-23T00:00:00Z',
  tables: [users, posts, roles, roleUser],
  diff: null,
};

async function load(page) {
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(base) }));
  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
}

const input = (page) => page.locator('#truss-focus');
const list = (page) => page.locator('#truss-focus-list');
const options = (page) => page.locator('#truss-focus-list [role="option"]');
const activeOption = (page) => page.locator('#truss-focus-list [role="option"].is-active');

test.describe('the Focus picker narrows as you type', () => {
  test('opens on focus and lists every table', async ({ page }) => {
    await load(page);
    await input(page).click();
    await expect(list(page)).toBeVisible();
    // 4 tables plus the Clear focus row.
    await expect(options(page)).toHaveCount(5);
  });

  test('matches anywhere in the name, not only at the start', async ({ page }) => {
    await load(page);
    await input(page).fill('user');
    // users, and role_user by substring; the Clear row is filtered out.
    await expect(options(page)).toHaveText([/users/, /role_user/]);
  });

  test('says how many tables matched', async ({ page }) => {
    await load(page);
    await input(page).fill('user');
    await expect(page.locator('#truss-focus-status')).toHaveText(/2 tables match/);
  });

  test('says when nothing matched', async ({ page }) => {
    await load(page);
    await input(page).fill('zzz');
    await expect(page.locator('#truss-focus-status')).toHaveText(/No tables match/);
    await expect(options(page)).toHaveCount(0);
  });
});

test.describe('committing a choice', () => {
  test('Enter commits the active option and focuses that table', async ({ page }) => {
    await load(page);
    await input(page).fill('users');
    await input(page).press('ArrowDown');
    await input(page).press('Enter');

    await expect(input(page)).toHaveValue('users');
    await expect(page.locator('#truss-canvas')).toContainText('role_user');
    await expect(page.locator('#truss-canvas')).not.toContainText('roles');
  });

  test('clicking an option commits it', async ({ page }) => {
    await load(page);
    await input(page).click();
    await options(page).filter({ hasText: 'posts' }).first().click();

    await expect(input(page)).toHaveValue('posts');
    await expect(list(page)).toBeHidden();
  });

  test('the Clear focus row clears the focus', async ({ page }) => {
    await load(page);
    await input(page).fill('users');
    await input(page).press('ArrowDown');
    await input(page).press('Enter');
    await expect(input(page)).toHaveValue('users');

    await input(page).click();
    await options(page).filter({ hasText: 'Clear focus' }).click();

    await expect(input(page)).toHaveValue('');
    await expect(page.locator('#truss-canvas')).toContainText('roles');
  });

  test('Escape closes the list and restores the committed value', async ({ page }) => {
    await load(page);
    await input(page).fill('posts');
    await input(page).press('ArrowDown');
    await input(page).press('Enter');

    await input(page).fill('rol');
    await input(page).press('Escape');

    await expect(list(page)).toBeHidden();
    await expect(input(page)).toHaveValue('posts');
  });

  test('typing a name that does not exist reverts on blur', async ({ page }) => {
    await load(page);
    await input(page).fill('not_a_table');
    await page.locator('#truss-search').click();

    await expect(input(page)).toHaveValue('');
    await expect(page.locator('#truss-canvas')).toContainText('roles');
  });
});

test.describe('keyboard and ARIA', () => {
  test('aria-expanded reflects whether the list is open', async ({ page }) => {
    await load(page);
    await expect(input(page)).toHaveAttribute('aria-expanded', 'false');
    await input(page).click();
    await expect(input(page)).toHaveAttribute('aria-expanded', 'true');
    await input(page).press('Escape');
    await expect(input(page)).toHaveAttribute('aria-expanded', 'false');
  });

  test('arrow keys move the active option and aria-activedescendant follows', async ({ page }) => {
    await load(page);
    await input(page).click();
    await input(page).press('ArrowDown');

    const first = await activeOption(page).getAttribute('id');
    expect(await input(page).getAttribute('aria-activedescendant')).toBe(first);

    await input(page).press('ArrowDown');
    const second = await activeOption(page).getAttribute('id');
    expect(second).not.toBe(first);
    expect(await input(page).getAttribute('aria-activedescendant')).toBe(second);
  });

  test('focus stays in the input while arrowing (aria-activedescendant pattern)', async ({ page }) => {
    await load(page);
    await input(page).click();
    await input(page).press('ArrowDown');
    await expect(input(page)).toBeFocused();
  });

  test('ArrowUp from the top wraps to the last option', async ({ page }) => {
    await load(page);
    await input(page).click();
    await input(page).press('ArrowUp');
    await expect(activeOption(page)).toHaveText(/role_user/);
  });

  test('Home and End jump to the first and last option', async ({ page }) => {
    await load(page);
    await input(page).click();
    await input(page).press('End');
    await expect(activeOption(page)).toHaveText(/role_user/);
    await input(page).press('Home');
    await expect(activeOption(page)).toHaveText(/Clear focus/);
  });

  test('the committed option is marked selected', async ({ page }) => {
    await load(page);
    await input(page).fill('posts');
    await input(page).press('ArrowDown');
    await input(page).press('Enter');

    await input(page).click();
    await expect(options(page).filter({ hasText: 'posts' }).first())
      .toHaveAttribute('aria-selected', 'true');
  });

  test('opening another overlay closes the list', async ({ page }) => {
    await load(page);
    await input(page).click();
    await expect(list(page)).toBeVisible();

    await page.locator('#truss-export-btn').click();

    await expect(list(page)).toBeHidden();
  });
});

test.describe('the picker stays in step with the rest of the dashboard', () => {
  test('focusing from the per-table menu updates the picker', async ({ page }) => {
    await load(page);
    await page.locator('#truss-canvas .truss-table-name', { hasText: 'users' }).first().press('Enter');
    await page.locator('#truss-popover').getByRole('button', { name: /Focus this table/ }).click();

    await expect(input(page)).toHaveValue('users');
  });

  test('a focus from the URL seeds the picker', async ({ page }) => {
    await page.route('**/api/schema**', (route) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify(base) }));
    await page.goto('/tests/e2e/harness.html?focus=posts');
    await expect(page.locator('#truss-canvas > svg')).toBeVisible();

    await expect(input(page)).toHaveValue('posts');
  });
});

// Below 1024px the picker lives inside the ⋯ panel, which is a dropdown rather
// than part of the bar. Choosing a table there is a completing action, so the
// panel goes with it; on a phone it was left covering the diagram it had just
// changed, along with the on-screen keyboard.
test.describe('committing from inside the ⋯ panel', () => {
  const more = (page) => page.locator('#truss-more');
  const moreBtn = (page) => page.locator('#truss-more-btn');

  test('closes the panel', async ({ page }) => {
    await load(page);
    await moreBtn(page).click();
    await expect(more(page)).toHaveClass(/is-open/);

    await input(page).click();
    await options(page).filter({ hasText: 'posts' }).first().click();

    await expect(more(page)).not.toHaveClass(/is-open/);
    await expect(moreBtn(page)).toHaveAttribute('aria-expanded', 'false');
  });

  // Closing the panel hides the input that has focus, so without a hand-back the
  // browser drops the user on <body>. The button that opened the panel is where
  // they were before, and is also what makes iOS dismiss the keyboard: focus
  // leaves the text field.
  test('returns focus to the button that opened it', async ({ page }) => {
    await load(page);
    await moreBtn(page).click();
    await input(page).click();

    await options(page).filter({ hasText: 'posts' }).first().click();

    await expect(moreBtn(page)).toBeFocused();
  });

  test('leaves focus in the input when the panel was never open', async ({ page }) => {
    await load(page);
    await input(page).click();

    await options(page).filter({ hasText: 'posts' }).first().click();

    // Unchanged desktop behaviour: the widget keeps DOM focus so a second click
    // reopens the list, and a keyboard user is not thrown out of the control.
    await expect(input(page)).toBeFocused();
  });
});

// A tap has no visible focus to preserve, and leaving focus in the input keeps
// the on-screen keyboard over the diagram. Read from the pointer type of the
// interaction rather than from the device, so a mouse on a touchscreen laptop
// still behaves like a mouse.
test.describe('committing with a finger', () => {
  test.use({ hasTouch: true });

  test('blurs the input so the on-screen keyboard drops', async ({ page }) => {
    await load(page);
    await input(page).click();

    await options(page).filter({ hasText: 'posts' }).first().tap();

    await expect(input(page)).toHaveValue('posts');
    await expect(input(page)).not.toBeFocused();
  });
});
