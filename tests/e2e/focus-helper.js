import { expect } from '@playwright/test';

// Driving the Focus control from the specs, in one place.
//
// It used to be a native <select>, so every spec called selectOption() on it
// directly. That made sixteen assertions depend on the element's tag, and
// selectOption throws on anything that is not a select. Going through these two
// helpers means the widget can change without touching the specs that merely
// need a table focused. It now drives the combobox: type the name, pick the row.

/** Focus a table by name; an empty name clears the focus. */
export async function selectFocus(page, name) {
  const input = page.locator('#truss-focus');
  await input.click();
  if (name === '') {
    await page.locator('#truss-focus-list [data-clear]').click();
    return;
  }
  await input.fill(name);
  await page.locator(`#truss-focus-list [data-name="${name}"]`).click();
}

/** Assert which table the control currently shows as focused ('' when none). */
export async function expectFocus(page, name) {
  await expect(page.locator('#truss-focus')).toHaveValue(name);
}
