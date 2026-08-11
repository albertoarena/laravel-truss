// Substring matching for the Focus picker.
//
// A native <select> only jumps by prefix, so `item` never reaches `order_items`
// on a large schema (issue #39). The toolbar filter already matches substrings,
// and the two controls contradicting each other was the complaint. Deliberately
// not fuzzy: consistency with the filter is the point, and fuzzy matching would
// make the two disagree again in a new way.

/**
 * @param {Array<string|{name: string}>} tables names, or table objects
 * @param {string} query
 * @returns {Array<{name: string, start: number, end: number}>} matches, ranked;
 *   `start`/`end` bound the matched span for highlighting, or -1 for no query.
 */
export function matchTables(tables, query) {
  const needle = String(query ?? '').trim().toLowerCase();
  const names = tables.map((table) => (typeof table === 'string' ? table : table.name));

  if (!needle) {
    return names.map((name) => ({ name, start: -1, end: -1 }));
  }

  const hits = [];
  names.forEach((name, order) => {
    // indexOf, not a regular expression: the query is literal text a user typed,
    // so a dot has to mean a dot rather than "any character".
    const start = String(name).toLowerCase().indexOf(needle);
    if (start === -1) {
      return;
    }

    // Exact first, then prefixes, then matches from further in: typing `item`
    // should surface `items` above `order_items`.
    const rank = String(name).toLowerCase() === needle ? 0 : (start === 0 ? 1 : 2);
    hits.push({ name, start, end: start + needle.length, rank, order });
  });

  return hits
    .sort((a, b) => a.rank - b.rank || a.order - b.order)
    .map(({ name, start, end }) => ({ name, start, end }));
}
