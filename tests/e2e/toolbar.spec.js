import { test, expect } from '@playwright/test';

// Layout-only checks against the real stylesheet (tests/e2e/toolbar.html loads
// resources/css/truss.css and the real toolbar structure with every control
// active). Guards the pre-existing bug where, on a small desktop, the full
// toolbar overflowed the viewport; since it is position:sticky (vertical only),
// a horizontal drag then slid the whole header left and clipped the brand.

const URL = '/tests/e2e/toolbar.html';

const overflowsHorizontally = (page) =>
  page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);

test('wide desktop: all controls inline, no more-menu, no overflow', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 800 });
  await page.goto(URL);

  expect(await overflowsHorizontally(page)).toBe(false);
  await expect(page.locator('#truss-more-btn')).toBeHidden();
  await expect(page.locator('#truss-more')).toBeVisible();
});

test('small desktop (920px, the reported glitch band): no horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 920, height: 800 });
  await page.goto(URL);

  expect(await overflowsHorizontally(page)).toBe(false);

  // The brand stays anchored at the left edge (never scrolled/clipped away).
  const left = await page.locator('.truss-brand').evaluate((el) => el.getBoundingClientRect().left);
  expect(left).toBeGreaterThanOrEqual(0);

  // The secondary controls fold behind the more button so the bar fits.
  await expect(page.locator('#truss-more-btn')).toBeVisible();
  await expect(page.locator('#truss-more')).toBeHidden();
});

test('narrow (640px): still no overflow, controls behind the more button', async ({ page }) => {
  await page.setViewportSize({ width: 640, height: 800 });
  await page.goto(URL);

  expect(await overflowsHorizontally(page)).toBe(false);
  await expect(page.locator('#truss-more-btn')).toBeVisible();
});

test('mobile (430px): the legend is a top-anchored dropdown, not a bottom sheet', async ({ page }) => {
  await page.setViewportSize({ width: 430, height: 900 });
  await page.goto(URL);

  const box = await page.locator('#truss-legend').evaluate((el) => {
    const r = el.getBoundingClientRect();
    return { top: r.top, left: r.left, right: r.right };
  });

  // Anchored just under the toolbar like the health/changes panels, not pinned
  // to the bottom of the page.
  expect(box.top).toBeLessThan(120);
  // A right-hand dropdown panel, not a full-width bottom sheet.
  expect(box.left).toBeGreaterThan(0);
  expect(box.right).toBeLessThanOrEqual(430);
});
