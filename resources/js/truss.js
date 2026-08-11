// Browser entry for the Truss dashboard. Thin DOM/fetch/Mermaid glue over the
// pure, unit-tested modules (selection, generator, viewport math). No build
// step: native ES module; Mermaid is a global from a <script> tag.

import { selectTables, emptySelectionNotice } from './selection.js';
import { generateErDiagram } from './mermaid-definition.js';
import { clamp, fitTransform, zoomAtPoint, ZOOM_LIMITS } from './viewport.js';
import { buildQuery, parseQuery } from './url-state.js';
import { buildExportUrl } from './export-request.js';
import { hasDiff, changeList, tableStatuses } from './diff-view.js';
import { hasDoctor, doctorSummary, worstSeverity, tableBadges, findingGroups, columnMarkers } from './doctor-view.js';

const app = document.getElementById('truss-app');

const config = {
  endpoint: app.dataset.schemaEndpoint,
  exportEndpoint: app.dataset.exportEndpoint,
  connections: JSON.parse(app.dataset.connections || '[]'),
  typeLabels: app.dataset.typeLabels || 'native',
  warnAbove: Number(app.dataset.warnAbove || 60),
  focusDepth: Number(app.dataset.focusDepth || 1),
  minZoom: Number(app.dataset.minZoom || 0.4),
  flagTables: app.dataset.doctorFlagTables !== '0', // passive always-on health flags on the diagram
};

// Seed the initial view from the URL query string so a shared/bookmarked link
// (e.g. ?focus=projects&filter=...) opens in that state.
const urlView = parseQuery(window.location.search);

const state = {
  tables: [],
  fallback: false,
  generatedAt: null,
  connection: (urlView.connection && config.connections.includes(urlView.connection))
    ? urlView.connection
    : (config.connections[0] ?? null),
  search: urlView.filter,
  focusRoot: '',
  pendingFocus: urlView.focus, // applied once the schema is loaded and validated
  depth: urlView.depth != null ? Math.max(0, urlView.depth) : config.focusDepth,
  laravelLabels: urlView.labels || config.typeLabels === 'laravel',
  view: { zoom: 1, x: 0, y: 0 }, // translate(x,y) scale(zoom)
  content: { width: 0, height: 0 }, // natural SVG size
  lastKey: null, // subset signature; drives auto-fit only on content change
  diff: null, // the schema diff from the API (null when disabled or no baseline)
  cacheUnavailable: false, // the snapshot was read live (the cache store is broken)
  diffUnavailable: false, // the diff baseline could not be read (a disk problem)
  diffMode: false, // "Changes" view: tint changed tables and show the panel
  doctor: null, // the doctor report from the API (null when the panel is disabled)
  doctorMode: false, // "Health" view: badge tables with findings and show the panel
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
  zoom: document.getElementById('truss-zoom'),
  fit: document.querySelector('[data-fit]'),
  zoomRange: document.getElementById('truss-zoom-range'),
  zoomPct: document.getElementById('truss-zoom-pct'),
  more: document.getElementById('truss-more'),
  moreBtn: document.getElementById('truss-more-btn'),
  legend: document.getElementById('truss-legend'),
  legendBtn: document.getElementById('truss-legend-btn'),
  diffBtn: document.getElementById('truss-diff-btn'),
  diffPanel: document.getElementById('truss-diff-panel'),
  healthBtn: document.getElementById('truss-health-btn'),
  healthPanel: document.getElementById('truss-health-panel'),
  healthCount: document.getElementById('truss-health-count'),
  healthMaxBtn: document.getElementById('truss-health-max-btn'),
  themeBtn: document.getElementById('truss-theme-btn'),
  exportBtn: document.getElementById('truss-export-btn'),
  statTables: document.getElementById('truss-stat-tables'),
  statConn: document.getElementById('truss-stat-conn'),
  statFallback: document.getElementById('truss-stat-fallback'),
  statUpdated: document.getElementById('truss-stat-updated'),
  popover: document.getElementById('truss-popover'),
};

