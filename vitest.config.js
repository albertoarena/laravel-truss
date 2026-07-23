import { defineConfig } from 'vitest/config';

// Vitest owns the pure-logic unit tests under tests/js. The browser (Playwright)
// specs under tests/e2e are a separate runner — keep them out of Vitest's scope.
export default defineConfig({
  test: {
    include: ['tests/js/**/*.test.js'],
  },
});
