import { readFileSync } from 'node:fs';
import { describe, it, expect, beforeAll } from 'vitest';
import mermaid from 'mermaid';
import {
  generateErDiagram,
  isMermaidSafeColumn,
  isMermaidSafeTable,
  mermaidColumnName,
  mermaidReadableName,
  mermaidTableIds,
} from '../../resources/js/mermaid-definition.js';

// Issue #94: a table or column name Mermaid's ER grammar rejects used to reach
// the definition verbatim, and one such name blanked the whole diagram. Names
// are now handled at the Mermaid sink, and ONLY when Mermaid would reject them
// or record something else, so every schema that parses today keeps its exact
// output.
//
// The predicates mirror Mermaid's ER lexer; the oracle tests below pin them to
// the real, vendored Mermaid, so an upgrade that changes what it accepts turns
// a test red instead of drifting quietly.

const col = (name, type = 'int') => ({ name, type, nullable: false, default: null });
const table = (name, columns, extra = {}) => ({
  name,
  columns,
  primary_key: [],
  indexes: [],
  foreign_keys: [],
  ...extra,
});

// Mermaid replaces `#code;` entities with placeholders before lexing and turns
// them back at render; decode them so a recorded label compares as text.
function decodeEntities(text) {
  return String(text)
    .replace(/ﬂ°°(\d+)¶ß/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/ﬂ°(\w+)¶ß/g, (_, name) => ({ quot: '"', amp: '&', lt: '<', gt: '>' }[name] ?? `#${name};`));
}

/**
 * What Mermaid records for a definition, or null when it rejects it. The acc*
 * directives are dropped first: Mermaid sanitises them with DOMPurify, which
 * needs a DOM that Node does not have (Playwright covers them rendered).
 */
async function record(definition) {
  const text = definition.split('\n').filter((line) => !/^\s*acc(Title|Descr):/.test(line)).join('\n');
  try {
    const diagram = await mermaid.mermaidAPI.getDiagramFromText(text);
    return [...diagram.db.getEntities().entries()].map(([id, entity]) => ({
      id,
      alias: decodeEntities(entity.alias),
      attributes: entity.attributes.map((a) => ({ name: a.name, comment: decodeEntities(a.comment) })),
    }));
  } catch {
    return null;
  }
}

/** The name Mermaid records for a column, or null when the line is rejected. */
async function recordedColumn(name) {
  const entities = await record(`erDiagram\n  a {\n    int ${name}\n  }`);
  return entities?.[0]?.attributes[0]?.name ?? null;
}

/** The id Mermaid records for a table used in an entity block AND an edge. */
async function recordedTable(name) {
  const entities = await record(`erDiagram\n  ${name} {\n    int x\n  }\n  users ||--o{ ${name} : "x"`);
  // More than two entities means the name was split into phantoms (` 1x`).
  if (entities?.length !== 2) return null;
  return entities.find((e) => e.id !== 'users')?.id ?? null;
}

// Shared with the Pest generator tests, so both sides run the same names.
const CASES = JSON.parse(readFileSync(new URL('../Fixtures/export/mermaid-name-cases.json', import.meta.url), 'utf8'));
const COLUMN_CASES = [...CASES.columns.rejected, ...CASES.columns.accepted];
const TABLE_CASES = [...CASES.tables.rejected, ...CASES.tables.accepted];
// Seeded fuzz names, with what Mermaid does with each (see support/mermaid-name-fuzz.mjs).
const FUZZ = JSON.parse(readFileSync(new URL('../Fixtures/export/mermaid-name-fuzz.json', import.meta.url), 'utf8'));

// A quoted label cannot hold a line break, so the generators write it as a space.
const shown = (name) => name.replace(/[\r\n]+/g, ' ');

beforeAll(async () => {
  // The first parse registers the diagram types getDiagramFromText needs.
  await mermaid.parse('erDiagram\n  a {\n    int b\n  }');
});