const mermaid = window.mermaid;
mermaid.initialize({
  startOnLoad: false,
  // Neutral base; the entity/row/line colours are painted by our CSS so the
  // diagram follows light/dark with no re-render.
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

function currentSelection() {
  return { search: state.search, focusRoot: state.focusRoot, depth: state.depth };
}

function currentSubset() {
  return selectTables(state.tables, currentSelection());
}

/** Drop the focus but keep the filter, so the search the user just typed applies. */
function clearFocus() {
  state.focusRoot = '';
  if (el.focus) el.focus.value = '';
  render();
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

/**
 * Re-draw each entity's outline on top of its row rects (issue #35).
 *
 * Mermaid draws the outer path first, then a full rect per row. Those row rects
 * share the entity's left and right edges and are stroked in the pale hairline
 * colour, so they repaint the outline everywhere except above the first row: the
 * dark border survives only around the title block, on three sides. SVG has no
 * z-index, and CSS cannot stroke two sides of a closed path, so the fix is paint
 * order. Cloning the outline path and appending it last gives one continuous
 * border at one width, and because the clone keeps the `outer-path` class the
 * focused, diff and health overrides reach it with no extra rules.
 */
function raiseEntityBorders() {
  const svg = el.canvas.querySelector('svg');
  if (!svg) return;

  for (const node of svg.querySelectorAll('g.node')) {
    // Re-rendering replaces the whole SVG, but a defensive clear keeps this
    // idempotent if it is ever called twice against the same node.
    node.querySelectorAll('g.truss-entity-outline').forEach((old) => old.remove());

    // The stroked half of the outer path (the other one only carries the fill).
    const stroked = node.querySelector('g.outer-path path[fill="none"]');
    if (!stroked) continue;

    const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    group.setAttribute('class', 'outer-path truss-entity-outline');
    group.append(stroked.cloneNode(true));
    node.append(group);
  }
}

function zoomBy(factor, point) {
  state.view = zoomAtPoint(state.view, point, factor);
  applyTransform();
}

/** The rendered SVG node for a table by name (g.label.name holds the entity name). */
function findTableNode(name) {
  const svg = el.canvas.querySelector('svg');
  if (!svg || !name) return null;
  for (const node of svg.querySelectorAll('g.node')) {
    const label = node.querySelector('g.label.name .nodeLabel');
    if (label && label.textContent.trim() === name) return node;
  }
  return null;
}

/** The values of an enum/set native type, or null. */
function enumValues(native) {
  const m = /^\s*(?:enum|set)\s*\((.*)\)\s*$/is.exec(native);
  if (!m) return null;
  return m[1].split(',').map((v) => v.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);
}

/** A hover tooltip for a native type: the full type, with enum/set values listed. */
function typeTooltip(native) {
  const values = enumValues(native);
  if (!values) return native;
  return `${native.trim().slice(0, 4).toLowerCase()} (${values.length}):\n` + values.map((v) => `• ${v}`).join('\n');
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * Drop a small eye button into an enum/set row's type cell (right after the
 * `enum` label) to reveal its values. The type cell is used rather than the
 * keys cell because the latter is often empty and too narrow — the button would
 * overflow it and become unclickable.
 */
/**
 * Per-render annotations on the diagram: a hover title on every type cell (the
 * full native type — the label is compacted), and, for enum/set columns, the
 * `enum` label is turned into a clickable accent that opens a popover of the
 * allowed values (works on touch, unlike the hover title). The label itself is
 * used as the trigger — it is real, correctly-sized foreignObject content, so
 * it stays clickable, unlike a button injected into the clipped cell.
 */
function annotateColumnTypes(tables) {
  const byName = new Map(tables.map((t) => [t.name, t]));
  const svg = el.canvas.querySelector('svg');
  if (!svg) return;
  for (const node of svg.querySelectorAll('g.node')) {
    const nameLabel = node.querySelector('g.label.name .nodeLabel');
    const name = nameLabel?.textContent.trim();
    const table = byName.get(name);
    if (!table) continue;
    // The table name doubles as the trigger for the per-table export/focus menu.
    nameLabel.classList.add('truss-table-name');
    nameLabel.dataset.table = table.name;
    nameLabel.setAttribute('role', 'button');
    nameLabel.setAttribute('tabindex', '0');
    nameLabel.setAttribute('title', `Export or focus ${table.name}`);
    const typeCells = node.querySelectorAll('g.label.attribute-type');
    table.columns.forEach((col, i) => {
      const label = typeCells[i]?.querySelector('.nodeLabel');
      if (!label) return;
      label.setAttribute('title', typeTooltip(col.type));
      const values = enumValues(col.type);
      if (values) {
        label.classList.add('truss-enum-type');
        label.dataset.values = JSON.stringify(values);
        label.setAttribute('role', 'button');
        label.setAttribute('tabindex', '0');
      }
    });
  }
}

// The single floating popover is shared: enum/set value lists anchor to a type
// label, the per-table export/focus menu anchors to a table name.
let openAnchor = null;
let menuTable = null;

// Position the shared popover next to an anchor. `placePopover` does the raw
// placement; `positionPopover` first closes the other overlays (so a toolbar or
// table menu is the only thing open). Finding markers use `placePopover` so their
// detail popover can sit alongside the open Health panel.
function placePopover(anchor) {
  el.popover.hidden = false;
  const r = anchor.getBoundingClientRect();
  const w = el.popover.offsetWidth;
  const h = el.popover.offsetHeight;
  const left = Math.max(8, Math.min(r.left, window.innerWidth - w - 10));
  const top = r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6;
  el.popover.style.left = `${left}px`;
  el.popover.style.top = `${top}px`;
  openAnchor = anchor;
}

function positionPopover(anchor) {
  closeOverlays('popover');
  placePopover(anchor);
}

// The export menu is a toolbar overlay, so it aligns to the top-right cluster
// with the Legend/Changes/Health panels instead of hanging off its button.
function positionCluster(anchor) {
  closeOverlays('popover');
  el.popover.hidden = false;
  const host = app.getBoundingClientRect();
  const w = el.popover.offsetWidth;
  el.popover.style.left = `${host.right - w - 14}px`;
  el.popover.style.top = `${host.top + 62}px`;
  openAnchor = anchor;
}

function showEnumPopover(anchor) {
  const values = JSON.parse(anchor.dataset.values || '[]');
  menuTable = null;
  el.popover.innerHTML = `<div class="truss-popover-head">enum · ${values.length}</div>`
    + `<ul>${values.map((v) => `<li>${escapeHtml(v)}</li>`).join('')}</ul>`;
  positionPopover(anchor);
}

function showTableMenu(anchor, table) {
  menuTable = table;
  // When this table is already the focus, "Focus" would be a no-op, so offer the
  // inverse instead of a redundant item.
  const focusItem = state.focusRoot === table.name
    ? '<button type="button" data-act="unfocus">Unfocus this table</button>'
    : '<button type="button" data-act="focus">Focus this table</button>';
  el.popover.innerHTML = `<div class="truss-popover-head">${escapeHtml(table.name)}</div>`
    + '<div class="truss-menu">'
    + focusItem
    + '<button type="button" data-act="copy">Copy JSON</button>'
    + '<button type="button" data-act="json">Download JSON</button>'
    + '<button type="button" data-act="csv">Download CSV</button>'
    + '<button type="button" data-act="table-md">Download Markdown</button>'
    + '</div>';
  positionPopover(anchor);
}

function hidePopover() {
  if (el.popover) el.popover.hidden = true;
  openAnchor = null;
  menuTable = null;
  el.exportBtn?.setAttribute('aria-expanded', 'false');
}

// The element to hand focus back to when the popover closes. Set only when the
// popover was opened from the keyboard: closing on a click should not yank the
// caret around, but closing with Escape must return a keyboard user to where
// they were, or they are dropped at the top of the document (WCAG 2.1.2).
let restoreFocusTo = null;

function dismissPopover() {
  const anchor = restoreFocusTo;
  hidePopover();
  restoreFocusTo = null;
  anchor?.focus();
}

function downloadBlob(name, blob) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function downloadFile(name, content, mime) {
  downloadBlob(name, new Blob([content], { type: mime }));
}

// Structural formats are generated server-side (the same pipeline as the CLI and
// the facade), so a download fetches the gated export route and saves the body.
// PNG and SVG stay client-side. The extension and MIME per format:
const EXPORT_EXT = { dbml: 'dbml', json: 'json', csv: 'csv', markdown: 'md', mermaid: 'mmd', llm: 'txt' };
const EXPORT_MIME = {
  dbml: 'text/plain', json: 'application/json', csv: 'text/csv',
  markdown: 'text/markdown', mermaid: 'text/plain', llm: 'text/plain',
};

// Fetch the export for a table set and save it. `only` empty means the whole
// (already exclusion-filtered) schema.
async function fetchExport(format, only) {
  const url = buildExportUrl(config.exportEndpoint, format, { only, connection: state.connection });
  const res = await fetch(url, { headers: { Accept: 'text/plain' } });
  return res.ok ? res.text() : null;
}

async function downloadExport(format, only, baseName) {
  const content = await fetchExport(format, only);
  if (content !== null) downloadFile(`${baseName}.${EXPORT_EXT[format]}`, content, EXPORT_MIME[format]);
}

function runMenuAction(act) {
  const table = menuTable;
  hidePopover();
  if (act === 'png') { exportImage('png'); return; }
  if (act === 'svg') { exportImage('svg'); return; }
  // Whole-diagram structural exports: the current (filtered/focused) subset.
  if (act === 'md') { downloadExport('markdown', subsetNames(), exportBaseName()); return; }
  if (act === 'dbml') { downloadExport('dbml', subsetNames(), exportBaseName()); return; }
  if (!table) return;
  if (act === 'focus' || act === 'unfocus') {
    state.focusRoot = act === 'focus' ? table.name : '';
    if (el.focus) el.focus.value = state.focusRoot;
    render();
  } else if (act === 'copy') {
    fetchExport('json', [table.name]).then((json) => { if (json !== null) navigator.clipboard?.writeText(json); });
  } else if (act === 'json') {
    downloadExport('json', [table.name], table.name);
  } else if (act === 'csv') {
    downloadExport('csv', [table.name], table.name);
  } else if (act === 'table-md') {
    downloadExport('markdown', [table.name], table.name);
  }
}

/** The names of the tables currently in view, for a whole-diagram export. */
function subsetNames() {
  return currentSubset().map((t) => t.name);
}

/* ---- diagram export --------------------------------------------------- */

const SVG_NS = 'http://www.w3.org/2000/svg';

// Inline the shipped IBM Plex Mono weights as base64 @font-face rules, so an
// exported SVG (whose labels are re-rendered as <text>) carries its own font
// instead of depending on one being installed wherever the file is opened.
async function embedFontCss() {
  const href = [...document.querySelectorAll('link[rel="stylesheet"]')]
    .map((l) => l.href).find((h) => /truss\.css(\?|$)/.test(h));
  if (!href) return '';
  const faces = await Promise.all(['400', '600'].map(async (weight) => {
    try {
      const url = new URL(`ibm-plex-mono-${weight}.woff2`, href).href;
      const bytes = new Uint8Array(await (await fetch(url)).arrayBuffer());
      let bin = '';
      for (let i = 0; i < bytes.length; i += 1) bin += String.fromCharCode(bytes[i]);
      return `@font-face{font-family:'IBM Plex Mono';font-weight:${weight};font-style:normal;`
        + `src:url(data:font/woff2;base64,${btoa(bin)}) format('woff2');}`;
    } catch {
      return '';
    }
  }));
  return faces.join('');
}

// Build a standalone, self-contained SVG of the current diagram. The rendered
// SVG relies on external CSS for colours and uses HTML labels in foreignObjects
// (which do not rasterise and are unsupported by most vector tools), so we
// inline the shape colours, flatten every label to a positioned <text> (mapped
// through the live CTM, so pan/zoom is irrelevant), embed the font, and paint a
// solid themed background. Returns { markup, width, height } or null.
async function buildExportSvg() {
  const live = el.canvas.querySelector('svg');
  if (!live) return null;
  const vb = live.viewBox.baseVal;
  const clone = live.cloneNode(true);

  const liveShapes = live.querySelectorAll('rect, path, line, polygon');
  const cloneShapes = clone.querySelectorAll('rect, path, line, polygon');
  liveShapes.forEach((node, i) => {
    const cs = getComputedStyle(node);
    const target = cloneShapes[i];
    target.setAttribute('fill', cs.fill);
    if (cs.stroke !== 'none') target.setAttribute('stroke', cs.stroke);
    const strokeWidth = cs.strokeWidth.replace('px', '');
    if (strokeWidth) target.setAttribute('stroke-width', strokeWidth);
    if (cs.strokeDasharray !== 'none') target.setAttribute('stroke-dasharray', cs.strokeDasharray);
  });

  // The foreignObject box is tight to its text, so a start-anchored,
  // vertically-centred <text> at the box origin lines up with the layout.
  const toUser = live.getScreenCTM().inverse();
  const textLayer = document.createElementNS(SVG_NS, 'g');
  for (const fo of live.querySelectorAll('foreignObject')) {
    const text = fo.textContent.trim();
    if (!text) continue;
    const styled = fo.querySelector('.nodeLabel') || fo.querySelector('.edgeLabel') || fo.lastElementChild || fo;
    const cs = getComputedStyle(styled);
    const rect = fo.getBoundingClientRect();
    const a = new DOMPoint(rect.left, rect.top).matrixTransform(toUser);
    const b = new DOMPoint(rect.right, rect.bottom).matrixTransform(toUser);
    const t = document.createElementNS(SVG_NS, 'text');
    t.setAttribute('x', a.x.toFixed(1));
    t.setAttribute('y', ((a.y + b.y) / 2).toFixed(1));
    t.setAttribute('text-anchor', 'start');
    t.setAttribute('dominant-baseline', 'central');
    t.setAttribute('fill', cs.color);
    t.setAttribute('font-size', cs.fontSize);
    t.setAttribute('font-weight', cs.fontWeight);
    t.setAttribute('font-family', "'IBM Plex Mono', ui-monospace, monospace");
    t.textContent = text;
    textLayer.appendChild(t);
  }
  clone.querySelectorAll('foreignObject').forEach((fo) => fo.remove());
  clone.appendChild(textLayer);

  const bg = document.createElementNS(SVG_NS, 'rect');
  bg.setAttribute('x', vb.x);
  bg.setAttribute('y', vb.y);
  bg.setAttribute('width', vb.width);
  bg.setAttribute('height', vb.height);
  bg.setAttribute('fill', getComputedStyle(el.viewport).backgroundColor);
  clone.insertBefore(bg, clone.firstChild);

  const style = document.createElementNS(SVG_NS, 'style');
  style.textContent = await embedFontCss();
  clone.insertBefore(style, clone.firstChild);
  clone.setAttribute('xmlns', SVG_NS);

  return { markup: new XMLSerializer().serializeToString(clone), width: vb.width, height: vb.height };
}

function rasterize({ markup, width, height }, scale = 2) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(new Blob([markup], { type: 'image/svg+xml' }));
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(width * scale);
      canvas.height = Math.round(height * scale);
      const ctx = canvas.getContext('2d');
      ctx.scale(scale, scale);
      ctx.drawImage(img, 0, 0);
      URL.revokeObjectURL(url);
      resolve(canvas);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('export render failed')); };
    img.src = url;
  });
}

