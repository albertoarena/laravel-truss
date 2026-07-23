// Browser entry for the Truss dashboard. Thin DOM/fetch/Mermaid glue over the
// pure, unit-tested modules (selection, generator, viewport math). No build
// step: native ES module; Mermaid is a global from a <script> tag.

import { filterTables, focusTables } from './selection.js';
import { generateErDiagram } from './mermaid-definition.js';
import { clamp, fitTransform, zoomAtPoint, ZOOM_LIMITS } from './viewport.js';

const app = document.getElementById('truss-app');

const config = {
  endpoint: app.dataset.schemaEndpoint,
  connections: JSON.parse(app.dataset.connections || '[]'),
  typeLabels: app.dataset.typeLabels || 'native',
  warnAbove: Number(app.dataset.warnAbove || 60),
  focusDepth: Number(app.dataset.focusDepth || 1),
  minZoom: Number(app.dataset.minZoom || 0.4),
};

const state = {
  tables: [],
  fallback: false,
  connection: config.connections[0] ?? null,
  search: '',
  focusRoot: '',
  depth: config.focusDepth,
  laravelLabels: config.typeLabels === 'laravel',
  view: { zoom: 1, x: 0, y: 0 }, // translate(x,y) scale(zoom)
  content: { width: 0, height: 0 }, // natural SVG size
  lastKey: null, // subset signature; drives auto-fit only on content change
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
  fit: document.querySelector('[data-fit]'),
  zoomRange: document.getElementById('truss-zoom-range'),
  zoomPct: document.getElementById('truss-zoom-pct'),
};

const mermaid = window.mermaid;
mermaid.initialize({
  startOnLoad: false,
  // A neutral base; the actual entity/row/line colours are painted by our CSS
  // (resources/css/truss.css) so the diagram follows light/dark with no re-render.
  theme: 'base',
  securityLevel: 'strict',
  maxTextSize: 5_000_000, // raise the guards so large schemas render
  maxEdges: 10_000,
  er: { useMaxWidth: false },
  themeVariables: {
    fontFamily: '"IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    fontSize: '13px',
  },
});

/* ---- selection -------------------------------------------------------- */

function currentSubset() {
  let subset = filterTables(state.tables, state.search);
  if (state.focusRoot) {
    subset = focusTables(subset, state.focusRoot, state.depth);
  }
  return subset;
}

/* ---- pan / zoom ------------------------------------------------------- */

function applyTransform() {
  const { x, y, zoom } = state.view;
  el.canvas.style.transform = `translate(${x}px, ${y}px) scale(${zoom})`;
  syncZoomUi();
}

function syncZoomUi() {
  if (el.zoomRange) el.zoomRange.value = String(clamp(state.view.zoom, Number(el.zoomRange.min), Number(el.zoomRange.max)));
  if (el.zoomPct) el.zoomPct.textContent = `${Math.round(state.view.zoom * 100)}%`;
}

function viewportSize() {
  return { width: el.viewport.clientWidth, height: el.viewport.clientHeight };
}

// `minScale` floors the zoom: the auto-fit passes config.minZoom so a large
// schema stays readable (you pan), while the Fit button passes 0 to frame the
// whole diagram at once.
function fitToViewport(minScale = 0) {
  state.view = fitTransform(state.content, viewportSize(), { minScale });
  applyTransform();
}

/** Size the freshly-rendered SVG to its natural pixels so our transform drives scale. */
function normalizeSvg() {
  const svg = el.canvas.querySelector('svg');
  if (!svg) {
    state.content = { width: 0, height: 0 };
    return;
  }
  const box = svg.viewBox?.baseVal;
  const width = box?.width || svg.getBoundingClientRect().width;
  const height = box?.height || svg.getBoundingClientRect().height;

  svg.removeAttribute('style');
  svg.setAttribute('width', String(width));
  svg.setAttribute('height', String(height));
  state.content = { width, height };
}

