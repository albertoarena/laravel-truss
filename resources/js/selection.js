// The client-side selection pipeline: pure reducers over the received schema
// tables (config exclusions are already applied server-side). Filter and focus
// are the same operation with different predicates and compose freely — both
// return a subset that feeds the same MermaidDefinitionGenerator, with no
// server refetch (see docs/DECISIONS.md → "Table selection").

/**
 * Text search over table names. An empty/whitespace query returns every table.
 *
 * @param {Array<{name: string}>} tables
 * @param {string} query
 * @returns {Array} the matching subset
 */
export function filterTables(tables, query) {
  const needle = String(query ?? '').trim().toLowerCase();
  if (needle === '') {
    return [...tables];
  }

  return tables.filter((table) => table.name.toLowerCase().includes(needle));
}

/**
 * A table plus its foreign-key neighbours out to `depth` hops. Neighbours are
 * followed in both directions — a table's own FKs (children → parents) and any
 * table that references it (parents → children) — so the neighbourhood is the
 * connected diagram around the root, not just its outbound edges.
 *
 * @param {Array} tables the full received set
 * @param {string} rootName the focused table
 * @param {number} [depth=1]
 * @returns {Array} the subset in the neighbourhood (empty if root is unknown)
 */
export function focusTables(tables, rootName, depth = 1) {
  const byName = new Map(tables.map((table) => [table.name, table]));
  if (!byName.has(rootName)) {
    return [];
  }

  // Undirected adjacency built from every foreign key.
  const neighbours = new Map(tables.map((table) => [table.name, new Set()]));
  const link = (a, b) => {
    if (neighbours.has(a) && neighbours.has(b)) {
      neighbours.get(a).add(b);
      neighbours.get(b).add(a);
    }
  };
  for (const table of tables) {
    for (const fk of table.foreign_keys ?? []) {
      if (fk.references_table !== table.name) {
        link(table.name, fk.references_table);
      }
    }
  }

  // Breadth-first out to `depth` hops.
  const visited = new Set([rootName]);
  let frontier = [rootName];
  for (let hop = 0; hop < depth; hop += 1) {
    const next = [];
    for (const name of frontier) {
      for (const adj of neighbours.get(name) ?? []) {
        if (!visited.has(adj)) {
          visited.add(adj);
          next.push(adj);
        }
      }
    }
    if (next.length === 0) {
      break;
    }
    frontier = next;
  }

  return tables.filter((table) => visited.has(table.name));
}