function exportBaseName() {
  return state.focusRoot ? `truss-${state.focusRoot}` : 'truss-schema';
}

async function exportImage(format) {
  const svg = await buildExportSvg();
  if (!svg) return;
  if (format === 'svg') {
    downloadFile(`${exportBaseName()}.svg`, svg.markup, 'image/svg+xml');
    return;
  }
  const canvas = await rasterize(svg, 2);
  canvas.toBlob((blob) => { if (blob) downloadBlob(`${exportBaseName()}.png`, blob); }, 'image/png');
}

function showExportMenu(anchor) {
  menuTable = null;
  el.popover.innerHTML = '<div class="truss-popover-head">Export view</div>'
    + '<div class="truss-menu">'
    + '<button type="button" data-act="png">Export PNG</button>'
    + '<button type="button" data-act="svg">Export SVG</button>'
    + '<button type="button" data-act="md">Data dictionary (Markdown)</button>'
    + '<button type="button" data-act="dbml">DBML</button>'
    + '</div>';
  positionCluster(anchor);
}

/** Flag the focused table's node so CSS can highlight it (cleared otherwise). */
function markFocusedTable() {
  const svg = el.canvas.querySelector('svg');
  if (!svg) return;
  svg.querySelectorAll('g.node.truss-focused').forEach((n) => n.classList.remove('truss-focused'));
  findTableNode(state.focusRoot)?.classList.add('truss-focused');
}

