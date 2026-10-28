import { readFileSync } from 'node:fs';
import { it, expect } from 'vitest';

// The fuzz fixture is regenerated only after a Mermaid upgrade, and the diff is
// how a reviewer sees which verdicts moved. So the generator must reproduce the
// committed file byte for byte while Mermaid is unchanged: a key order or a
// comment that differs turns every regeneration into a full rewrite and hides
// the one line that matters.
it('regenerates the committed fuzz fixture byte for byte', async () => {
  const committed = readFileSync(new URL('../Fixtures/export/mermaid-name-fuzz.json', import.meta.url), 'utf8');
  const { buildFuzzFixture } = await import('./support/mermaid-name-fuzz.mjs');

  expect(await buildFuzzFixture()).toBe(committed);
}, 60_000);
