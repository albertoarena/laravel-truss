// Pure serializers for a single table's structure. Structure only, never row
// data: they operate on the snapshot the browser already holds. Used by the
// per-table export menu in truss.js and unit-tested in isolation.

const CSV_HEADER = ['name', 'type', 'nullable', 'default', 'key'];

function csvCell(value) {
  const s = value == null ? '' : String(value);
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

// The full structure, pretty-printed. The table object already is the
// structure (columns, primary key, indexes, foreign keys), so this is a
// stable, copy-pasteable dump of exactly what Truss knows.
export function toJson(table) {
  return JSON.stringify(table, null, 2);
}

// A flat, spreadsheet-friendly view of the columns, with a derived key column
// (PK / FK) mirroring the badges shown in the diagram.
export function toCsv(table) {
  const pk = new Set(table.primary_key ?? []);
  const fk = new Set((table.foreign_keys ?? []).flatMap((f) => f.columns));

  const rows = [CSV_HEADER];
  for (const c of table.columns ?? []) {
    const key = [pk.has(c.name) ? 'PK' : null, fk.has(c.name) ? 'FK' : null]
      .filter(Boolean)
      .join(', ');
    rows.push([c.name, c.type, c.nullable ? 'true' : 'false', c.default ?? '', key]);
  }

  return rows.map((r) => r.map(csvCell).join(',')).join('\n');
}
