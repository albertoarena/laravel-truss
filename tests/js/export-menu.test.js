// Unit tests for export availability: which export actions the dashboard may
// offer, given how it is deployed and how big the diagram is. Written before
// the implementation (resources/js/export-menu.js).
//
// Both rules exist because of a failure that was silent.
//
// Markdown, DBML, JSON and CSV are generated server-side, so a dashboard with no
// export route cannot produce them. The live demo is exactly that, and it
// offered all six actions anyway: clicking one threw a TypeError inside a
// promise and the visitor saw nothing at all. Not a 404, not an error, nothing.
//
// PNG is rasterised through a canvas at a fixed 2x, with no ceiling. Past about
// 268 megapixels Chrome hands back a blank canvas and toBlob yields null, which
// the caller dropped on the floor. Measured: a 33-table schema exports at 98 MP
// and works; a 50-table schema needs 384 MP and silently produces nothing.

import { describe, it, expect } from 'vitest';
import {
  serverExportsAvailable,
  rasterScale,
  pngAvailability,
  MAX_RASTER_PIXELS,
} from '../../resources/js/export-menu.js';

describe('serverExportsAvailable', () => {
  it('is true when the dashboard was given an export route', () => {
    expect(serverExportsAvailable('/truss/export/__format__')).toBe(true);
  });

  it('is false when the route is absent, which is how a static page is served', () => {
    expect(serverExportsAvailable(undefined)).toBe(false);
    expect(serverExportsAvailable(null)).toBe(false);
    expect(serverExportsAvailable('')).toBe(false);
    expect(serverExportsAvailable('   ')).toBe(false);
  });
});

describe('rasterScale', () => {
  it('uses the full 2x on a diagram with room for it', () => {
    // A handful of tables: unchanged from what shipped.
    expect(rasterScale(1200, 800)).toBe(2);
  });

  it('holds 2x right up to the budget', () => {
    // 33 tables measured at 9500x2589, which is 98 MP at 2x and renders today.
    expect(rasterScale(9500, 2589)).toBe(2);
  });

  it('steps the scale down rather than blowing the budget', () => {
    const scale = rasterScale(8688, 5735);

    expect(scale).toBeGreaterThan(1);
    expect(scale).toBeLessThan(2);
    expect(8688 * scale * (5735 * scale)).toBeLessThanOrEqual(MAX_RASTER_PIXELS);
  });

  it('never goes below 1x, where real detail starts being lost', () => {
    // Downscaling is not a rescue: it throws away the pixels that make the
    // labels readable, which is the only reason to export a PNG at all. A
    // diagram this size only just fits, so the scale lands just above 1.
    const scale = rasterScale(12000, 8000);

    expect(scale).toBeGreaterThanOrEqual(1);
    expect(scale).toBeLessThan(1.1);
    expect(12000 * scale * (8000 * scale)).toBeLessThanOrEqual(MAX_RASTER_PIXELS);
  });

  it('returns null when even 1x cannot be rasterised', () => {
    expect(rasterScale(24000, 16000)).toBe(null);
  });

  it('returns null for a diagram with no area', () => {
    expect(rasterScale(0, 0)).toBe(null);
    expect(rasterScale(-5, 100)).toBe(null);
  });

  it('keeps its own budget under what a browser will actually rasterise', () => {
    // Chrome tops out around 268 MP; other engines, notably on iOS, are lower.
    expect(MAX_RASTER_PIXELS).toBeLessThanOrEqual(268_000_000);
  });
});

describe('pngAvailability', () => {
  it('offers PNG for an ordinary diagram', () => {
    expect(pngAvailability(1200, 800)).toEqual({ available: true, scale: 2, reason: null });
  });

  it('withholds PNG when the diagram cannot be rasterised, and says why', () => {
    const verdict = pngAvailability(24000, 16000);

    expect(verdict.available).toBe(false);
    expect(verdict.scale).toBe(null);
    // The message has to name the way out, not just the refusal.
    expect(verdict.reason).toMatch(/too large/i);
    expect(verdict.reason).toMatch(/SVG/);
    expect(verdict.reason).toMatch(/focus/i);
  });

  it('withholds PNG when there is no diagram, without calling nothing too large', () => {
    // Seen on a dashboard whose schema failed to load: with no diagram drawn the
    // size is 0x0, and the menu announced that the diagram was too large to
    // rasterise. There is nothing to export and nothing to explain.
    const verdict = pngAvailability(0, 0);

    expect(verdict.available).toBe(false);
    expect(verdict.reason).toBe(null);
  });

  it('turns PNG off at roughly the size the maintainer expected', () => {
    // The ask was "disable it past about fifty tables". This is enforced on the
    // diagram's real geometry rather than a table count, since a count is only a
    // proxy: twenty wide tables can outgrow fifty narrow ones. It happens to
    // land in the same place. Figures interpolated from two measured exports.
    expect(pngAvailability(9500, 2589).available).toBe(true); // 33 tables
    expect(pngAvailability(12000, 8000).available).toBe(true); // ~50, at 1x
    expect(pngAvailability(14000, 9500).available).toBe(false); // past it
  });
});
