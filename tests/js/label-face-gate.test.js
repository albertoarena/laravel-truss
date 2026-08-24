import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { labelFaceGate } from '../../resources/js/label-face-gate.js';

// Mermaid sizes every label box to the text it measures and leaves no slack, so
// the diagram must not be measured in one face and painted in another. This is
// the policy that prevents it (issue #59): wait for the face, but never hold the
// canvas blank, and pick up a late arrival exactly once.
//
// The policy is unit-tested rather than driven through a browser because it is a
// race, and asserting on a race from outside the page means winning it on
// purpose, which is not something a loaded CI machine will reliably do. The
// ordering it produces in a real browser is covered by tests/e2e/label-clipping.spec.js.

const FACES = ['13px "IBM Plex Mono"', '600 13px "IBM Plex Mono"'];

/** A document.fonts stand-in whose loads resolve when the test says so. */
const fontsThatResolveWith = (result) => {
  const pending = [];
  return {
    load: vi.fn(() => new Promise((resolve) => pending.push(resolve))),
    deliver: () => pending.forEach((resolve) => resolve(result)),
    calls: () => pending.length,
  };
};

const gateFor = (fonts, timeoutMs = 1500) => labelFaceGate({ fonts, faces: FACES, timeoutMs });
const settle = () => Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('the label face gate', () => {
  it('requests only the weights the diagram paints', async () => {
    const fonts = fontsThatResolveWith([{}]);
    gateFor(fonts).settled();

    expect(fonts.load).toHaveBeenCalledTimes(2);
    expect(fonts.load.mock.calls.map(([face]) => face)).toEqual(FACES);
    // Nothing uses the 500, and loading it would fetch a face the page never
    // paints on every cold start.
    expect(fonts.load.mock.calls.some(([face]) => face.includes('500'))).toBe(false);
  });

  it('reports the face as settled when it arrives in time', async () => {
    const fonts = fontsThatResolveWith([{}]);
    const gate = gateFor(fonts);
    const settled = gate.settled();

    fonts.deliver();

    await expect(settled).resolves.toBe(true);
  });

  it('gives up rather than holding the canvas blank', async () => {
    const gate = gateFor(fontsThatResolveWith([{}]));
    const settled = gate.settled();

    await vi.advanceTimersByTimeAsync(1500);

    await expect(settled).resolves.toBe(false);
  });

  it('asks for the faces once however many times it is consulted', () => {
    const fonts = fontsThatResolveWith([{}]);
    const gate = gateFor(fonts);

    gate.settled();
    gate.settled();
    gate.settled();

    // Filtering and focusing re-render constantly; only the first pays.
    expect(fonts.load).toHaveBeenCalledTimes(2);
  });

  it('redraws once when a late face finally lands', async () => {
    const fonts = fontsThatResolveWith([{}]);
    const gate = gateFor(fonts);
    const redraw = vi.fn();

    const settled = gate.settled();
    await vi.advanceTimersByTimeAsync(1500);
    expect(await settled).toBe(false);

    gate.whenLate(redraw);
    expect(redraw).not.toHaveBeenCalled();

    fonts.deliver();
    await settle();

    expect(redraw).toHaveBeenCalledTimes(1);
  });

  it('does not redraw when the face never turns up', async () => {
    // document.fonts.load resolves with an empty list when nothing matches, and
    // Promise.all wraps that as [[], []], which is length 2 and would look like
    // success to anyone counting the outer array.
    const fonts = fontsThatResolveWith([]);
    const gate = gateFor(fonts);
    const redraw = vi.fn();

    gate.settled();
    await vi.advanceTimersByTimeAsync(1500);
    gate.whenLate(redraw);
    fonts.deliver();
    await settle();

    // The fallback measurement is the correct one here: nothing changed under
    // it, so redrawing would be churn for no gain.
    expect(redraw).not.toHaveBeenCalled();
  });

  it('redraws once even if several renders notice the timeout', async () => {
    const fonts = fontsThatResolveWith([{}]);
    const gate = gateFor(fonts);
    const redraw = vi.fn();

    gate.settled();
    await vi.advanceTimersByTimeAsync(1500);
    gate.whenLate(redraw);
    gate.whenLate(redraw);
    gate.whenLate(redraw);
    fonts.deliver();
    await settle();

    expect(redraw).toHaveBeenCalledTimes(1);
  });

  it('reports settled from then on, so later renders do not queue redraws', async () => {
    const fonts = fontsThatResolveWith([{}]);
    const gate = gateFor(fonts);

    const settled = gate.settled();
    await vi.advanceTimersByTimeAsync(1500);
    expect(await settled).toBe(false);

    gate.whenLate(vi.fn());
    fonts.deliver();
    await settle();

    expect(await gate.settled()).toBe(true);
  });

  it('treats a failed load as no face rather than throwing', async () => {
    const fonts = {
      load: vi.fn(() => Promise.reject(new Error('network'))),
    };
    const gate = gateFor(fonts);
    const redraw = vi.fn();

    await expect(gate.settled()).resolves.toBe(true);

    gate.whenLate(redraw);
    await settle();
    expect(redraw).not.toHaveBeenCalled();
  });
});