describe('the safe-name predicates agree with real Mermaid', () => {
  it.each(COLUMN_CASES)('column %j', async (name) => {
    const verbatim = (await recordedColumn(name)) === name;
    expect(isMermaidSafeColumn(name)).toBe(verbatim);
    expect(CASES.columns.accepted.includes(name)).toBe(verbatim);
  });

  it.each(TABLE_CASES)('table %j', async (name) => {
    const verbatim = (await recordedTable(name)) === name;
    expect(isMermaidSafeTable(name)).toBe(verbatim);
    expect(CASES.tables.accepted.includes(name)).toBe(verbatim);
  });
});

// Mermaid 11.16 quotes an attribute name in backticks, so the dashboard shows a
// rejected column under its real name with no rewrite. Backticks cannot quote a
// name holding a backtick, one the generic `~` rule claims first, or one with a
// `#code;` entity Mermaid would decode, so those fall back to a readable token.
describe('mermaidColumnName (the dashboard)', () => {
  it.each([
    ['429_error_date', '`429_error_date`'],
    ['pk', '`pk`'],
    ['full name', '`full name`'],
    [' x', '` x`'],
    ['a`b', 'a_b'],
    ['x~ y~ z', 'x__y__z'],
    ['a\rb', 'a_b'],
    ['x #35; y', 'x__35__y'],
  ])('writes %j as %j', (name, token) => {
    expect(mermaidColumnName(name)).toBe(token);
  });

  it.each(COLUMN_CASES.filter((name) => isMermaidSafeColumn(name)))('passes the safe %j through', (name) => {
    expect(mermaidColumnName(name)).toBe(name);
  });

  it.each([...COLUMN_CASES, ...FUZZ.names])('shows %j as its real name or a readable token', async (name) => {
    const token = mermaidColumnName(name);
    const quoted = token.startsWith('`');
    expect(await recordedColumn(token)).toBe(quoted ? name : token);
    if (!quoted && token !== name) expect(token).toBe(mermaidReadableName(name));
  });
});

// The readable token: what the text exports always use for a rejected column
// (they have no render to quote into, and older renderers may lack backticks).
describe('mermaidReadableName', () => {
  it.each(Object.entries(CASES.rewrites).filter(([name]) => name !== '_comment'))('rewrites %j to %j', (name, token) => {
    expect(mermaidReadableName(name)).toBe(token);
  });

  it.each([...COLUMN_CASES, ...FUZZ.names].filter((name) => !isMermaidSafeColumn(name)))('yields a token Mermaid records verbatim for %j', async (name) => {
    const token = mermaidReadableName(name);
    expect(await recordedColumn(token)).toBe(token);
  });
});

describe('the stored fuzz verdicts match real Mermaid, and so do the predicates', () => {
  it.each(FUZZ.names.map((name, i) => [name, i]))('%j', async (name, i) => {
    const column = (await recordedColumn(name)) === name;
    const table = (await recordedTable(name)) === name;
    expect([FUZZ.columns[i], FUZZ.tables[i]]).toEqual([column, table]);
    expect([isMermaidSafeColumn(name), isMermaidSafeTable(name)]).toEqual([column, table]);
  });
});

describe('mermaidTableIds', () => {
  it('keeps a safe name as its own id', () => {
    expect(mermaidTableIds(['users', 'città', '429']).get('users')).toBe('users');
    expect(mermaidTableIds(['users', 'città', '429']).get('città')).toBe('città');
    expect(mermaidTableIds(['users', 'città', '429']).get('429')).toBe('429');
  });

  it('gives a rejected name a t_ id', () => {
    const ids = mermaidTableIds(['429_logs', 'a b', 'style', 'one-x']);
    expect(ids.get('429_logs')).toBe('t_429_logs');
    expect(ids.get('a b')).toBe('t_a_b');
    expect(ids.get('style')).toBe('t_style');
    expect(ids.get('one-x')).toBe('t_one-x');
  });

  // Two tables sharing an id would merge into one entity, so later ones get a
  // numeric suffix in table order, and a safe name is never taken over.
  it('deduplicates colliding ids in table order, without taking a real name', () => {
    const ids = mermaidTableIds(['a$b', 't_a_b', 'a b', 'a~b']);
    expect(ids.get('t_a_b')).toBe('t_a_b');
    expect(ids.get('a$b')).toBe('t_a_b_2');
    expect(ids.get('a b')).toBe('t_a_b_3');
    expect(ids.get('a~b')).toBe('t_a_b_4');
  });
});

