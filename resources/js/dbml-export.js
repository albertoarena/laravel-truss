// Pure DBML serializer for the in-browser schema snapshot. Structure only,
// never row data. Whole-schema: a Table block per table, then Ref lines for the
// foreign keys. Opens in dbdiagram.io and other DBML tools. Unit-tested in
// tests/js/dbml-export.test.js.
//
// Type mapping is deliberately lossy: the native type is passed through, quoted
// only when it would otherwise misparse (same stance as the native-vs-Laravel
// label caveat in docs/DESIGN.md).

function typeToken(type) {
  const t = type == null ? '' : String(type);
  return /[\s,"']/.test(t) ? `"${t.replace(/"/g, '\\"')}"` : t;
}

function defaultToken(value) {
  const s = String(value);
  if (/^-?\d+(\.\d+)?$/.test(s)) return s;                       // number, bare
  if (/^(true|false|null)$/i.test(s)) return s.toLowerCase();    // boolean/null keyword
  if (/^current_timestamp/i.test(s) || /\(/.test(s)) return `\`${s}\``; // SQL expression
  return `'${s.replace(/'/g, "\\'")}'`;                          // string literal
}

function columnLine(table, col) {
  const pk = table.primary_key ?? [];
  const solePk = pk.length === 1 && pk[0] === col.name;
  const parts = [];
  // A composite PK is expressed in the indexes block, not on the column, so only
  // a sole PK gets [pk]; pk implies not null, so do not also emit it there.
  if (solePk) parts.push('pk');
  else if (!col.nullable) parts.push('not null');
  if (col.default != null) parts.push(`default: ${defaultToken(col.default)}`);
  const settings = parts.length ? ` [${parts.join(', ')}]` : '';
  return `  ${col.name} ${typeToken(col.type)}${settings}`;
}

function tableToDbml(table) {
  const lines = [`Table ${table.name} {`];
  for (const c of table.columns ?? []) lines.push(columnLine(table, c));

  const idx = [];
  const pk = table.primary_key ?? [];
  if (pk.length > 1) idx.push(`    (${pk.join(', ')}) [pk]`);
  for (const i of table.indexes ?? []) {
    const cols = i.columns.length > 1 ? `(${i.columns.join(', ')})` : i.columns[0];
    idx.push(`    ${cols}${i.unique ? ' [unique]' : ''}`);
  }
  if (idx.length) lines.push('', '  indexes {', ...idx, '  }');

  lines.push('}');
  return lines.join('\n');
}

// Ref lines are emitted only when the referenced table is also in the export
// set, so a filtered/focused export never produces a dangling (invalid) Ref.
function refLines(tables) {
  const present = new Set(tables.map((t) => t.name));
  const refs = [];
  for (const t of tables) {
    for (const f of t.foreign_keys ?? []) {
      if (!present.has(f.references_table)) continue;
      const src = f.columns.length > 1 ? `(${f.columns.join(', ')})` : f.columns[0];
      const tgt = f.references_columns.length > 1 ? `(${f.references_columns.join(', ')})` : f.references_columns[0];
      refs.push(`Ref: ${t.name}.${src} > ${f.references_table}.${tgt}`);
    }
  }
  return refs;
}

export function schemaToDbml(tables) {
  const list = tables ?? [];
  return [...list.map(tableToDbml), ...refLines(list)].join('\n\n') + '\n';
}
