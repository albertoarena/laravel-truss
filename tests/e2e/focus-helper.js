import { expect } from '@playwright/test';

// Driving the Focus control from the specs, in one place.
//
// It used to be a native <select>, so every spec called selectOption() on it
// directly. That made sixteen assertions depend on the element's tag, and
// selectOption throws on anything that is not a select. Going through these two
// helpers means the widget can change without touching the specs that merely
// need a table focused.

/** Focus a table by name; an empty name clears the focus. */
export async function selectFocus(page, name) {
  await page.selectOption('#truss-focus', name);
}

/** Assert which table the control currently shows as focused ('' when none). */
export async function expectFocus(page, name) {
  await expect(page.locator('#truss-focus')).toHaveValue(name);
}
