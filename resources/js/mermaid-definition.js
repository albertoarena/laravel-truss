// Turns a selected subset of schema tables into a Mermaid `erDiagram` string.
// This is the shared sink of the selection pipeline: config exclusions, text
// filter, and focus mode all reduce to a table subset that arrives here.
//
// Per-column PK/FK badges are DERIVED here from `primary_key` and
// `foreign_keys[].columns` — they are not stored on columns (see docs/DESIGN.md).

import { toShortLabel } from './type-labels.js';

// ---- Identifiers -------------------------------------------------------------
// Mermaid's ER lexer rejects some legal SQL names (a leading digit, `pk`, `$`,
// whitespace, a table called `style`), and one rejected name fails the whole
// diagram (issue #94). Names are handled here, at the Mermaid sink only, and
// ONLY when Mermaid would reject them or record something else: a name that
// parses today keeps its exact output, even where markdown restyles it. The
// patterns mirror the ER lexer of the vendored Mermaid, and
// tests/js/mermaid-identifiers.test.js pins them to it, with a seeded fuzz.

// An attribute name token. Not a `u` regex on purpose: like the lexer, `/i`
// case-folds `µ` into range and an astral character is two code units in it.
const ATTRIBUTE_WORD = /^[*A-Za-z_À-￿][A-Za-z0-9\-_[\]().,À-￿*]*$/i;
const ATTRIBUTE_CHAR = /[^A-Za-z0-9\-_[\]().,À-￿*]/gi;
const ATTRIBUTE_START = /^[*A-Za-z_À-￿]/i;
// The key keywords win over a name that starts with them at a word boundary.
const KEY_KEYWORD = /^(?:PK|FK|UK)(?![A-Za-z0-9_])/i;
// The lexer's generic-type rule: two `~`, no whitespace before the first or after the last.
const GENERIC_WORD = /^\S*~.*~\S*$/;
// The lexer skips whitespace before a name, so a name that starts with any
// (U+00A0, U+3000 and U+FEFF included) loses it, or is not there at all.
const LEADING_SPACE = /^\s/;
// Mermaid swaps `#name;` and `#123;` for the character before it lexes.
const ENTITY_CODE = /#\w+;/;

// An entity name, and the grammar keywords and operators that win over it.
const ENTITY_WORD = /^(?:[^\x00-\x7F]|[A-Za-z0-9_\-*.])+$/;
const ENTITY_CHAR = /[^A-Za-z0-9_\-*.\u0080-￿]|\s/g;
const ENTITY_KEYWORD = /^(?:(?:erDiagram|classDef|class|style|one|many|to)(?![A-Za-z0-9_])|u[.-]|\.\.|\.-|--|-\.)/i;
// A leading digit lexes as a cardinality or a number; only a whole number is a name.
const ENTITY_NUMBER = /^[0-9]+(?:\.[0-9]+)?$/;
// Anywhere on a line outside an entity block, this is the layout directive and
// takes the whole line with it.
const DIRECTION = /direction\s+(?:TB|BT|RL|LR)/i;

/** Whether Mermaid records this column name verbatim. */
export function isMermaidSafeColumn(name) {
  const text = String(name);
  if (LEADING_SPACE.test(text) || KEY_KEYWORD.test(text)) return false;

  return ATTRIBUTE_WORD.test(text) || GENERIC_WORD.test(text);
}

/** Whether Mermaid records this table name verbatim, in a block and in an edge. */
export function isMermaidSafeTable(name) {
  const text = String(name);
  if (!ENTITY_WORD.test(text) || LEADING_SPACE.test(text) || ENTITY_KEYWORD.test(text) || DIRECTION.test(text)) {
    return false;
  }

  return !/^[0-9]/.test(text) || ENTITY_NUMBER.test(text);
}

/**
 * A readable token Mermaid accepts for a rejected column name: whitespace and
 * each rejected character become `_`, and `_` goes in front of a bad first
 * character or a key keyword. The text exports always use it (they carry the
 * real name in the comment); the dashboard only when backticks cannot quote.
 * Kept identical to MermaidGenerator::columnName() by a shared case list.
 */