/** Centre of the focused table's rendered node, in content coordinates (or null). */
function focusedTableCenter() {
  const node = findTableNode(state.focusRoot);
  if (!node) return null;
  try {
    const bb = node.getBBox();
    const m = node.getCTM(); // node user space → svg (viewBox = content) space
    if (!m) return null;
    const cx = bb.x + bb.width / 2;
    const cy = bb.y + bb.height / 2;
    return { x: m.a * cx + m.c * cy + m.e, y: m.b * cx + m.d * cy + m.f };
  } catch {
    return null;
  }
}

/** Pan so the focused table sits at the viewport centre, keeping the fit zoom. */
function centerOnFocusedTable() {
  const c = focusedTableCenter();
  if (!c) return;
  const vp = viewportSize();
  state.view = {
    ...state.view,
    x: vp.width / 2 - c.x * state.view.zoom,
    y: vp.height / 2 - c.y * state.view.zoom,
  };
  applyTransform();
}

/* ---- schema diff ("Changes" view) ------------------------------------- */

// Show the Changes button only when the response carried a diff (the feature is
// on and a baseline exists). When it did not, make sure the view is off.
function updateDiffAvailability() {
  const available = state.diff != null;
  el.diffBtn?.toggleAttribute('hidden', !available);
  if (!available && state.diffMode) closeDiff();
}

function toggleDiff() {
  if (state.diff == null) return;
  state.diffMode ? closeDiff() : openDiff();
}

function openDiff() {
  closeOverlays('diff');
  state.diffMode = true;
  el.diffBtn?.setAttribute('aria-expanded', 'true');
  renderDiffPanel();
  el.diffPanel?.removeAttribute('hidden');
  markDiffTables();
}

function closeDiff() {
  state.diffMode = false;
  el.diffBtn?.setAttribute('aria-expanded', 'false');
  el.diffPanel?.setAttribute('hidden', '');
  clearDiffTables();
}

// Tint the added and changed table nodes. Removed tables are not rendered (they
// are gone from the current schema), so the panel lists them instead.
function clearDiffTables() {
  el.canvas.querySelector('svg')
    ?.querySelectorAll('g.node.truss-diff-added, g.node.truss-diff-changed')
    .forEach((n) => n.classList.remove('truss-diff-added', 'truss-diff-changed'));
}

function markDiffTables() {
  clearDiffTables();
  if (!state.diffMode || !hasDiff(state.diff)) return;
  for (const [name, status] of tableStatuses(state.diff)) {
    if (status === 'removed') continue;
    findTableNode(name)?.classList.add(`truss-diff-${status}`);
  }
}

function renderDiffPanel() {
  const body = el.diffPanel?.querySelector('.truss-diff-body');
  if (!body) return;

  if (!hasDiff(state.diff)) {
    body.innerHTML = '<p class="truss-diff-empty">No structural changes since the last migration.</p>';
    return;
  }

  body.innerHTML = changeList(state.diff).map((item) => {
    // Added and changed tables still exist in the diagram, so their names are
    // focus links; removed tables are gone, so they stay plain text.
    if (item.kind === 'table-added') return diffRow('added', item.table, '', true);
    if (item.kind === 'table-removed') return diffRow('removed', item.table, '', false);
    const details = item.details.map((d) => `<li>${escapeHtml(describeDetail(d))}</li>`).join('');
    return diffRow('changed', item.table, details, true);
  }).join('');
}

function diffRow(status, table, details = '', focusable = false) {
  const label = focusable
    ? `<button type="button" class="truss-diff-focus" data-diff-focus="${escapeHtml(table)}">${escapeHtml(table)}</button>`
    : escapeHtml(table);
  const list = details ? `<ul class="truss-diff-details">${details}</ul>` : '';
  return `<div class="truss-diff-item truss-diff-item--${status}">`
    + `<span class="truss-diff-badge">${status}</span> ${label}${list}</div>`;
}

// Focus a table straight from the Changes panel, reusing the focus pipeline.
function focusTableFromDiff(name) {
  if (!state.tables.some((t) => t.name === name)) return;
  state.focusRoot = name;
  if (el.focus) el.focus.value = name;
  render();
}

function describeDetail(d) {
  switch (d.kind) {
    case 'column-added': return `+ column ${d.name} (${d.type})`;
    case 'column-removed': return `- column ${d.name}`;
    case 'column-changed': return `~ column ${d.name} (${Object.entries(d.changes)
      .map(([field, c]) => `${field}: ${diffValue(c.before)} → ${diffValue(c.after)}`).join(', ')})`;
    case 'index-added': return `+ index ${d.name}`;
    case 'index-removed': return `- index ${d.name}`;
    case 'index-changed': return `~ index ${d.name}`;
    case 'fk-added': return `+ foreign key ${d.name}`;
    case 'fk-removed': return `- foreign key ${d.name}`;
    case 'fk-changed': return `~ foreign key ${d.name}`;
    case 'primary-key-changed': return `~ primary key [${d.before.join(', ')}] → [${d.after.join(', ')}]`;
    default: return '';
  }
}

