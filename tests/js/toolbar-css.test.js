import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

// The rule that gives every toolbar field the blueprint look: mono type, the
// field background, and a one pixel rule around it. Its selector list names the
// input types explicitly, so a control added with a new type silently renders
// with the browser's own chrome instead.
const fieldRule = css.match(/([^{}]+)\{[^}]*background:\s*var\(--bp-field\)[^}]*\}/);

describe('toolbar fields share one appearance', () => {
  it('has a field rule keyed to the input types', () => {
    expect(fieldRule).not.toBeNull();
  });

  // The Focus picker was a <select> and matched. As a combobox it is an
  // <input type="text"> (index.blade.php), which the list did not cover, so it
  // sat in the toolbar with default borders, padding and height next to fields
  // that carry the package's own.
  it('covers the Focus combobox input', () => {
    expect(fieldRule?.[1] ?? '').toMatch(/input\[type="text"\]/);
  });

  // The select it replaced was capped so a long table name could not grow the
  // bar without bound and push the rest of the toolbar off-screen. A text input
  // sizes itself from its default character count, so it needs the same bound.
  it('bounds the width of the Focus combobox input', () => {
    expect(css).toMatch(/\.truss-combo\s+input[^{]*\{[^}]*width\s*:/);
  });
});