function zoomBy(factor, point) {
  state.view = zoomAtPoint(state.view, point, factor);
  applyTransform();
}

/* ---- rendering -------------------------------------------------------- */

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
  const key = subset.map((t) => t.name).join('|');

  try {
    const { svg } = await mermaid.render('truss-graph', definition);
    el.canvas.innerHTML = svg;
    normalizeSvg();

    // Auto-fit only when the *content* changed (new tables): so filtering and
    // focusing always frame their result, but a label toggle keeps your view.
    // The auto-fit honours the readable floor; the Fit button does not.
    if (key !== state.lastKey) {
      fitToViewport(config.minZoom);
    } else {
      applyTransform();
    }
    state.lastKey = key;
  } catch (error) {
    el.canvas.replaceChildren(banner('error', `Diagram failed to render: ${error?.message ?? error}`));
  }
}

/* ---- data ------------------------------------------------------------- */

function populateFocusOptions() {
  el.focus.innerHTML = ['<option value="">— none —</option>']
    .concat(state.tables.map((t) => `<option value="${t.name}">${t.name}</option>`))
    .join('');
  el.focus.value = state.focusRoot;
}

function populateConnectionOptions() {
  if (!el.connection) return;
  el.connection.innerHTML = config.connections.map((name) => `<option value="${name}">${name}</option>`).join('');
  if (state.connection) el.connection.value = state.connection;
  el.connection.closest('.truss-field')?.toggleAttribute('hidden', config.connections.length < 2);
}

async function loadSchema() {
  const url = new URL(config.endpoint, window.location.origin);
  if (state.connection) url.searchParams.set('connection', state.connection);

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
  state.lastKey = null; // force an auto-fit for the new schema
  populateFocusOptions();
  await render();
}

/* ---- events ----------------------------------------------------------- */

function debounce(fn, ms) {
  let handle;
  return (...args) => {
    clearTimeout(handle);
    handle = setTimeout(() => fn(...args), ms);
  };
}

function pointerInViewport(event) {
  const rect = el.viewport.getBoundingClientRect();
  return { x: event.clientX - rect.left, y: event.clientY - rect.top };
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

  // Wheel zooms toward the cursor (natural, Maps-style); never scrolls the page.
  el.viewport.addEventListener('wheel', (e) => {
    e.preventDefault();
    zoomBy(e.deltaY < 0 ? 1.1 : 1 / 1.1, pointerInViewport(e));
  }, { passive: false });

  // Drag to pan. preventDefault stops the drag from selecting the SVG text.
  let pan = null;
  el.viewport.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    pan = { x: e.clientX, y: e.clientY, ox: state.view.x, oy: state.view.y };
    el.viewport.setPointerCapture(e.pointerId);
    el.viewport.classList.add('is-panning');
  });
  el.viewport.addEventListener('pointermove', (e) => {
    if (!pan) return;
    state.view = { ...state.view, x: pan.ox + (e.clientX - pan.x), y: pan.oy + (e.clientY - pan.y) };
    applyTransform();
  });
  const endPan = () => { pan = null; el.viewport.classList.remove('is-panning'); };
  el.viewport.addEventListener('pointerup', endPan);
  el.viewport.addEventListener('pointercancel', endPan);

  // Slider zooms around the viewport centre.
  el.zoomRange?.addEventListener('input', (e) => {
    const target = clamp(Number(e.target.value), ZOOM_LIMITS.min, ZOOM_LIMITS.max);
    const { width, height } = viewportSize();
    zoomBy(target / state.view.zoom, { x: width / 2, y: height / 2 });
  });

  // The explicit Fit button frames the whole diagram (no readable floor).
  el.fit?.addEventListener('click', () => fitToViewport(0));

  window.addEventListener('resize', debounce(() => applyTransform(), 200));
}

/* ---- boot ------------------------------------------------------------- */

if (el.depth) el.depth.value = String(state.depth);
if (el.labels) el.labels.checked = state.laravelLabels;
populateConnectionOptions();
wireEvents();
loadSchema();
