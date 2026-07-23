import { describe, it, expect } from 'vitest';
import { clamp, fitTransform, zoomAtPoint, ZOOM_LIMITS } from '../../resources/js/viewport.js';

describe('clamp', () => {
  it('bounds a value to [min, max]', () => {
    expect(clamp(5, 0, 10)).toBe(5);
    expect(clamp(-1, 0, 10)).toBe(0);
    expect(clamp(99, 0, 10)).toBe(10);
  });
});

describe('fitTransform', () => {
  it('shrinks content larger than the viewport and centres it', () => {
    const { zoom, x, y } = fitTransform({ width: 2000, height: 1000 }, { width: 1000, height: 800 });
    // width is the binding constraint: 1000/2000 = 0.5, minus padding.
    expect(zoom).toBeLessThan(0.5);
    expect(zoom).toBeGreaterThan(0.4);
    // centred: equal margins left/right, top/bottom.
    expect(x).toBeCloseTo((1000 - 2000 * zoom) / 2, 5);
    expect(y).toBeCloseTo((800 - 1000 * zoom) / 2, 5);
  });

  it('never upscales past 100% for content smaller than the viewport', () => {
    const { zoom } = fitTransform({ width: 200, height: 200 }, { width: 1000, height: 800 });
    expect(zoom).toBe(1);
  });

  it('returns an identity transform when content or viewport is empty', () => {
    expect(fitTransform({ width: 0, height: 0 }, { width: 100, height: 100 })).toEqual({ zoom: 1, x: 0, y: 0 });
    expect(fitTransform({ width: 100, height: 100 }, { width: 0, height: 0 })).toEqual({ zoom: 1, x: 0, y: 0 });
  });

  it('does not zoom out below the readable floor, centring the overflow', () => {
    const content = { width: 8000, height: 6000 };
    const viewport = { width: 1000, height: 800 };
    const { zoom, x } = fitTransform(content, viewport, { minScale: 0.4 });

    expect(zoom).toBe(0.4); // true fit (~0.12) would be smaller; floored to stay legible
    expect(x).toBeCloseTo((1000 - 8000 * 0.4) / 2, 5); // centred → overflow is symmetric
    expect(x).toBeLessThan(0);
  });

  it('ignores the floor when content already fits above it', () => {
    const { zoom } = fitTransform({ width: 1100, height: 800 }, { width: 1000, height: 800 }, { minScale: 0.4 });

    expect(zoom).toBeGreaterThan(0.4);
    expect(zoom).toBeLessThan(1);
  });
});

describe('zoomAtPoint', () => {
  it('keeps the content point under the cursor fixed on screen', () => {
    const view = { zoom: 1, x: 0, y: 0 };
    const point = { x: 300, y: 200 };

    const contentUnderCursor = { x: (point.x - view.x) / view.zoom, y: (point.y - view.y) / view.zoom };
    const next = zoomAtPoint(view, point, 2);

    // The same content coordinate must still sit under the cursor after zooming.
    expect((point.x - next.x) / next.zoom).toBeCloseTo(contentUnderCursor.x, 5);
    expect((point.y - next.y) / next.zoom).toBeCloseTo(contentUnderCursor.y, 5);
    expect(next.zoom).toBe(2);
  });

  it('respects the zoom limits', () => {
    expect(zoomAtPoint({ zoom: ZOOM_LIMITS.max, x: 0, y: 0 }, { x: 0, y: 0 }, 2).zoom).toBe(ZOOM_LIMITS.max);
    expect(zoomAtPoint({ zoom: ZOOM_LIMITS.min, x: 0, y: 0 }, { x: 0, y: 0 }, 0.5).zoom).toBe(ZOOM_LIMITS.min);
  });
});
