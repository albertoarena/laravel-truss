// Pure helpers that turn the API `diff` field (see SchemaDiffer) into render
// models for the dashboard "Changes" view: table tint statuses, per-column
// badges, and a flat list model for the changes panel. No DOM, no fetch.
// Structure only, the diff itself never carries row data.

const EMPTY_SUMMARY = { added: 0, removed: 0, changed: 0, total: 0 };

// True only when a diff is present and actually reports changes.
export function hasDiff(diff) {
  return !!(diff && diff.has_changes);
}

// Counts of added / removed / changed tables (plus their total).
export function diffSummary(diff) {
  if (!diff) return { ...EMPTY_SUMMARY };
  const added = (diff.tables_added ?? []).length;
  const removed = (diff.tables_removed ?? []).length;
  const changed = (diff.tables_changed ?? []).length;
  return { added, removed, changed, total: added + removed + changed };
}

// Map of tableName -> 'added' | 'removed' | 'changed', for tinting diagram nodes.
// Unchanged tables are absent from the map.
export function tableStatuses(diff) {
  const map = new Map();
  if (!diff) return map;
  for (const t of diff.tables_added ?? []) map.set(t.name, 'added');
  for (const t of diff.tables_removed ?? []) map.set(t.name, 'removed');
  for (const t of diff.tables_changed ?? []) map.set(t.name, 'changed');
  return map;
}

// A single table status, or 'unchanged' when it is not in the diff.
export function tableStatus(diff, name) {
  return tableStatuses(diff).get(name) ?? 'unchanged';
}

// Map of columnName -> 'added' | 'removed' | 'changed' for one changed table.
export function columnBadges(diff, name) {
  const map = new Map();
  const table = (diff?.tables_changed ?? []).find((t) => t.name === name);
  if (!table) return map;
  for (const c of table.columns_added ?? []) map.set(c.name, 'added');
  for (const c of table.columns_removed ?? []) map.set(c.name, 'removed');
  for (const c of table.columns_changed ?? []) map.set(c.name, 'changed');
  return map;
}

// A flat, ordered list model for the "Changes" panel: added tables, then removed
// tables, then changed tables (each with their per-item details).
export function changeList(diff) {
  if (!diff) return [];
  const items = [];
  for (const t of diff.tables_added ?? []) items.push({ kind: 'table-added', table: t.name });
  for (const t of diff.tables_removed ?? []) items.push({ kind: 'table-removed', table: t.name });
  for (const t of diff.tables_changed ?? []) {
    items.push({ kind: 'table-changed', table: t.name, details: tableDetails(t) });
  }
  return items;
}

// The ordered per-table detail rows for a changed table.
function tableDetails(table) {
  const details = [];
  for (const c of table.columns_added ?? []) details.push({ kind: 'column-added', name: c.name, type: c.type });
  for (const c of table.columns_removed ?? []) details.push({ kind: 'column-removed', name: c.name });
  for (const c of table.columns_changed ?? []) details.push({ kind: 'column-changed', name: c.name, changes: c.changes });
  for (const i of table.indexes_added ?? []) details.push({ kind: 'index-added', name: i.name });
  for (const i of table.indexes_removed ?? []) details.push({ kind: 'index-removed', name: i.name });
  for (const i of table.indexes_changed ?? []) details.push({ kind: 'index-changed', name: i.name });
  for (const f of table.foreign_keys_added ?? []) details.push({ kind: 'fk-added', name: f.name });
  for (const f of table.foreign_keys_removed ?? []) details.push({ kind: 'fk-removed', name: f.name });
  for (const f of table.foreign_keys_changed ?? []) details.push({ kind: 'fk-changed', name: f.name });
  if (table.changes?.primary_key) {
    details.push({ kind: 'primary-key-changed', before: table.changes.primary_key.before, after: table.changes.primary_key.after });
  }
  return details;
}