function diffValue(value) {
  if (value === null) return 'null';
  if (value === true) return 'true';
  if (value === false) return 'false';
  return Array.isArray(value) ? `[${value.join(', ')}]` : String(value);
}

/* ---- structure health ("Health" view) --------------------------------- */

// Show the Health button only when the response carried a doctor payload (the
// panel is enabled). The count bubble reflects the total, coloured by the worst
// severity present; when there are no findings the button reads as a clean pass.
function updateDoctorAvailability() {
  const available = state.doctor != null;
  el.healthBtn?.toggleAttribute('hidden', !available);
  if (!available) {
    if (state.doctorMode) closeHealth();
    return;
  }

  const { total } = doctorSummary(state.doctor);
  const worst = worstSeverity(state.doctor) ?? 'clean';
  el.healthBtn?.setAttribute('data-severity', worst);
  el.healthBtn?.setAttribute('title', total > 0
    ? `Structure health: ${total} finding${total === 1 ? '' : 's'} (truss:doctor)`
    : 'Structure health: no problems found (truss:doctor)');
  if (el.healthCount) {
    el.healthCount.textContent = total > 0 ? String(total) : '';
    el.healthCount.toggleAttribute('hidden', total === 0);
  }
}

function toggleHealth() {
  if (state.doctor == null) return;
  if (state.doctorMode) {
    closeHealth();
    hidePopover(); // also dismiss a finding popover opened from a marker
  } else {
    openHealth();
  }
}

function openHealth() {
  closeOverlays('health');
  state.doctorMode = true;
  el.healthBtn?.setAttribute('aria-expanded', 'true');
  renderHealthPanel();
  el.healthPanel?.removeAttribute('hidden');
  markDoctorBadges();
  markDoctorRows(currentSubset());
}

function closeHealth() {
  state.doctorMode = false;
  el.healthBtn?.setAttribute('aria-expanded', 'false');
  el.healthPanel?.setAttribute('hidden', '');
  restoreHealth();
  clearDoctorBadges();
  clearDoctorRows();
}

// Expand the panel to a large centred overlay for reading, or restore it.
function toggleHealthMax() {
  el.healthPanel?.classList.contains('is-maximized') ? restoreHealth() : maximizeHealth();
}

function maximizeHealth() {
  el.healthPanel?.classList.add('is-maximized');
  el.healthMaxBtn?.setAttribute('aria-pressed', 'true');
  if (el.healthMaxBtn) {
    el.healthMaxBtn.textContent = '⤡';
    el.healthMaxBtn.title = 'Restore';
  }
}

function restoreHealth() {
  el.healthPanel?.classList.remove('is-maximized');
  el.healthMaxBtn?.setAttribute('aria-pressed', 'false');
  if (el.healthMaxBtn) {
    el.healthMaxBtn.textContent = '⤢';
    el.healthMaxBtn.title = 'Maximize';
  }
}

// Badge the diagram nodes for tables that have findings, coloured by their worst
// severity. Only active while the Health panel is open, so the canvas stays clean
// otherwise.
function clearDoctorBadges() {
  el.canvas.querySelector('svg')
    ?.querySelectorAll('g.node.truss-health-error, g.node.truss-health-warning, g.node.truss-health-info')
    .forEach((n) => n.classList.remove('truss-health-error', 'truss-health-warning', 'truss-health-info'));
}

function markDoctorBadges() {
  clearDoctorBadges();
  if (!state.doctorMode || !hasDoctor(state.doctor)) return;
  for (const [name, { severity }] of tableBadges(state.doctor)) {
    findTableNode(name)?.classList.add(`truss-health-${severity}`);
  }
}

// A passive, always-on flag: a small severity-coloured badge with the finding
// count on the corner of every table that has findings, independent of the Health
// panel, so a problem is visible just by looking at the table. Gated by the
// `flag_tables` config. Drawn as an SVG element inside the node, so it zooms and
// pans with the diagram and sits on top of any Changes tint without conflicting.
const SVGNS = 'http://www.w3.org/2000/svg';

function clearPassiveHealth() {
  el.canvas.querySelector('svg')?.querySelectorAll('.truss-health-flag').forEach((n) => n.remove());
}

function markPassiveHealth(subset) {
  clearPassiveHealth();
  const svg = el.canvas.querySelector('svg');
  if (!svg || !config.flagTables || !hasDoctor(state.doctor)) return;

  const present = new Set(subset.map((t) => t.name));
  for (const [name, { severity, count }] of tableBadges(state.doctor)) {
    if (!present.has(name)) continue;
    const node = findTableNode(name);
    if (!node) continue;

    let bb;
    try { bb = node.getBBox(); } catch { continue; }

    const flag = document.createElementNS(SVGNS, 'g');
    flag.setAttribute('class', `truss-health-flag truss-health-flag--${severity}`);
    flag.setAttribute('transform', `translate(${bb.x + bb.width - 3}, ${bb.y + 3})`);

    const circle = document.createElementNS(SVGNS, 'circle');
    circle.setAttribute('r', '9');

    const text = document.createElementNS(SVGNS, 'text');
    text.setAttribute('text-anchor', 'middle');
    text.setAttribute('dominant-baseline', 'central');
    text.textContent = String(count);

    flag.append(circle, text);
    node.append(flag);
  }
}

// Mark the diagram rows whose column has a finding, so the problem is visible on
// the table itself. Reuses the per-column type cells (indexed like
// annotateColumnTypes); each marker opens the finding popover on click.
function clearDoctorRows() {
  el.canvas.querySelector('svg')?.querySelectorAll('.truss-health-marker').forEach((label) => {
    label.classList.remove('truss-health-marker', 'truss-health-marker--error', 'truss-health-marker--warning', 'truss-health-marker--info');
    delete label.dataset.healthTable;
    delete label.dataset.healthColumn;
  });
}

