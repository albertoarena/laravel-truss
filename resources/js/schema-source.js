// Where the dashboard's schema comes from, and the one step that used to be
// unreachable without an HTTP request.
//
// The rest of the pipeline is already pure and importable: selection reduces
// tables to a subset, mermaid-definition turns a subset into an erDiagram
// string, viewport does the transform maths. Reading the API envelope into the
// state those run on lived inline in truss.js, so anything embedding Truss's
// diagram in a page of its own (an admin panel, a Livewire component) had to
// make an HTTP request to its own application to get at it, even though
// `Truss::payload()` hands it the same array in PHP.
//
// Two ways in, and Truss's own dashboard still uses the first:
//
//   - fetch the JSON endpoint, then readPayload() the response
//   - embed the payload in the page, then inlinePayload() it and readPayload()
//     the result, with no request at all
//
// Structure only, like everything else here: this reads what the server chose to
// send and never asks for more.

/**
 * The API envelope as the dashboard's state slice.
 *
 * Deliberately tolerant. The envelope has grown optional keys across releases
 * (`diff`, `doctor`, `cache_unavailable`, `diff_unavailable` all arrived after
 * v1.0), and a payload that predates one of them, or a hand-built one from a
 * host application, must degrade to a working diagram rather than to a crash
 * inside the loader. A blank diagram is a state the dashboard already renders;
 * a thrown TypeError leaves the page on its loading banner forever.
 *
 * @param {unknown} payload the parsed JSON envelope
 * @returns {{tables: object[], fallback: boolean, generatedAt: string|null, diff: object|null, doctor: object|null, cacheUnavailable: boolean, diffUnavailable: boolean}}
 */
export function readPayload(payload) {
  const envelope = isPlainObject(payload) ? payload : {};

  return {
    tables: Array.isArray(envelope.tables) ? envelope.tables : [],
    fallback: Boolean(envelope.fallback),
    generatedAt: envelope.generated_at ?? null,
    diff: envelope.diff ?? null,
    doctor: envelope.doctor ?? null,
    // Strictly true, never merely truthy. Both flags are notices that something
    // is broken, so a stray '0' or 'false' string in a hand-built payload must
    // not report a broken cache store to someone whose cache is fine.
    cacheUnavailable: envelope.cache_unavailable === true,
    diffUnavailable: envelope.diff_unavailable === true,
  };
}

/**
 * The payload embedded in the page, if the host embedded one.
 *
 * The contract is one element inside the app container:
 *
 *   <script type="application/json" data-truss-payload>{ ... }</script>
 *
 * A script element rather than a data attribute because a schema envelope is
 * large and an attribute is a poor place for it, and `type="application/json"`
 * because the browser will not execute it, so this stays safe under the same
 * strict CSP the rest of the package is built for.
 *
 * @param {{querySelector: (selector: string) => {textContent: string}|null}} container the app element
 * @returns {object|null} the parsed payload, or null when the page embeds none
 * @throws {Error} when a payload is embedded but is not parseable JSON
 */
export function inlinePayload(container) {
  const node = container?.querySelector('script[type="application/json"][data-truss-payload]');

  if (!node) {
    return null;
  }

  let parsed;

  try {
    parsed = JSON.parse(node.textContent ?? '');
  } catch {
    parsed = undefined;
  }

  // Loud, on purpose. Falling back to a fetch would send a host that embedded a
  // payload off to an endpoint it may not even have, and then report that 404
  // instead of the authoring mistake that actually caused it.
  if (!isPlainObject(parsed)) {
    throw new Error('The embedded Truss payload could not be parsed as a JSON object.');
  }

  return parsed;
}

function isPlainObject(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
