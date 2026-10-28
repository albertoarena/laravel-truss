// Regenerates tests/Fixtures/export/mermaid-name-fuzz.json: 400 seeded names
// over an alphabet of characters Mermaid's ER lexer treats specially, with what
// the vendored Mermaid does with each as a column and as a table. Only needed
// after a Mermaid upgrade: tests/js/mermaid-identifiers.test.js fails when a
// stored verdict no longer matches. Run from the repo root:
//   node tests/js/support/mermaid-name-fuzz.mjs
// While Mermaid is unchanged the output is byte for byte the committed file
// (tests/js/mermaid-name-fuzz.test.js), so a regeneration diff shows only the
// verdicts that moved.
import mermaid from 'mermaid';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const FIXTURE = new URL('../../Fixtures/export/mermaid-name-fuzz.json', import.meta.url);

export async function buildFuzzFixture() {
  await mermaid.parse('erDiagram\n a {\n int b\n }');
  // mulberry32
  let seed = 94; const rnd = () => { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
  const parts = ['a','b','x','p','k','f','u','P','K','1','9','0','_','-','.','*','~','`','$',' ','"','%','\\','#',';','(',')','[',']',',','{','|','\u00A0','\u3000','\uFEFF','\u2028','\n','\r','\t','é','名','😀','µ','ſ','\u212A','direction',' TB',' lr','style','one','to','pk','FK','#35;','&amp;','<b>','12','.5'];
  const names = new Set();
  while (names.size < 400) { let n=''; const len = 1 + Math.floor(rnd()*4); for (let i=0;i<len;i++) n += parts[Math.floor(rnd()*parts.length)]; names.add(n); }
  const rec = async (def) => { try { return [...(await mermaid.mermaidAPI.getDiagramFromText(def)).db.getEntities().entries()]; } catch { return null; } };
  const list = [...names];
  const columns = [];
  const tables = [];
  for (const n of list) {
    const c = await rec(`erDiagram\n  a {\n    int ${n}\n  }`);
    columns.push(!!c && c.length === 1 && c[0][1].attributes.length === 1 && c[0][1].attributes[0].name === n);
    const t = await rec(`erDiagram\n  ${n} {\n    int x\n  }\n  users ||--o{ ${n} : "x"`);
    tables.push(!!t && t.length === 2 && t.some(([id]) => id === n));
  }
  // Major and minor only, so a patch upgrade that moves no verdict moves no byte.
  const [major, minor] = JSON.parse(readFileSync(new URL('../../../node_modules/mermaid/package.json', import.meta.url), 'utf8')).version.split('.');
  const out = {
    _comment: `Seeded (94) fuzz names with what Mermaid ${major}.${minor} does with each: true when it records the name verbatim. Regenerating is only needed on a Mermaid upgrade, and tests/js/mermaid-identifiers.test.js fails if any verdict is stale.`,
    columns,
    tables,
    names: list,
  };

  return JSON.stringify(out, null, 1) + '\n';
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const json = await buildFuzzFixture();
  writeFileSync(FIXTURE, json);
  const { columns, tables } = JSON.parse(json);
  console.log(columns.filter(Boolean).length, tables.filter(Boolean).length);
}
