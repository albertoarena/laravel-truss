import { test, expect } from '@playwright/test';

/**
 * Native controls follow the chosen theme, not the operating system.
 *
 * `color-scheme` is what tells the browser how to paint the parts of a checkbox
 * or a scrollbar it draws itself. Declared once as `light dark`, it means "use
 * the OS preference", which is right only while the page is on auto. Force the
 * dashboard to light on a machine set to dark and the page turned light while
 * every unchecked checkbox stayed black, which is how it shipped.
 */

const scheme = (page) => page.evaluate(() => getComputedStyle(document.documentElement).colorScheme);

async function open(page, theme) {
  await page.goto('/tests/e2e/harness.html?css=1');
  await page.evaluate((t) => {
    if (t) document.documentElement.setAttribute('data-theme', t);
    else document.documentElement.removeAttribute('data-theme');
  }, theme);
}

test('paints controls light when the theme is light on a dark machine', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await open(page, 'light');

  expect(await scheme(page)).toBe('light');
});

test('paints controls dark when the theme is dark on a light machine', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await open(page, 'dark');

  expect(await scheme(page)).toBe('dark');
});

test('leaves both open when no theme is chosen, so the OS decides', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await open(page, null);

  // Auto is the default and must keep working: without a data-theme the page
  // follows prefers-color-scheme, and so should the controls.
  expect(await scheme(page)).toBe('light dark');
});
