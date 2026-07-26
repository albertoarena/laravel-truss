import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

const css = readFileSync(
  fileURLToPath(new URL('../../resources/css/truss.css', import.meta.url)),
  'utf8'
);

// The `#truss-canvas` rule that carries the pan/zoom transform.
const canvasRule = css.match(/#truss-canvas\s*\{[^}]*\}/)?.[0] ?? '';

describe('#truss-canvas transform layer', () => {
  it('exists', () => {
    expect(canvasRule).toContain('transform-origin');
  });

  // `will-change: transform` pins the canvas to a GPU layer that is rasterised
  // once at promotion scale and then bitmap-upscaled, so text blurs past ~1x.
  // Leaving it off lets the browser re-rasterise the vector SVG on each scale
  // change, keeping deep zoom crisp. Panning only changes `translate`, which
  // does not invalidate the raster, so it stays cheap.
  it('does not pin will-change to a fixed-scale raster', () => {
    expect(canvasRule).not.toMatch(/will-change\s*:\s*transform/);
  });
});
