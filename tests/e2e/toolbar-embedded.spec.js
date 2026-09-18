import { test, expect } from '@playwright/test';

// Embedded layout: a wide window whose toolbar is narrow, which is what the
// dashboard gets inside a Filament panel (a sidebar takes ~470px before the
// content area starts, so a 1280px window leaves the bar about 810px).
//
// The toolbar's responsive steps used to ask the viewport, so none of them
// fired here. Every child except the Filter field is floored at its min-content
// width, so the field absorbed the whole shortfall and rendered at a content
// width of zero: a small empty square of its own padding, not a field.
//
// Measured, never grepped. The stylesheet has looked correct throughout; what
// was wrong is the box it produced in a narrow container, and a test that read
// the CSS would have passed the whole time.

const URL = '/tests/e2e/toolbar.html';

/** Content width of an input: clientWidth less its own padding. */
const contentWidth = (page, selector) =>
  page.locator(selector).evaluate((el) => {
    const cs = getComputedStyle(el);
    return Math.round(el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight));
  });

const overflows = (page) =>
  page.locator('.truss-toolbar').evaluate(
    (el) => el.scrollWidth > Math.round(el.getBoundingClientRect().width) + 1
  );

/** A wide window with the bar constrained to `width`, the embedded shape. */
async function embed(page, width) {
  await page.setViewportSize({ width: 1600, height: 900 });
  await page.goto(URL);
  await page.addStyleTag({ content: `#truss-app { width: ${width}px; }` });
}

// Worth pinning at more than one width: the three rows behave differently, and
// the field was at zero across a band rather than at a single size.
for (const width of [1100, 900, 810]) {
  test(`bar constrained to ${width}px: the Filter field keeps a usable box`, async ({ page }) => {
    await embed(page, width);

    await expect.poll(() => contentWidth(page, '#truss-search')).toBeGreaterThan(60);
  });

  test(`bar constrained to ${width}px: the bar fits without overflowing`, async ({ page }) => {
    await embed(page, width);

    await expect.poll(() => overflows(page)).toBe(false);
  });
}

test('a bar under 1024 folds the secondary controls away, as a narrow window does', async ({ page }) => {
  await embed(page, 810);

  await expect(page.locator('#truss-more-btn')).toBeVisible();
  await expect(page.locator('#truss-more')).toBeHidden();
});

// The steps must key off the bar, not the window, in both directions: a roomy
// bar in a wide window keeps every control inline.
test('a roomy bar in a wide window keeps every control inline', async ({ page }) => {
  await embed(page, 1440);

  await expect(page.locator('#truss-more-btn')).toBeHidden();
  await expect(page.locator('#truss-more')).toBeVisible();
  expect(await contentWidth(page, '#truss-search')).toBe(130);
});
