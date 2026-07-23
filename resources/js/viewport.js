// Pure pan/zoom math for the diagram viewport. Kept free of the DOM so it can be
// unit-tested; truss.js wires these to wheel/drag/slider events and the SVG.
//
// A "view" is { zoom, x, y }: the canvas is transformed as
// `translate(x, y) scale(zoom)`, with transform-origin at the top-left.

export const ZOOM_LIMITS = { min: 0.05, max: 8 };

export function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

/**
 * The view that fits `content` inside `viewport`, centred. Never upscales past
 * `maxScale` (default 1) so small diagrams show at their natural size rather
 * than being blown up.
 *
 * @param {{width:number,height:number}} content  natural pixel size of the SVG
 * @param {{width:number,height:number}} viewport  visible area
 * @param {{padding?:number,maxScale?:number}} [options]
 * @returns {{zoom:number,x:number,y:number}}
 */
export function fitTransform(content, viewport, { padding = 0.95, maxScale = 1 } = {}) {
  if (!content.width || !content.height || !viewport.width || !viewport.height) {
    return { zoom: 1, x: 0, y: 0 };
  }

  const raw = Math.min(viewport.width / content.width, viewport.height / content.height) * padding;
  const zoom = clamp(Math.min(raw, maxScale), ZOOM_LIMITS.min, ZOOM_LIMITS.max);

  return {
    zoom,
    x: (viewport.width - content.width * zoom) / 2,
    y: (viewport.height - content.height * zoom) / 2,
  };
}

/**
 * Zoom by `factor` while keeping the content point currently under `point`
 * (a coordinate in viewport space) pinned in place — the Google-Maps feel.
 *
 * @param {{zoom:number,x:number,y:number}} view
 * @param {{x:number,y:number}} point  cursor position in viewport space
 * @param {number} factor  >1 zooms in, <1 zooms out
 * @param {{min:number,max:number}} [limits]
 * @returns {{zoom:number,x:number,y:number}}
 */
export function zoomAtPoint(view, point, factor, limits = ZOOM_LIMITS) {
  const zoom = clamp(view.zoom * factor, limits.min, limits.max);
  const k = zoom / view.zoom;

  return {
    zoom,
    x: point.x - (point.x - view.x) * k,
    y: point.y - (point.y - view.y) * k,
  };
}
