// Browser entry for the Truss dashboard. Thin DOM/fetch/Mermaid glue over the
// pure, unit-tested modules (selection + generator). No build step: this is a
// native ES module and Mermaid is loaded as a global from a CDN <script>.
//
// The logic worth testing lives in the imported modules and is covered by
// Vitest; this file's rendering/interaction behaviour is covered by Playwright.

import { filterTables, focusTables } from './selection.js';
import { generateErDiagram } from './mermaid-definition.js';

const app = document.getElementById('truss-app');

const config = {
  endpoint: app.dataset.schemaEndpoint,
  connections: JSON.parse(app.dataset.connections || '[]'),
  typeLabels: app.dataset.typeLabels || 'native',
  warnAbove: Number(app.dataset.warnAbove || 60),
  focusDepth: Number(app.dataset.focusDepth || 1),
};

const state = {
  tables: [],
  fallback: false,
  connection: config.connections[0] ?? null,
  search: '',
  focusRoot: '',
  depth: config.focusDepth,
  laravelLabels: config.typeLabels === 'laravel',
  zoom: 1,
};

const el = {
  connection: document.getElementById('truss-connection'),
  search: document.getElementById('truss-search'),
  focus: document.getElementById('truss-focus'),
  depth: document.getElementById('truss-depth'),
  labels: document.getElementById('truss-labels'),
  banners: document.getElementById('truss-banners'),
  viewport: document.getElementById('truss-viewport'),
  canvas: document.getElementById('truss-canvas'),
  zoom: document.querySelectorAll('[data-zoom]'),
};

const mermaid = window.mermaid;
mermaid.initialize({
  startOnLoad: false,
  theme: app.dataset.theme || 'default',
  securityLevel: 'strict',
  // Raise the guards so large schemas render instead of erroring out.
  maxTextSize: 5_000_000,
  maxEdges: 10_000,
});

/** The subset to draw: filter first, then focus operates on what's left. */
function currentSubset() {
  let subset = filterTables(state.tables, state.search);
  if (state.focusRoot) {
    subset = focusTables(subset, state.focusRoot, state.depth);
  }
  return subset;
}

function banner(kind, text) {
  const node = document.createElement('div');
  node.className = `truss-banner truss-banner--${kind}`;
  node.textContent = text;
  return node;
}

function renderBanners(subsetCount) {
  el.banners.replaceChildren();
  if (state.fallback) {
    el.banners.append(banner('warn',
      'A database connection was not available; the schema was rebuilt from an in-memory SQLite replay, so column types may be approximate.'));
  }
  const unscoped = !state.search && !state.focusRoot;
  if (unscoped && state.tables.length > config.warnAbove) {
    el.banners.append(banner('info',
      `${state.tables.length} tables — large schema. Use the filter or focus a table to keep the diagram fast and legible.`));
  }
  if (subsetCount === 0) {
    el.banners.append(banner('info', 'No tables match the current filter or focus.'));
  }
}

function applyZoom() {
  el.canvas.style.transform = `scale(${state.zoom})`;
  el.canvas.style.transformOrigin = 'top left';
}

async function render() {
  const subset = currentSubset();
  renderBanners(subset.length);

  if (subset.length === 0) {
    el.canvas.replaceChildren();
    return;
  }

  const definition = generateErDiagram(subset, {
    typeLabels: state.laravelLabels ? 'laravel' : 'native',
  });

  try {
    const { svg } = await mermaid.render('truss-graph', definition);
    el.canvas.innerHTML = svg;
    applyZoom();
  } catch (error) {
    el.canvas.replaceChildren(banner('error', `Diagram failed to render: ${error?.message ?? error}`));
  }
}

function populateFocusOptions() {
  const options = ['<option value="">— none —</option>']
    .concat(state.tables.map((t) => `<option value="${t.name}">${t.name}</option>`));
  el.focus.innerHTML = options.join('');
  el.focus.value = state.focusRoot;
}

async function loadSchema() {
  const url = new URL(config.endpoint, window.location.origin);
  if (state.connection) {
    url.searchParams.set('connection', state.connection);
  }

  el.banners.replaceChildren(banner('info', 'Loading schema…'));

  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    el.banners.replaceChildren(banner('error', `Could not load schema (HTTP ${response.status}).`));
    return;
  }

  const payload = await response.json();
  state.tables = payload.tables ?? [];
  state.fallback = Boolean(payload.fallback);
  state.focusRoot = '';
  populateFocusOptions();
  await render();
}

function populateConnectionOptions() {
  if (!el.connection) return;
  el.connection.innerHTML = config.connections
    .map((name) => `<option value="${name}">${name}</option>`)
    .join('');
  if (state.connection) {
    el.connection.value = state.connection;
  }
  // Hide the switcher entirely when there is only one connection to offer.
  el.connection.closest('.truss-field')?.toggleAttribute('hidden', config.connections.length < 2);
}

function debounce(fn, ms) {
  let handle;
  return (...args) => {
    clearTimeout(handle);
    handle = setTimeout(() => fn(...args), ms);
  };
}

function wireEvents() {
  el.connection?.addEventListener('change', (e) => {
    state.connection = e.target.value;
    loadSchema();
  });

  el.search?.addEventListener('input', debounce((e) => {
    state.search = e.target.value;
    render();
  }, 150));

  el.focus?.addEventListener('change', (e) => {
    state.focusRoot = e.target.value;
    render();
  });

  el.depth?.addEventListener('change', (e) => {
    state.depth = Math.max(0, Number(e.target.value) || 0);
    render();
  });

  el.labels?.addEventListener('change', (e) => {
    state.laravelLabels = e.target.checked;
    render();
  });

  el.zoom.forEach((btn) => btn.addEventListener('click', () => {
    const kind = btn.dataset.zoom;
    if (kind === 'in') state.zoom = Math.min(4, state.zoom + 0.2);
    else if (kind === 'out') state.zoom = Math.max(0.2, state.zoom - 0.2);
    else state.zoom = 1;
    applyZoom();
  }));
}

el.depth && (el.depth.value = String(state.depth));
el.labels && (el.labels.checked = state.laravelLabels);
populateConnectionOptions();
wireEvents();
loadSchema();
