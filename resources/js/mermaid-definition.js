// Turns a selected subset of schema tables into a Mermaid `erDiagram` string.
// This is the shared sink of the selection pipeline: config exclusions, text
// filter, and focus mode all reduce to a table subset that arrives here.
//
// Per-column PK/FK badges are DERIVED here from `primary_key` and
// `foreign_keys[].columns` — they are not stored on columns (see docs/DESIGN.md).

import { toShortLabel } from './type-labels.js';

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

function entityBlock(table, mode) {
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

    return `    ${type} ${column.name}${badge ? ` ${badge}` : ''}${note}`.trimEnd();
  });

  return [`  ${table.name} {`, ...lines, '  }'].join('\n');
}

/**
 * @param {Array} tables the selected subset (already filtered/focused)
 * @param {{ typeLabels?: 'native' | 'laravel' }} [options]
 * @returns {string} a Mermaid erDiagram definition
 */
export function generateErDiagram(tables, options = {}) {
  const mode = options.typeLabels === 'laravel' ? 'laravel' : 'native';
  const present = new Set(tables.map((table) => table.name));

  const entities = tables.map((table) => entityBlock(table, mode));

  // Only emit an edge when BOTH endpoints are in the subset, so focus/filter
  // never conjure a phantom entity for an out-of-scope referenced table.
  const relationships = [];
  for (const table of tables) {
    for (const fk of table.foreign_keys ?? []) {
      if (!present.has(fk.references_table)) {
        continue;
      }
      if (fk.references_table === table.name) {
        // Self-reference: shown as a "self-ref" column note (see entityBlock),
        // not a self-loop edge, which Mermaid draws as a large sweeping curve.
        continue;
      }
      const label = fk.name || fk.columns.join('_');
      // parent ||--o{ child : "constraint" (child is the referencing table).
      relationships.push(`  ${fk.references_table} ||--o{ ${table.name} : "${label}"`);
    }
  }

  return ['erDiagram', ...entities, ...relationships].join('\n');
}