function markDoctorRows(subset) {
  clearDoctorRows();
  const svg = el.canvas.querySelector('svg');
  if (!svg || !state.doctorMode || !hasDoctor(state.doctor)) return;

  const byName = new Map(subset.map((t) => [t.name, t]));
  for (const node of svg.querySelectorAll('g.node')) {
    const nameLabel = node.querySelector('g.label.name .nodeLabel');
    const table = byName.get(nameLabel?.textContent.trim());
    if (!table) continue;

    const markers = columnMarkers(state.doctor, table.name, table.columns.map((c) => c.name));
    if (markers.size === 0) continue;

    // Mark the column *name* cell (indexed like the type cells), so the affordance
    // sits on the field the finding is about.
    const nameCells = node.querySelectorAll('g.label.attribute-name');
    table.columns.forEach((col, i) => {
      const marker = markers.get(col.name);
      const label = nameCells[i]?.querySelector('.nodeLabel');
      if (!marker || !label) return;
      label.classList.add('truss-health-marker', `truss-health-marker--${marker.severity}`);
      label.dataset.healthTable = table.name;
      label.dataset.healthColumn = col.name;
      label.setAttribute('role', 'button');
      label.setAttribute('tabindex', '0');
      label.setAttribute('title', marker.findings.map((f) => f.message).join('\n'));
    });
  }
}

// The detail popover for a marked column: its findings, each with message and
// hint. Uses placePopover so it can sit alongside the open Health panel.
function showFindingPopover(anchor, tableName, column) {
  const findings = (state.doctor?.findings ?? []).filter((f) => f.table === tableName && f.column === column);
  if (findings.length === 0) return;

  menuTable = null;
  el.popover.innerHTML = `<div class="truss-popover-head">${escapeHtml(tableName)}.${escapeHtml(column)}</div>`
    + '<ul class="truss-health-pop">'
    + findings.map((f) => `<li class="truss-health-pop-item truss-health-item--${f.severity}">`
      + `<div class="truss-health-item-head"><span class="truss-health-badge">${escapeHtml(f.severity)}</span> `
      + `<code class="truss-health-code">${escapeHtml(f.code)}</code></div>`
      + `<div class="truss-health-msg">${escapeHtml(f.message)}</div>`
      + (f.hint ? `<div class="truss-health-hint">${escapeHtml(f.hint)}</div>` : '')
      + '</li>').join('')
    + '</ul>';
  placePopover(anchor);
}

function renderHealthPanel() {
  const body = el.healthPanel?.querySelector('.truss-health-body');
  if (!body) return;

  const groups = findingGroups(state.doctor);
  if (groups.length === 0) {
    body.innerHTML = '<p class="truss-health-empty">No structural problems found.</p>';
    return;
  }

  // On a large schema the groups start collapsed so the panel stays scannable.
  const open = state.tables.length <= config.warnAbove;
  body.innerHTML = groups.map((group) => healthGroup(group, open)).join('');
}

function healthGroup(group, open) {
  const items = group.findings.map((f) => healthItem(f)).join('');
  return `<details class="truss-health-group truss-health-group--${group.severity}"${open ? ' open' : ''}>`
    + '<summary class="truss-health-group-head">'
    + `<span class="truss-health-dot truss-health-dot--${group.severity}"></span>`
    + `<span class="truss-health-table">${escapeHtml(group.table)}</span>`
    + `<span class="truss-health-count-inline">${group.findings.length}</span></summary>`
    + '<div class="truss-health-group-body">'
    + `<button type="button" class="truss-health-focus" data-health-focus="${escapeHtml(group.table)}">Focus this table</button>`
    + `<ul class="truss-health-items">${items}</ul></div></details>`;
}

function healthItem(finding) {
  const location = finding.column ?? '(table)';
  const heuristic = finding.confidence === 'heuristic'
    ? ' <span class="truss-health-heuristic" title="Heuristic: a lower-confidence signal">heuristic</span>'
    : '';
  return `<li class="truss-health-item truss-health-item--${finding.severity}">`
    + `<div class="truss-health-item-head"><span class="truss-health-badge">${escapeHtml(finding.severity)}</span> `
    + `<code class="truss-health-code">${escapeHtml(finding.code)}</code> `
    + `<span class="truss-health-loc">${escapeHtml(location)}</span>${heuristic}</div>`
    + `<div class="truss-health-msg">${escapeHtml(finding.message)}</div></li>`;
}

// Focus a table straight from the Health panel, reusing the focus pipeline.
function focusTableFromDoctor(name) {
  if (!state.tables.some((t) => t.name === name)) return;
  state.focusRoot = name;
  if (el.focus) el.focus.value = name;
  render();
}

/* ---- overlay coordination --------------------------------------------- */

// Only one overlay is open at a time: opening any panel or menu closes the rest,
// so they never stack or overlap. `except` names the one being opened, so it is
// left untouched. The toolbar overlays (Legend, Changes, Health, the Export menu)
// all anchor to the same top-right cluster.
function closeOverlays(except) {
  if (except !== 'legend' && el.legend && !el.legend.hasAttribute('hidden')) closeLegend();
  if (except !== 'diff' && state.diffMode) closeDiff();
  if (except !== 'health' && state.doctorMode) closeHealth();
  if (except !== 'more' && el.more?.classList.contains('is-open')) closeMore();
  if (except !== 'popover') hidePopover();
}

function openLegend() {
  closeOverlays('legend');
  el.legend?.removeAttribute('hidden');
  el.legendBtn?.setAttribute('aria-expanded', 'true');
}

function closeLegend() {
  el.legend?.setAttribute('hidden', '');
  el.legendBtn?.setAttribute('aria-expanded', 'false');
}

function toggleLegend() {
  el.legend?.hasAttribute('hidden') ? openLegend() : closeLegend();
}

function openMore() {
  closeOverlays('more');
  el.more?.classList.add('is-open');
  el.moreBtn?.setAttribute('aria-expanded', 'true');
}

function closeMore() {
  el.more?.classList.remove('is-open');
  el.moreBtn?.setAttribute('aria-expanded', 'false');
}

function toggleMore() {
  el.more?.classList.contains('is-open') ? closeMore() : openMore();
}

/* ---- rendering -------------------------------------------------------- */

function banner(kind, text, action = null) {
  const node = document.createElement('div');
  node.className = `truss-banner truss-banner--${kind}`;
  node.append(document.createTextNode(text));

  if (action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'truss-banner-action';
    button.textContent = action.label;
    button.setAttribute(action.attribute, '');
    button.addEventListener('click', action.onClick);
    node.append(button);
  }

  return node;
}

