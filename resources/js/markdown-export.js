// Pure serializers that turn the in-browser schema snapshot into a Markdown
// data dictionary. Structure only, never row data: they operate on the snapshot
// the browser already holds. Unit-tested in tests/js/markdown-export.test.js.

// Markdown table cells cannot contain a raw pipe or newline without breaking the
// row, so escape the pipe and flatten newlines.
function cell(value) {
  return (value == null ? '' : String(value)).replace(/\|/g, '\\|').replace(/\n/g, ' ');
}

// Derive the PK / FK badge for a column, mirroring the diagram and the CSV export.
function keyResolver(table) {
  const pk = new Set(table.primary_key ?? []);
  const fk = new Set((table.foreign_keys ?? []).flatMap((f) => f.columns));
  return (name) => [pk.has(name) ? 'PK' : null, fk.has(name) ? 'FK' : null].filter(Boolean).join(', ');
}

// One table as a Markdown section: heading, a column table, then index and
// foreign-key lines when present.
export function tableToMarkdown(table) {
  const key = keyResolver(table);
  const lines = [
    `## ${table.name}`,
    '',
    '| Column | Type | Null | Default | Key |',
    '| --- | --- | --- | --- | --- |',
  ];
  for (const c of table.columns ?? []) {
    lines.push(`| ${cell(c.name)} | ${cell(c.type)} | ${c.nullable ? 'yes' : 'no'} | ${cell(c.default)} | ${key(c.name)} |`);
  }

  const indexes = table.indexes ?? [];
  if (indexes.length) {
    lines.push('', 'Indexes: ' + indexes
      .map((i) => `${i.name} (${i.columns.join(', ')})${i.unique ? ' UNIQUE' : ''}`)
      .join('; '));
  }

  const fks = table.foreign_keys ?? [];
  if (fks.length) {
    lines.push('', 'Foreign keys: ' + fks.map((f) => {
      let s = `${f.columns.join(', ')} -> ${f.references_table}.${f.references_columns.join(', ')}`;
      const acts = [];
      if (f.on_delete) acts.push(`on delete: ${f.on_delete}`);
      if (f.on_update) acts.push(`on update: ${f.on_update}`);
      if (acts.length) s += ` (${acts.join(', ')})`;
      return s;
    }).join('; '));
  }

  return lines.join('\n');
}

// The whole schema (or the current selection) as one document: a title, an
// optional connection line, then one section per table.
export function schemaToMarkdown(tables, meta = {}) {
  const head = ['# Data dictionary'];
  if (meta.connection) head.push('', `Connection: ${meta.connection}`);
  const sections = (tables ?? []).map(tableToMarkdown);
  return [head.join('\n'), ...sections].join('\n\n') + '\n';
}