describe('generateErDiagram with names Mermaid rejects', () => {
  const awkward = [
    table('users', [col('id'), col('429_error_date', 'date'), col('pk'), col('full name')], {
      primary_key: ['id'],
    }),
    table('429_logs', [col('id'), col('user_id')], {
      foreign_keys: [{ name: 'fk "quoted"', columns: ['user_id'], references_table: 'users', references_columns: ['id'] }],
    }),
    table('a$b', [col('id'), col('a$b'), col('a_b')]),
    table('style', [col('id'), col('parent_id')], {
      foreign_keys: [
        { name: 'style_parent', columns: ['parent_id'], references_table: 'style', references_columns: ['id'] },
        { name: 'style_ab', columns: ['id'], references_table: 'a$b', references_columns: ['id'] },
      ],
    }),
    table('50% "off"', [col('id')]),
  ];

  it('produces a definition real Mermaid parses (the #94 regression)', async () => {
    expect(await record(generateErDiagram(awkward))).not.toBeNull();
  });

  it('keeps every real table name as the label Mermaid renders', async () => {
    const entities = await record(generateErDiagram(awkward));
    const labels = entities.map((e) => e.alias || e.id).sort();
    expect(labels).toEqual(['429_logs', '50% "off"', 'a$b', 'style', 'users'].sort());
  });

  it('aliases a rejected table name and points its edges at the alias id', () => {
    const out = generateErDiagram(awkward);
    expect(out).toContain('  t_429_logs["429#95;logs"] {');
    expect(out).toContain('  users ||--o{ t_429_logs : ');
    expect(out).toContain('  t_a_b ||--o{ t_style : "style_ab"');
  });

  it('encodes characters a quoted label cannot hold', () => {
    const out = generateErDiagram(awkward);
    expect(out).toContain('t_50___off_["50#37; #34;off#34;"] {');
    expect(out).toContain(': "fk #34;quoted#34;"');
  });

  // The label goes through markdown too, and the dashboard finds a table by its
  // label text, so an alias carries no emphasis delimiters either.
  it('encodes markdown emphasis in a dashboard alias', () => {
    const out = generateErDiagram([table('_a b_', [col('id')]), table('*x y*', [col('id')])]);
    expect(out).toContain('t__a_b_["#95;a b#95;"] {');
    expect(out).toContain('t_*x_y*["#42;x y#42;"] {');
  });

  it('quotes rejected column names, so Mermaid keeps the real ones', async () => {
    const out = generateErDiagram(awkward);
    expect(out).toMatch(/date `429_error_date`$/m);
    expect(out).toMatch(/int `pk`$/m);
    expect(out).toMatch(/int `full name`$/m);

    const entities = await record(out);
    const ab = entities.find((e) => e.alias === 'a$b');
    expect(ab.attributes.map((a) => a.name)).toEqual(['id', 'a$b', 'a_b']);
  });

  it('carries no real-name comment in the dashboard definition', () => {
    expect(generateErDiagram(awkward)).not.toContain('"429_error_date"');
  });

  // `direction TB` anywhere on an INITIAL-state line is taken as the layout
  // directive and eats the line, so one letter of it is entity-coded.
  it('keeps a direction directive out of an alias and a relationship label', async () => {
    const out = generateErDiagram([
      table('users', [col('id')]),
      table('sort direction LR', [col('user_id')], {
        foreign_keys: [{ name: 'by direction tb', columns: ['user_id'], references_table: 'users', references_columns: ['id'] }],
      }),
    ]);
    const entities = await record(out);
    expect(entities.map((e) => e.alias || e.id)).toEqual(['users', 'sort direction LR']);
  });
});

