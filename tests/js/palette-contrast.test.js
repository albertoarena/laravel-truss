import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

// WCAG 1.4.3 (Contrast Minimum) for the shipped defaults. The browser suite
// cannot answer this: its harness page inlines its own styles rather than
// loading truss.css, which is why color-contrast is excluded from the axe scan
// there. Computing the ratio from the token values needs no browser at all.
//
// The defaults are all the package can promise: truss.theme lets an app set
// arbitrary colours, so a custom palette can still fail. That caveat belongs in
// any accessibility statement, not in this file.

/** Token values, light from the first declaration and dark from the last (the
 *  media query and the [data-theme] block carry the same values). */
function token(name) {
  const all = [...css.matchAll(new RegExp(`--${name}:\\s*(#[0-9a-f]{6})`, 'gi'))].map((m) => m[1]);
  return { light: all[0], dark: all[all.length - 1] };
}

const channel = (v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);

function luminance(hex) {
  const [r, g, b] = hex.match(/\w\w/g).map((c) => channel(parseInt(c, 16) / 255));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(fg, bg) {
  const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
  return (hi + 0.05) / (lo + 0.05);
}

/** The colour a rule sets, as the token it points at. */
function colorToken(selector) {
  const rule = css.match(new RegExp(`${selector.replace(/[.\\[\]]/g, '\\$&')}\\s*\\{([^}]*)\\}`));
  return rule?.[1].match(/color:\s*var\(--([\w-]+)\)/)?.[1] ?? null;
}

describe('the Focus picker match highlight meets AA', () => {
  const panel = token('bp-panel');
  const infoBg = token('bp-info-bg');

  // Resolved through the cascade the same way the browser does: the active row
  // may override the base mark colour, and if it does not it inherits it.
  const baseMark = colorToken('.truss-combo-opt mark');
  const activeMark = colorToken('.truss-combo-opt.is-active mark') ?? baseMark;

  it('marks the match with a colour', () => {
    expect(baseMark).not.toBeNull();
  });

  for (const mode of ['light', 'dark']) {
    it(`is readable on a normal row in ${mode}`, () => {
      expect(ratio(token(baseMark)[mode], panel[mode])).toBeGreaterThanOrEqual(4.5);
    });

    // The active row swaps the background under the mark, so the ratio that
    // matters there is against the highlight, not the panel. This is the case
    // axe DevTools caught: the shipped light cyan measures 4.10:1 on it.
    it(`is readable on the active row in ${mode}`, () => {
      expect(ratio(token(activeMark)[mode], infoBg[mode])).toBeGreaterThanOrEqual(4.5);
    });
  }
});
