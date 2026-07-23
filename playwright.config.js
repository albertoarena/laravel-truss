import { defineConfig, devices } from '@playwright/test';

// Drives the real client (resources/js/truss.js) in a headless browser against
// a mocked /api/schema, verifying the browser-only concerns Vitest can't:
// actual Mermaid rendering, filter/focus/label interaction, zoom, connection
// switching, and the fallback / large-schema banners.
export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  fullyParallel: true,
  use: {
    baseURL: 'http://localhost:5178',
  },
  webServer: {
    command: 'node tests/e2e/serve.mjs',
    url: 'http://localhost:5178/tests/e2e/harness.html',
    reuseExistingServer: true,
    timeout: 20_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