function renderBanners() {
  el.banners.replaceChildren();
  if (state.fallback) {
    el.banners.append(banner('warn',
      'A database connection was not available; the schema was rebuilt from an in-memory SQLite replay, so column types may be approximate.'));
  }
  // A baseline the server could not read is different from having none yet: the
  // panel is missing either way, but only this case is a problem the user can fix,
  // and saying nothing would let it read as "nothing has changed".
  // The snapshot is complete either way: the server read it live instead of from
  // the cache, which is slower on every request until the store is usable again.
  if (state.cacheUnavailable) {
    el.banners.append(banner('warn',
      'The cache store is unavailable, so the structure was read live. '
      + 'The diagram is complete, but it will stay slow until the cache store is fixed.'));
  }
  if (state.diffUnavailable) {
    el.banners.append(banner('warn',
      'Changes is unavailable: the schema-diff baseline could not be read from disk. '
      + 'Set TRUSS_DIFF_DISK to a local disk, or turn the feature off with TRUSS_DIFF_ENABLED=false. '
      + 'The diagram is unaffected.'));
  }
  const unscoped = !state.search && !state.focusRoot;
  if (unscoped && state.tables.length > config.warnAbove) {
    el.banners.append(banner('info',
      `${state.tables.length} tables, a large schema. Use the filter or focus a table to keep the diagram fast and legible.`));
  }
  // When the diagram is empty, say which of the filter and the focus emptied it,
  // and offer to drop the focus when that is what is hiding the match.
  const notice = emptySelectionNotice(state.tables, currentSelection());
  if (notice) {
    el.banners.append(banner('info', notice.message, notice.clearFocus
      ? { label: 'Clear focus', attribute: 'data-clear-focus', onClick: clearFocus }
      : null));
  }
}

/** Reflect the current view (filter, focus, depth, type labels, connection) in the URL. */
function syncUrl() {
  const qs = buildQuery({
    connection: config.connections.length > 1 ? state.connection : null,
    filter: state.search,
    focus: state.focusRoot,
    depth: (state.focusRoot && state.depth !== config.focusDepth) ? state.depth : null,
    labels: state.laravelLabels,
  });
  window.history.replaceState(null, '', qs || window.location.pathname);
}

async function render() {
  const subset = currentSubset();
  syncUrl();
  renderBanners();

  if (subset.length === 0) {
    el.canvas.replaceChildren();
    return;
  }

  const definition = generateErDiagram(subset, {
    typeLabels: state.laravelLabels ? 'laravel' : 'native',
    // Names the SVG for assistive technology and describes the view it is
    // showing, so the description tracks the filter and focus (WCAG 1.1.1).
    view: { filter: state.search, focus: state.focusRoot, depth: state.depth },
  });
  const key = subset.map((t) => t.name).join('|');

  try {
    const { svg } = await mermaid.render('truss-graph', definition);
    hidePopover(); // the eye it anchored to is about to be replaced
    el.canvas.innerHTML = svg;
    normalizeSvg();
    raiseEntityBorders();
    markFocusedTable();
    markDiffTables(); // re-tint after a re-render when the Changes view is on
    markDoctorBadges(); // re-badge after a re-render when the Health view is on
    markPassiveHealth(subset); // always-on flags on tables with findings (config-gated)
    annotateColumnTypes(subset);
    markDoctorRows(subset); // after annotateColumnTypes, so a marked row's title wins

    // Auto-fit only when the *content* changed (new tables): filtering and
    // focusing frame their result (honouring the readable floor), while a label
    // toggle keeps your view. When focusing, re-centre on the focused table so
    // it lands in the middle rather than wherever the layout put it.
    if (key !== state.lastKey) {
      fitToViewport(config.minZoom);
      if (state.focusRoot) centerOnFocusedTable();
    } else {
      applyTransform();
    }
    state.lastKey = key;
  } catch (error) {
    el.canvas.replaceChildren(banner('error', `Diagram failed to render: ${error?.message ?? error}`));
  }
}

/* ---- status footer ---------------------------------------------------- */

