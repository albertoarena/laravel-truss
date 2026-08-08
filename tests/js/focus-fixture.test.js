// The JS half of the shared focus fixture. The PHP half is
// tests/Unit/Export/FocusTransformTest.php. Both read
// tests/Fixtures/export/focus-cases.json and must produce the same table-name
// subsets, so the interactive dashboard focus and the CLI/facade export focus
// stay in lockstep (the algorithm lives in two places because interactive focus
// cannot round-trip to the server per click).

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { focusTables } from '../../resources/js/selection.js';

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../Fixtures/export');
const fixture = JSON.parse(readFileSync(path.join(dir, 'focus-cases.json'), 'utf8'));

describe('focusTables agrees with the shared PHP/JS fixture', () => {
  for (const testCase of fixture.cases) {
    it(`focus(${testCase.root}, depth: ${testCase.depth})`, () => {
      const result = focusTables(fixture.tables, testCase.root, testCase.depth);
      expect(result.map((table) => table.name)).toEqual(testCase.expected);
    });
  }
});