export function mermaidReadableName(name) {
  let token = String(name).replace(/\s/g, '_').replace(ATTRIBUTE_CHAR, '_');
  if (!ATTRIBUTE_START.test(token)) token = `_${token}`;
  if (KEY_KEYWORD.test(token)) token = `_${token}`;

  return token;
}

/**
 * The attribute name the dashboard writes for a column. Mermaid 11.16 quotes an
 * attribute name in backticks, so a rejected name is shown as itself. Backticks
 * cannot hold a backtick or a carriage return, are claimed first by the generic
 * `~` rule when a `~` comes before any whitespace, and do not stop entity
 * decoding; those names fall back to the readable token.
 */
export function mermaidColumnName(name) {
  const text = String(name);
  if (isMermaidSafeColumn(text)) return text;
  if (/[`\r]/.test(text) || ENTITY_CODE.test(text) || /^\S*~.*~/.test(`\`${text}`)) {
    return mermaidReadableName(text);
  }

  return `\`${text}\``;
}

/**
 * The entity id for every table name: the name itself when Mermaid accepts it,
 * otherwise `t_<name>` with rejected characters as `_`, shown under its real
 * name through an alias. Ids that collide get `_2`, `_3` in table order, and a
 * real table name is never taken over.
 */
export function mermaidTableIds(names) {
  const ids = new Map();
  const taken = new Set(names.filter((name) => isMermaidSafeTable(name)));
  for (const name of names) {
    if (taken.has(name)) {
      ids.set(name, name);
      continue;
    }
    const base = `t_${String(name).replace(ENTITY_CHAR, '_')}`;
    let id = base;
    for (let n = 2; taken.has(id); n++) id = `${base}_${n}`;
    taken.add(id);
    ids.set(name, id);
  }

  return ids;
}

/**
 * Text for a quoted relationship label: one line, no bare quote, and no
 * `direction TB` for the lexer to mistake for the layout directive (its `i` is
 * entity-coded, decoded again when it renders).
 */
function mermaidLabel(text) {
  return String(text)
    .replace(/[\r\n]+/g, ' ')
    .replaceAll('"', '#34;')
    .replace(/(?<=d)i(?=rection\s+(?:TB|BT|RL|LR))/gi, (c) => `#${c.charCodeAt(0)};`);
}

/**
 * Text for a quoted alias label. On top of mermaidLabel, what the quoted-string
 * token cannot hold, and `#code;` text Mermaid would decode, are entity-coded.
 * The label also goes through markdown, and the dashboard finds a table by its
 * label text, so the emphasis delimiters are coded too. Only an aliased
 * (rejected) name gets here, so this changes no existing output.
 */
