import { describe, it, expect } from 'vitest';
import { TOOLBAR_BREAKPOINTS, layoutClasses } from '../../resources/js/toolbar-layout.js';

// The toolbar's responsive steps used to be viewport media queries, which is
// wrong for an embedded dashboard: a Filament panel takes ~470px of sidebar, so
// a 1280px viewport leaves the bar ~810px and none of the steps fired. The
// Filter field absorbed the whole shortfall and rendered at a content width of
// zero. These breakpoints are measured against the bar's own width instead.

describe('layoutClasses', () => {
  it('leaves a full-width bar unclassed', () => {
    expect(layoutClasses(1440)).toEqual([]);
    expect(layoutClasses(1025)).toEqual([]);
  });

  it('marks a bar at or under 1024 compact, whatever the viewport is', () => {
    expect(layoutClasses(1024)).toEqual(['is-compact']);
    expect(layoutClasses(1000)).toEqual(['is-compact']);
  });

  // The measured failure: a 1280px window with a panel sidebar leaves the bar
  // about 810px, which is a narrower layout than any viewport step reached.
  it('folds an 810px bar the way an 810px window already did', () => {
    expect(layoutClasses(810)).toEqual(['is-compact', 'is-condensed']);
  });

  // max-width media queries stack, so the narrower steps have always been
  // additive. Keeping them cumulative is what lets the CSS blocks be prefixed
  // one for one instead of rewritten.
  it('stacks the narrower steps the way max-width queries did', () => {
    expect(layoutClasses(900)).toEqual(['is-compact', 'is-condensed']);
    expect(layoutClasses(560)).toEqual(['is-compact', 'is-condensed', 'is-minimal']);
    expect(layoutClasses(320)).toEqual(['is-compact', 'is-condensed', 'is-minimal']);
  });

  // A ResizeObserver reports 0 for a display:none element. Treating that as the
  // narrowest bar would fold every control away and leave the class behind when
  // the bar is shown again, so a zero width keeps the roomy layout.
  it('treats an unmeasurable width as roomy', () => {
    expect(layoutClasses(0)).toEqual([]);
    expect(layoutClasses(NaN)).toEqual([]);
  });

  it('exposes the breakpoints so the stylesheet can be checked against them', () => {
    expect(TOOLBAR_BREAKPOINTS.map((b) => b.maxWidth)).toEqual([1024, 900, 560]);
    expect(TOOLBAR_BREAKPOINTS.map((b) => b.className))
      .toEqual(['is-compact', 'is-condensed', 'is-minimal']);
  });
});
