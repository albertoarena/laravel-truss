import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

const appRule = css.match(/#truss-app\s*\{[^}]*\}/)?.[0] ?? '';

// The legend and the other absolutely-positioned overlays anchor to #truss-app,
// so it must establish a positioning context. Without one they fall back to the
// viewport and land over the toolbar when the dashboard is embedded below other
// chrome (e.g. an iframe or the docs demo's site bar).
describe('#truss-app positioning context', () => {
  it('is a positioned container', () => {
    expect(appRule).toMatch(/position\s*:\s*relative/);
  });
});
