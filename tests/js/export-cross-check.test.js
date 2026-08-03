// The JS half of the PHP<->JS export cross-check. Both the browser generators
// (here) and the PHP generators (tests/Unit/Export/CrossCheckTest.php) are
// pinned to the same golden fixtures under tests/Fixtures/export, so the CLI and
// the dashboard download produce byte-identical files for the same schema while
// both implementations coexist (until the Phase 3 route swap deletes these JS
// generators). Equal-to-the-same-file means equal to each other.

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { schemaToDbml } from '../../resources/js/dbml-export.js';
import { schemaToMarkdown } from '../../resources/js/markdown-export.js';
import { generateErDiagram } from '../../resources/js/mermaid-definition.js';

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../Fixtures/export');
const tables = JSON.parse(readFileSync(path.join(dir, 'schema.json'), 'utf8'));
const golden = (name) => readFileSync(path.join(dir, name), 'utf8');

describe('PHP<->JS export cross-check', () => {
  it('schemaToDbml matches the shared golden fixture', () => {
    expect(schemaToDbml(tables)).toBe(golden('schema.dbml'));
  });

  it('schemaToMarkdown matches the shared golden fixture', () => {
    expect(schemaToMarkdown(tables)).toBe(golden('schema.md'));
  });

  it('generateErDiagram matches the shared golden fixture', () => {
    expect(generateErDiagram(tables)).toBe(golden('schema.mmd'));
  });
});