function mermaidText(text) {
  return mermaidLabel(String(text).replace(/["%\\~_*]|#(?=\w+;)/g, (c) => `#${c.charCodeAt(0)};`));
}

/**
 * Collapse a native type into a single Mermaid-safe attribute-type token.
 * Mermaid's ER grammar splits attributes on whitespace, so `bigint unsigned`
 * and `varchar(255)` must become one word: `bigint_unsigned`, `varchar_255`.
 *
 * `enum(...)`/`set(...)` value lists can be enormous and blow out the column
 * width, so in native mode they collapse to just the keyword; the full list is
 * surfaced on hover by the presentation layer (see truss.js). `laravel` mode
 * already reduces them to `enum` via toShortLabel.
 */
function mermaidType(nativeType, mode) {
  const label = mode === 'laravel'
    ? toShortLabel(nativeType)
    : String(nativeType).replace(/^\s*(enum|set)\b[\s\S]*$/i, '$1');

  return String(label)
    .trim()
    .replace(/[^A-Za-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '') || 'unknown';
}

/**
 * The PK/FK badge for a column, or '' when it is neither.
 */
function keyBadge(columnName, primaryKey, foreignKeyColumns) {
  const badges = [];
  if (primaryKey.includes(columnName)) {
    badges.push('PK');
  }
  if (foreignKeyColumns.has(columnName)) {
    badges.push('FK');
  }

  return badges.join(', ');
}

function entityBlock(table, mode, id) {
  const primaryKey = table.primary_key ?? [];
  const foreignKeys = table.foreign_keys ?? [];
  const fkColumns = new Set(foreignKeys.flatMap((fk) => fk.columns));
  // Columns whose FK points back at this same table. Mermaid renders self-loops
  // as a large sweeping curve, so instead of an edge we flag the column with a
  // "self-ref" note (see generateErDiagram, where the self-loop edge is skipped).
  const selfRefColumns = new Set(
    foreignKeys.filter((fk) => fk.references_table === table.name).flatMap((fk) => fk.columns),
  );

  const lines = table.columns.map((column) => {
    const type = mermaidType(column.type, mode);
    const badge = keyBadge(column.name, primaryKey, fkColumns);
    const note = selfRefColumns.has(column.name) ? ' "self-ref"' : '';

    return `    ${type} ${mermaidColumnName(column.name)}${badge ? ` ${badge}` : ''}${note}`.trimEnd();
  });
  const head = id === table.name ? id : `${id}["${mermaidText(table.name)}"]`;

  return [`  ${head} {`, ...lines, '  }'].join('\n');
}

/**
 * Reduce arbitrary text to something safe to sit inside a quoted accDescr:
 * one line, no quotes. The filter is user input and reaches the definition
 * verbatim, so a newline would terminate the directive and corrupt the rest
 * of the diagram, and a quote would break the quoted value open.
 */
function accSafe(text) {
  return String(text).replace(/["\r\n]+/g, (m) => (m.includes('"') ? '' : ' '))
    .replace(/\s+/g, ' ')
    .trim();
}

function plural(count, noun) {
  return `${count} ${count === 1 ? noun : `${noun}s`}`;
}

/**
 * The accessible name and description Mermaid renders as <title> and <desc>.
 * Without them the SVG announces itself as a graphics document with nothing
 * to say (WCAG 1.1.1). The description summarises rather than enumerating:
 * the table names are already text inside the SVG, so what a screen reader
 * user lacks is orientation, not a list.
 */
function accessibilityLines(tableCount, relationshipCount, view = {}) {
  const parts = [
    plural(tableCount, 'table'),
    relationshipCount === 0 ? 'no relationships' : plural(relationshipCount, 'relationship'),
  ];

  if (view.filter) {
    parts.push(`filtered by "${accSafe(view.filter)}"`);
  }
  if (view.focus) {
    const depth = view.depth == null ? '' : `, depth ${Number(view.depth)}`;
    parts.push(`focused on ${accSafe(view.focus)}${depth}`);
  }

  return [
    '  accTitle: Database structure diagram',
    `  accDescr: ${parts.join(', ')}.`,
  ];
}

/**
 * @param {Array} tables the selected subset (already filtered/focused)
 * @param {{ typeLabels?: 'native' | 'laravel',
 *           view?: { filter?: string, focus?: string, depth?: number } }} [options]
 * @returns {string} a Mermaid erDiagram definition
 */
export function generateErDiagram(tables, options = {}) {
  const mode = options.typeLabels === 'laravel' ? 'laravel' : 'native';
  const ids = mermaidTableIds(tables.map((table) => table.name));

  const entities = tables.map((table) => entityBlock(table, mode, ids.get(table.name)));

  // Only emit an edge when BOTH endpoints are in the subset, so focus/filter
  // never conjure a phantom entity for an out-of-scope referenced table.
  const relationships = [];
  for (const table of tables) {
    for (const fk of table.foreign_keys ?? []) {
      if (!ids.has(fk.references_table)) {
        continue;
      }
      if (fk.references_table === table.name) {
        // Self-reference: shown as a "self-ref" column note (see entityBlock),
        // not a self-loop edge, which Mermaid draws as a large sweeping curve.
        continue;
      }
      const label = mermaidLabel(fk.name || fk.columns.join('_'));
      // parent ||--o{ child : "constraint" (child is the referencing table).
      relationships.push(`  ${ids.get(fk.references_table)} ||--o{ ${ids.get(table.name)} : "${label}"`);
    }
  }

  const acc = accessibilityLines(tables.length, relationships.length, options.view);

  return ['erDiagram', ...acc, ...entities, ...relationships].join('\n');
}