// The PHP generator (truss:export --format=mermaid, the binary, MCP) cannot be
// parsed by Mermaid in Pest, so Pest pins its output to this committed file and
// Vitest parses the same file here: PHP output is checked against real Mermaid.
describe('the PHP generator fixture parses in real Mermaid', () => {
  const fixture = () => readFileSync(new URL('../Fixtures/export/awkward-names.mmd', import.meta.url), 'utf8');

  it('is accepted', async () => {
    expect(await record(fixture())).not.toBeNull();
  });

  it('keeps every real name, as a label or a column comment', async () => {
    const entities = await record(fixture());
    const labels = entities.map((e) => e.alias || e.id).sort();
    expect(labels).toEqual(['429_logs', '50% "off"', 'a$b', 'style', 'users'].sort());

    const users = entities.find((e) => e.id === 'users');
    expect(users.attributes).toContainEqual({ name: '_429_error_date', comment: '429_error_date' });
    expect(users.attributes).toContainEqual({ name: '_pk', comment: 'pk' });
    expect(users.attributes).toContainEqual({ name: 'a__b', comment: 'a ~b' });

    const style = entities.find((e) => e.alias === 'style');
    expect(style.attributes).toContainEqual({ name: 'parent_id', comment: 'self-ref' });
    expect(style.attributes).toContainEqual({ name: '_fk', comment: 'fk, self-ref' });
  });
});

// Every case through the PHP generator: the rejected columns of one table, the
// accepted ones of another, and each table name as its own table with an edge.
describe('the PHP generator renders every name case so real Mermaid keeps it', () => {
  const entities = async () =>
    record(readFileSync(new URL('../Fixtures/export/mermaid-name-cases.mmd', import.meta.url), 'utf8'));

  it('is accepted', async () => {
    expect(await entities()).not.toBeNull();
  });

  it('keeps a rejected column name in the comment of a token Mermaid accepts', async () => {
    const rejected = (await entities()).find((e) => e.id === 'columns_rejected');
    expect(rejected.attributes.map((a) => a.comment)).toEqual(CASES.columns.rejected.map(shown));
    expect(rejected.attributes.map((a) => a.name)).toEqual(CASES.columns.rejected.map(mermaidReadableName));
  });

  it('passes an accepted column name through, with no comment', async () => {
    const accepted = (await entities()).find((e) => e.id === 'columns_accepted');
    expect(accepted.attributes).toEqual(CASES.columns.accepted.map((name) => ({ name, comment: '' })));
  });

  it('shows every table under its real name', async () => {
    const labels = (await entities()).map((e) => e.alias || e.id);
    expect(labels).toEqual(['users', 'columns_rejected', 'columns_accepted', ...TABLE_CASES.map(shown)]);
  });
});

// The fuzz names through the PHP generator: Pest pins its output to this file.
describe('the PHP generator renders every fuzz name so real Mermaid keeps it', () => {
  const entities = async () =>
    record(readFileSync(new URL('../Fixtures/export/mermaid-name-fuzz.mmd', import.meta.url), 'utf8'));

  it('is accepted', async () => {
    expect(await entities()).not.toBeNull();
  });

  it('keeps every column, verbatim or as a readable token with the real name in its comment', async () => {
    const columns = (await entities()).find((e) => e.id === 'columns');
    expect(columns.attributes).toEqual(FUZZ.names.map((name, i) => (FUZZ.columns[i]
      ? { name, comment: '' }
      : { name: mermaidReadableName(name), comment: shown(name) })));
  });

  it('shows every table under its real name', async () => {
    const labels = (await entities()).filter((e) => e.id !== 'columns' && e.id !== 'users').map((e) => e.alias || e.id);
    expect(labels).toEqual(FUZZ.names.map(shown));
  });
});
