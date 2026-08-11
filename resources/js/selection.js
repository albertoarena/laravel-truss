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
 * The whole selection pipeline: filter first, then narrow to the focused
 * neighbourhood. The two compose, so a filter inside a focus searches within
 * that neighbourhood rather than replacing it.
 *
 * @param {Array} tables the full received set
 * @param {{search?: string, focusRoot?: string, depth?: number}} [selection]
 * @returns {Array} the subset to render
 */
export function selectTables(tables, { search = '', focusRoot = '', depth = 1 } = {}) {
  const subset = filterTables(tables, search);

  return focusRoot ? focusTables(subset, focusRoot, depth) : subset;
}

/**
 * Why the diagram is empty, and whether clearing the focus would bring it back.
 * Composing filter and focus is deliberate, but when the intersection is empty
 * the diagram looks broken: the focus control sits away from the filter box, so
 * by the time someone searches they have usually forgotten a focus is set. The
 * notice names both, and only offers to clear the focus when doing so would
 * actually show something.
 *
 * @param {Array} tables the full received set
 * @param {{search?: string, focusRoot?: string, depth?: number}} [selection]
 * @returns {?{message: string, clearFocus: boolean}} null while tables are showing
 */
export function emptySelectionNotice(tables, { search = '', focusRoot = '', depth = 1 } = {}) {
  if (selectTables(tables, { search, focusRoot, depth }).length > 0) {
    return null;
  }

  const query = String(search ?? '').trim();
  const withoutFocus = query === '' ? tables : filterTables(tables, query);

  if (focusRoot && withoutFocus.length > 0) {
    return {
      message: query === ''
        ? `No tables match focus: ${focusRoot}.`
        : `No tables match "${query}" within focus: ${focusRoot}.`,
      clearFocus: true,
    };
  }

  // Either nothing is scoped, or the filter alone already matches nothing, in
  // which case dropping the focus would leave the diagram just as empty.
  return {
    message: query === '' ? 'No tables match the current filter or focus.' : `No tables match "${query}".`,
    clearFocus: false,
  };
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