function timeAgo(iso) {
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const s = Math.max(0, Math.round((Date.now() - t) / 1000));
  if (s < 60) return `${s}s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  return h < 24 ? `${h}h ago` : `${Math.round(h / 24)}d ago`;
}

function updateFooter() {
  const n = state.tables.length;
  if (el.statTables) el.statTables.textContent = `${n} ${n === 1 ? 'table' : 'tables'}`;
  if (el.statConn) el.statConn.textContent = state.connection ?? '';
  if (el.statFallback) el.statFallback.toggleAttribute('hidden', !state.fallback);
  if (el.statUpdated) el.statUpdated.textContent = state.generatedAt ? `updated ${timeAgo(state.generatedAt)}` : '';
}

/* ---- data ------------------------------------------------------------- */

function populateFocusOptions() {
  el.focus.innerHTML = ['<option value="">- none -</option>']
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
  state.generatedAt = payload.generated_at ?? null;
  state.diff = payload.diff ?? null;
  state.cacheUnavailable = payload.cache_unavailable === true;
  state.diffUnavailable = payload.diff_unavailable === true;
  state.doctor = payload.doctor ?? null;
  // Apply a focus requested via the URL, once we can confirm the table exists.
  state.focusRoot = (state.pendingFocus && state.tables.some((t) => t.name === state.pendingFocus))
    ? state.pendingFocus
    : '';
  state.pendingFocus = null;
  state.lastKey = null; // force an auto-fit for the new schema
  populateFocusOptions();
  updateFooter();
  updateDiffAvailability();
  updateDoctorAvailability();
  await render();
}

/* ---- theme ------------------------------------------------------------ */

// auto (follow OS) → light → dark → auto. Persisted; drives the CSS via
// data-theme (absent = auto/prefers-color-scheme).
function applyTheme() {
  const t = localStorage.getItem('truss-theme');
  if (t === 'light' || t === 'dark') app.ownerDocument.documentElement.setAttribute('data-theme', t);
  else app.ownerDocument.documentElement.removeAttribute('data-theme');
  if (el.themeBtn) {
    el.themeBtn.textContent = t === 'light' ? '☀' : t === 'dark' ? '☾' : '◐';
    el.themeBtn.title = `Theme: ${t || 'auto'}`;
  }
}

function cycleTheme() {
  const cur = localStorage.getItem('truss-theme');
  const next = cur == null ? 'light' : cur === 'light' ? 'dark' : null;
  if (next) localStorage.setItem('truss-theme', next);
  else localStorage.removeItem('truss-theme');
  applyTheme();
}

/* ---- events ----------------------------------------------------------- */

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

  // Wheel zooms toward the cursor (never scrolls the page).
  el.viewport.addEventListener('wheel', (e) => {
    e.preventDefault();
    hidePopover();
    const rect = el.viewport.getBoundingClientRect();
    zoomBy(e.deltaY < 0 ? 1.1 : 1 / 1.1, { x: e.clientX - rect.left, y: e.clientY - rect.top });
  }, { passive: false });

  // Enum label and table name: open/close the shared popover. Delegated so it
  // survives re-renders. A menu action click is handled by its own listener below.
  el.canvas.addEventListener('click', (e) => {
    const marker = e.target.closest('.truss-health-marker');
    if (marker) {
      e.stopPropagation();
      if (openAnchor === marker) hidePopover();
      else showFindingPopover(marker, marker.dataset.healthTable, marker.dataset.healthColumn);
      return;
    }
    const enumLabel = e.target.closest('.truss-enum-type');
    if (enumLabel) {
      e.stopPropagation();
      if (openAnchor === enumLabel) hidePopover();
      else showEnumPopover(enumLabel);
      return;
    }
    const nameLabel = e.target.closest('.truss-table-name');
    if (nameLabel) {
      e.stopPropagation();
      const table = state.tables.find((t) => t.name === nameLabel.dataset.table);
      if (openAnchor === nameLabel || !table) hidePopover();
      else showTableMenu(nameLabel, table);
    }
  });
  // The in-diagram triggers are given role="button" and tabindex="0", which
  // promises assistive technology a button, so they have to answer Enter and
  // Space like one (WCAG 2.1.1). Delegated to match the click handler above and
  // survive re-renders. Space is prevented from scrolling the page.
  el.canvas.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    const trigger = e.target.closest('.truss-health-marker, .truss-enum-type, .truss-table-name');
    if (!trigger) return;

    e.preventDefault();
    trigger.click();

    // Opened from the keyboard: move into the menu so its actions are reachable
    // without a mouse, and remember where to hand focus back on Escape.
    if (!el.popover.hidden) {
      restoreFocusTo = trigger;
      el.popover.querySelector('button')?.focus();
    }
  });

  // Escape closes the open popover from anywhere, including from inside it.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || el.popover.hidden) return;
    e.preventDefault();
    dismissPopover();
  });

  el.popover.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (btn) runMenuAction(btn.dataset.act);
  });
  el.exportBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (openAnchor === el.exportBtn) {
      hidePopover();
    } else {
      showExportMenu(el.exportBtn);
      el.exportBtn.setAttribute('aria-expanded', 'true');
    }
  });
  document.addEventListener('click', (e) => {
    if (openAnchor && !e.target.closest('.truss-popover, .truss-enum-type, .truss-table-name, .truss-health-marker, #truss-export-btn')) hidePopover();
  });

  // Unified pointer handling: one pointer pans (mouse or touch), two pointers
  // pinch-zoom around their midpoint. Overlay controls are excluded.
  const pointers = new Map();
  let lastPinch = 0;
  const dist = () => { const p = [...pointers.values()]; return Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y); };
  const mid = (rect) => { const p = [...pointers.values()]; return { x: (p[0].x + p[1].x) / 2 - rect.left, y: (p[0].y + p[1].y) / 2 - rect.top }; };

  el.viewport.addEventListener('pointerdown', (e) => {
    if (e.target.closest?.('#truss-zoom, .truss-legend, .truss-enum-type, .truss-table-name, .truss-health-marker, .truss-popover')) return;
    hidePopover();
    e.preventDefault();
    el.viewport.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 1) el.viewport.classList.add('is-panning');
    if (pointers.size === 2) lastPinch = dist();
  });
  el.viewport.addEventListener('pointermove', (e) => {
    const p = pointers.get(e.pointerId);
    if (!p) return;
    const prev = { x: p.x, y: p.y };
    p.x = e.clientX; p.y = e.clientY;

    if (pointers.size >= 2) {
      const d = dist();
      if (lastPinch) zoomBy(d / lastPinch, mid(el.viewport.getBoundingClientRect()));
      lastPinch = d;
    } else {
      state.view = { ...state.view, x: state.view.x + (e.clientX - prev.x), y: state.view.y + (e.clientY - prev.y) };
      applyTransform();
    }
  });
  const endPointer = (e) => {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) lastPinch = 0;
    if (pointers.size === 0) el.viewport.classList.remove('is-panning');
  };
  el.viewport.addEventListener('pointerup', endPointer);
  el.viewport.addEventListener('pointercancel', endPointer);

  // Slider zooms around the viewport centre.
  el.zoomRange?.addEventListener('input', (e) => {
    const target = clamp(Number(e.target.value), ZOOM_LIMITS.min, ZOOM_LIMITS.max);
    const { width, height } = viewportSize();
    zoomBy(target / state.view.zoom, { x: width / 2, y: height / 2 });
  });

  // The Fit button frames the whole diagram (ignores the readable floor).
  el.fit?.addEventListener('click', () => fitToViewport(0));

  // Utility cluster.
  el.moreBtn?.addEventListener('click', toggleMore);
  el.legendBtn?.addEventListener('click', toggleLegend);
  el.diffBtn?.addEventListener('click', toggleDiff);
  el.diffPanel?.addEventListener('click', (e) => {
    const focus = e.target.closest('[data-diff-focus]');
    if (focus) focusTableFromDiff(focus.dataset.diffFocus);
  });
  el.healthBtn?.addEventListener('click', toggleHealth);
  el.healthMaxBtn?.addEventListener('click', toggleHealthMax);
  el.healthPanel?.addEventListener('click', (e) => {
    const focus = e.target.closest('[data-health-focus]');
    if (focus) focusTableFromDoctor(focus.dataset.healthFocus);
  });
  el.themeBtn?.addEventListener('click', cycleTheme);

  window.addEventListener('resize', debounce(() => applyTransform(), 200));
}

/* ---- boot ------------------------------------------------------------- */

if (el.search) el.search.value = state.search;
if (el.depth) el.depth.value = String(state.depth);
if (el.labels) el.labels.checked = state.laravelLabels;
applyTheme();
populateConnectionOptions();
updateFooter();
wireEvents();
loadSchema();
