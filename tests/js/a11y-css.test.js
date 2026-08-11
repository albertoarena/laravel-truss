import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

// WCAG 2.4.7 (Focus Visible). The three in-diagram triggers are given
// role="button" and tabindex="0" by truss.js, so they are reachable by Tab.
// Without a focus style they are reachable and invisible, which is worse than
// not being reachable at all. The Playwright specs prove the behaviour; this
// guards the indicator, which a browser test cannot assert against the shipped
// stylesheet (the harness page carries its own inlined copy).
describe('in-diagram triggers have a visible focus indicator', () => {
  for (const trigger of ['truss-table-name', 'truss-enum-type', 'truss-health-marker']) {
    it(`styles .${trigger}:focus-visible`, () => {
      const pattern = new RegExp(`\\.${trigger}[^{]*:focus-visible`);
      expect(css).toMatch(pattern);
    });
  }

  // A rule that only changes colour is not an indicator for someone who cannot
  // distinguish the two colours; the focus ring has to be a shape change.
  it('draws an outline rather than only recolouring', () => {
    const block = css.match(/:focus-visible[^{]*\{[^}]*\}/g)?.join('\n') ?? '';
    expect(block).toMatch(/outline\s*:/);
  });
});
