import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

// Extract the declaration block for a selector so we can assert on its rules.
function rule(selector) {
  const re = new RegExp(
    selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{[^}]*\\}'
  );
  return css.match(re)?.[0] ?? '';
}

// The toolbar and overlay labels/heads read in sentence case, matching the docs
// site, so none of them may force ALL CAPS. The source text is already sentence
// case (Filter, Focus, Laravel types, Legend, Fit, Export view), so dropping the
// uppercase transform is all it takes.
const sentenceCaseSelectors = [
  '.truss-field-label',
  '.truss-zoom button',
  '.truss-legend-head',
  '.truss-diff-head',
  '.truss-popover-head',
];

describe('label casing', () => {
  it.each(sentenceCaseSelectors)('%s does not force uppercase', (selector) => {
    const r = rule(selector);
    expect(r, `no rule found for ${selector}`).not.toBe('');
    expect(r).not.toMatch(/text-transform\s*:\s*uppercase/);
  });

  // Diff badges render their status text lowercased ("added"), so they capitalize
  // to read as "Added" rather than shouting "ADDED".
  it('diff badges capitalize rather than uppercase', () => {
    const r = rule('.truss-diff-badge');
    expect(r).not.toBe('');
    expect(r).not.toMatch(/text-transform\s*:\s*uppercase/);
    expect(r).toMatch(/text-transform\s*:\s*capitalize/);
  });
});
