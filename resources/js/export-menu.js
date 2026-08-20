// Which export actions the dashboard may offer, given how it is deployed and
// how big the diagram is. Pure logic, no DOM: truss.js asks, renders, and
// disables accordingly.
//
// Both rules replace a silent failure.
//
// Markdown, DBML, JSON and CSV are generated server-side (see docs/DECISIONS.md,
// "Frontend export swap: one PHP source of truth"), so a dashboard served
// without an export route cannot produce them. It offered them anyway, and
// choosing one threw inside a promise: no file, no error, nothing. That is the
// state of the live demo, and of any install that has turned the route off.
//
// PNG is rasterised through a canvas at a fixed 2x with no ceiling. Past roughly
// 268 megapixels Chrome returns a blank canvas and toBlob yields null, which the
// caller discarded. Measured in Chrome: a 33-table schema rasterises at 98 MP
// and is fine; a 50-table schema would need 384 MP and produces nothing at all.

/**
 * The ceiling this module will rasterise to.
 *
 * Chrome tops out near 268 MP and other engines are lower, iOS most of all, so
 * this sits well under the worst case that was measured rather than at it. It
 * is also chosen to leave the common sizes untouched: a schema that exports at
 * a full 2x today still does.
 */
export const MAX_RASTER_PIXELS = 100_000_000;

/** The scale the diagram export has always used when there is room for it. */
const PREFERRED_SCALE = 2;

/**
 * Can the server-backed formats (Markdown, DBML, JSON, CSV) be produced?
 *
 * @param {string|null|undefined} endpoint the export route template
 * @returns {boolean}
 */
export function serverExportsAvailable(endpoint) {
  return typeof endpoint === 'string' && endpoint.trim() !== '';
}

/**
 * The scale to rasterise a diagram of this size at, or null if it cannot be
 * rasterised at all.
 *
 * Never returns less than 1. Downscaling is not a rescue: it throws away the
 * pixels that make the labels readable, which is the only reason to want a PNG.
 * Better to withhold the export and say so than to hand back something unusable.
 *
 * @param {number} width diagram width in CSS pixels
 * @param {number} height diagram height in CSS pixels
 * @returns {number|null}
 */
export function rasterScale(width, height) {
  if (!(width > 0) || !(height > 0)) return null;

  const area = width * height;
  if (area > MAX_RASTER_PIXELS) return null;

  // Rounded down, not just computed: squaring a square root lands a hair above
  // the budget, and a bound that its own arithmetic breaks is not a bound.
  const fits = Math.floor(Math.sqrt(MAX_RASTER_PIXELS / area) * 1000) / 1000;

  return Math.min(PREFERRED_SCALE, Math.max(1, fits));
}

/**
 * Whether to offer PNG, at what scale, and what to say when the answer is no.
 *
 * The verdict is taken from the diagram's real geometry rather than its table
 * count. A count is only a proxy: twenty wide tables can outgrow fifty narrow
 * ones, and it is the pixels that hit the wall.
 *
 * @param {number} width
 * @param {number} height
 * @returns {{ available: boolean, scale: number|null, reason: string|null }}
 */
export function pngAvailability(width, height) {
  // Nothing drawn (a schema that failed to load, an empty filter) is not the
  // same as a diagram that will not fit, and must not be explained as one.
  if (!(width > 0) || !(height > 0)) {
    return { available: false, scale: null, reason: null };
  }

  const scale = rasterScale(width, height);

  if (scale === null) {
    return {
      available: false,
      scale: null,
      reason: 'This diagram is too large to save as a PNG. Export SVG instead, which has no size limit, or focus a table first and export what you are looking at.',
    };
  }

  return { available: true, scale, reason: null };
}
