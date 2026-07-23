// Serialize/parse the shareable view state (connection, filter, focus, depth,
// type labels) to and from the URL query string, so a focused/filtered view can
// be bookmarked and reopened. Pure functions; truss.js wires them to the URL.

/**
 * @param {{connection?:string, filter?:string, focus?:string, depth?:number|null, labels?:boolean}} view
 * @returns {string} a query string beginning with `?`, or '' when nothing is set
 */
export function buildQuery({ connection, filter, focus, depth, labels } = {}) {
  const p = new URLSearchParams();
  if (connection) p.set('connection', connection);
  if (filter) p.set('filter', filter);
  if (focus) p.set('focus', focus);
  if (depth != null) p.set('depth', String(depth));
  if (labels) p.set('labels', 'laravel');
  const s = p.toString();
  return s ? `?${s}` : '';
}

/**
 * @param {string} search e.g. window.location.search
 * @returns {{connection:string|null, filter:string, focus:string, depth:number|null, labels:boolean}}
 */
export function parseQuery(search = '') {
  const p = new URLSearchParams(search);
  const rawDepth = p.get('depth');
  const depth = rawDepth != null && rawDepth !== '' && Number.isFinite(Number(rawDepth)) ? Number(rawDepth) : null;

  return {
    connection: p.get('connection') || null,
    filter: p.get('filter') || '',
    focus: p.get('focus') || '',
    depth,
    labels: p.get('labels') === 'laravel',
  };
}
