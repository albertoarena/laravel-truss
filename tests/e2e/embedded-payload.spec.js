import { test, expect } from '@playwright/test';
import { users, posts, categories } from '../js/fixtures.js';

// The dashboard rendering from a payload the page carries, with no HTTP request.
//
// Truss's own dashboard fetches its schema, and must keep fetching. A host that
// renders the diagram inside a page of its own (an admin panel, a Livewire
// component) is already holding the same array from `Truss::payload()`, so
// asking its own application for it over the network is work for nothing.
//
// This is a browser spec rather than a unit test because the claim is about what
// the page does, not about what a function returns: that the diagram draws, and
// that nothing goes out to the endpoint. The parsing rules are unit-tested in
// tests/js/schema-source.test.js.

const base = {
  connection: 'primary',
  fallback: false,
  skipped_migrations: [],
  generated_at: '2026-09-10T00:00:00Z',
  tables: [users, posts, categories],
  diff: null,
};

/**
 * Serve the harness with a payload embedded in the app container, and count
 * every request that reaches the schema endpoint.
 *
 * The embed is injected by rewriting the HTML rather than from a script, for the
 * same reason export-availability.spec.js rewrites it: truss.js reads the page
 * once at module evaluation, and a deferred module runs before any script a test
 * could add afterwards.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{payload?: string, endpoint?: boolean}} options
 * @returns {Promise<{schemaRequests: () => number}>}
 */
async function loadEmbedded(page, { payload = JSON.stringify(base), endpoint = true } = {}) {
  let schemaRequests = 0;
  page.on('request', (request) => {
    if (request.url().includes('/api/schema')) schemaRequests += 1;
  });

  // Fulfilled so a stray fetch would succeed rather than error. A spec that
  // proves "no request" by breaking the endpoint proves only that it broke it.
  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(base) }));

  await page.route('**/harness.html*', async (route) => {
    const response = await route.fetch();
    let html = await response.text();

    html = html.replace(
      '<div id="truss-banners"></div>',
      `<script type="application/json" data-truss-payload>${payload}</script>\n<div id="truss-banners"></div>`,
    );

    if (!endpoint) {
      html = html.replace(/\s*data-schema-endpoint="[^"]*"/, '');
    }

    await route.fulfill({ response, body: html, contentType: 'text/html' });
  });

  await page.goto('/tests/e2e/harness.html');

  return { schemaRequests: () => schemaRequests };
}

test('renders the diagram from a payload embedded in the page', async ({ page }) => {
  const { schemaRequests } = await loadEmbedded(page);

  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
  await expect(page.locator('#truss-canvas')).toContainText('users');
  await expect(page.locator('#truss-canvas')).toContainText('posts');

  expect(schemaRequests()).toBe(0);
});

test('needs no schema endpoint at all when the payload is embedded', async ({ page }) => {
  // The case that matters for a host page: it has no Truss route to point at,
  // so the attribute is simply absent.
  const { schemaRequests } = await loadEmbedded(page, { endpoint: false });

  await expect(page.locator('#truss-canvas > svg')).toBeVisible();
  expect(schemaRequests()).toBe(0);
});

test('keeps the interactive pipeline working on an embedded payload', async ({ page }) => {
  // The whole reason to hand the payload to this frontend rather than render a
  // static image: filter and focus still run client-side, with no refetch.
  await loadEmbedded(page);
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();

  await page.locator('#truss-search').fill('users');

  await expect(page.locator('#truss-canvas')).toContainText('users');
  await expect(page.locator('#truss-canvas')).not.toContainText('categories');
});

test('still fetches when the page embeds nothing', async ({ page }) => {
  // The package's own dashboard. The embed is opt-in and must stay so.
  let schemaRequests = 0;
  page.on('request', (request) => {
    if (request.url().includes('/api/schema')) schemaRequests += 1;
  });

  await page.route('**/api/schema**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(base) }));

  await page.goto('/tests/e2e/harness.html');
  await expect(page.locator('#truss-canvas > svg')).toBeVisible();

  expect(schemaRequests).toBeGreaterThan(0);
});
