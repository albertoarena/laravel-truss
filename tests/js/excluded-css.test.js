import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

/**
 * How a revealed table is drawn.
 *
 * Tables excluded by config can be shown on request, and they must not look
 * like the rest of the diagram while they are. The diff and the doctor run on
 * the filtered set, so a revealed table carries no change tint and no health
 * badge: drawn as an ordinary table it would read as one with nothing wrong,
 * rather than as one nobody checked.
 */
describe('revealed tables are drawn as guests', () => {
  const rules = css.match(/#truss-canvas svg g\.node\.truss-excluded[^{]*\{[^}]*\}/g) ?? [];

  it('mutes them', () => {
    expect(rules.join('\n')).toMatch(/opacity:\s*0\.[0-9]+/);
  });

  it('does not rely on opacity alone', () => {
    // A muted table still has to read as deliberate in a custom theme, in a
    // monochrome print, and to a reader who cannot pick the opacity difference
    // out, so the outline carries the same message.
    expect(rules.join('\n')).toContain('stroke-dasharray');
  });

  it('leaves an ordinary table alone', () => {
    // Scoped to the class, never to the node: every other table on the diagram
    // must be untouched by this.
    expect(rules.length).toBeGreaterThan(0);
    for (const rule of rules) {
      expect(rule).toContain('truss-excluded');
    }
  });
});
