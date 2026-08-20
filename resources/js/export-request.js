// Builds the URL for the gated server export route from the current selection.
// The dashboard no longer generates structural formats in the browser; it asks
// the server, which runs the same pipeline as the CLI and the facade, so the
// download is byte-identical everywhere. PNG and SVG stay client-side (they are
// canvas/DOM artifacts with no server form).

/**
 * @param {string} template the route with a `__format__` placeholder
 * @param {string} format dbml | json | csv | markdown | mermaid | llm
 * @param {{only?: string[], except?: string[], focus?: string, depth?: number,
 *   compact?: boolean, connection?: string}} [params]
 * @returns {string}
 */
export function buildExportUrl(template, format, params = {}) {
  // No route to call: a dashboard served as a static page, or an install that
  // turned the export route off. Returning null lets the caller say so; throwing
  // from in here left the menu item doing nothing visible at all.
  if (typeof template !== 'string' || template.trim() === '') return null;

  const url = template.replace('__format__', format);
  const query = new URLSearchParams();

  if (params.only?.length) query.set('only', params.only.join(','));
  if (params.except?.length) query.set('except', params.except.join(','));
  if (params.focus) {
    query.set('focus', params.focus);
    if (params.depth != null) query.set('depth', String(params.depth));
  }
  if (params.compact) query.set('compact', '1');
  if (params.connection) query.set('connection', params.connection);

  // URLSearchParams encodes the comma in `only`; the server decodes it before
  // splitting, so the round-trip is exact. Keep the querystring readable by
  // leaving the comma literal.
  const qs = query.toString().replace(/%2C/g, ',');

  return qs ? `${url}?${qs}` : url;
}
