import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, existsSync } from 'node:fs';
import path from 'node:path';

// The document `truss:export --format=html` writes, opened the way a reader
// opens it: over file://, with no server, no origin and nothing to fetch.
//
// This spec is the only one in the suite that drives the *shipped* markup. Every
// other spec runs against tests/e2e/harness.html, a hand-authored shell that has
// twice diverged from index.blade.php and hidden real behaviour (see the popover
// cascade and the misplaced .truss-zoom). The exported file is rendered from the
// Blade view itself, so what loads here is what users get.
//
// The fixture is built by the real command rather than checked in: a 3.6 MB
// generated artifact does not belong in git, and building it is also the only
// way this proves the pipeline instead of a snapshot of it.

// Serial, and it has to be. The config is fullyParallel, which splits a file's
// tests across workers, and beforeAll then runs once per worker: four workers
// each deleting and rebuilding the same fixture, racing over one sqlite file.
// The symptom is a migrate that fails for no visible reason.
test.describe.configure({ mode: 'serial' });

const tmp = path.resolve('tests/e2e/.tmp');
const db = path.join(tmp, 'export.sqlite');
const out = path.join(tmp, 'exported.html');

test.beforeAll(() => {
  rmSync(tmp, { recursive: true, force: true });
  mkdirSync(tmp, { recursive: true });
  // Touched rather than created by the driver: sqlite opens a missing file as a
  // new database, but the migrator wants it to exist first.
  execFileSync('touch', [db]);

  const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: db };
  execFileSync('vendor/bin/testbench',
    ['migrate', '--path=tests/Fixtures/migrations', '--realpath'], { env });
  execFileSync('vendor/bin/testbench',
    ['truss:export', '--format=html', `--output=${out}`], { env });

  if (!existsSync(out)) throw new Error('the export produced no file');
});

test.afterAll(() => rmSync(tmp, { recursive: true, force: true }));

test('renders the diagram from a file with no server', async ({ page }) => {
  const requests = [];
  const errors = [];
  page.on('request', (r) => {
    if (!r.url().startsWith('file://') && !r.url().startsWith('data:')) requests.push(r.url());
  });
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

  await page.goto(`file://${out}`);
  await expect(page.locator('#truss-canvas svg')).toBeVisible();

  // The offline promise, asserted by observation rather than by grepping the
  // document for http: the file legitimately contains namespace and licence URLs
  // inside Mermaid's own source, none of which is ever requested.
  expect(requests).toEqual([]);
  expect(errors).toEqual([]);
});

test('filters, and the filter is the shipped control', async ({ page }) => {
  await page.goto(`file://${out}`);
  await expect(page.locator('#truss-canvas svg')).toBeVisible();

  const before = await page.locator('#truss-canvas svg g.node').count();
  await page.locator('#truss-search').fill('does-not-exist-anywhere');
  await expect(page.locator('#truss-canvas svg g.node')).toHaveCount(0);

  await page.locator('#truss-search').fill('');
  await expect(page.locator('#truss-canvas svg g.node')).toHaveCount(before);
});

test('zooms, so the viewport maths survives having no origin', async ({ page }) => {
  await page.goto(`file://${out}`);
  await expect(page.locator('#truss-canvas svg')).toBeVisible();

  const start = await page.locator('#truss-canvas').evaluate((el) => getComputedStyle(el).transform);
  await page.locator('#truss-zoom-range').fill('2');
  await page.locator('#truss-zoom-range').dispatchEvent('input');

  await expect(page.locator('#truss-zoom-pct')).not.toHaveText('100%');
  expect(await page.locator('#truss-canvas').evaluate((el) => getComputedStyle(el).transform))
    .not.toBe(start);
});

test('offers no server export, because there is no server', async ({ page }) => {
  await page.goto(`file://${out}`);
  await expect(page.locator('#truss-canvas svg')).toBeVisible();

  // buildExportUrl returns null for an empty template, which is the degraded
  // state the client was already written for. This pins that the exported page
  // actually lands in it rather than offering downloads that cannot work.
  const app = page.locator('#truss-app');
  await expect(app).not.toHaveAttribute('data-export-endpoint', /./);
  await expect(app).not.toHaveAttribute('data-schema-endpoint', /./);
});
