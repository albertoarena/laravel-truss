// Which responsive step the toolbar is in, decided by the bar's own width.
//
// These steps were `@media (max-width: …)` rules, which ask the viewport. That
// is the wrong question for a dashboard embedded in something: a Filament panel
// spends roughly 470px on its sidebar, so a 1280px viewport leaves the toolbar
// about 810px and no viewport step fires. Every toolbar child except the Filter
// field is floored at its min-content size, so the whole shortfall lands on the
// one item that cannot refuse it and the field renders at a content width of
// zero (a 24px square of its own padding). Measuring the bar instead makes an
// 810px panel behave exactly like an 810px window already did.
//
// Kept DOM-free so the mapping is unit-tested; truss.js wires the observer.

/** Narrowest-last, matching the source order of the blocks they replaced. */
export const TOOLBAR_BREAKPOINTS = [
  // Small desktop: the secondary controls fold behind the ⋯ button.
  { maxWidth: 1024, className: 'is-compact' },
  // Tablet: field labels go, inputs stay.
  { maxWidth: 900, className: 'is-condensed' },
  // Phone: mark-only branding and a full-width filter.
  { maxWidth: 560, className: 'is-minimal' },
];

/**
 * The classes a toolbar of `width` pixels should carry.
 *
 * Cumulative, because max-width queries stack: at 560px all three apply, which
 * is what lets each CSS block keep its rules unchanged behind a class prefix.
 *
 * A width of 0 (or anything unmeasurable) means the bar is display:none or not
 * laid out yet. Folding everything away on that reading would leave the phone
 * layout stuck on a bar that is later shown at full width, so it reads as roomy.
 *
 * @param {number} width  the toolbar's border-box width in CSS pixels
 * @returns {string[]}
 */
export function layoutClasses(width) {
  if (!Number.isFinite(width) || width <= 0) return [];

  return TOOLBAR_BREAKPOINTS
    .filter((breakpoint) => width <= breakpoint.maxWidth)
    .map((breakpoint) => breakpoint.className);
}

/**
 * Keep `host`'s class list in step with `toolbar`'s width for as long as the
 * page lives. Returns a disposer, mostly for tests.
 *
 * ResizeObserver is used rather than a window resize listener because the bar
 * can change width without the window doing so: a Filament sidebar collapses,
 * a split pane is dragged, a container animates open.
 *
 * @param {Element} toolbar  the element whose width decides the step
 * @param {Element} host  the element carrying the classes (#truss-app)
 * @returns {() => void}
 */
export function observeToolbarLayout(toolbar, host) {
  if (!toolbar || !host) return () => {};

  const apply = () => {
    const wanted = layoutClasses(toolbar.getBoundingClientRect().width);

    for (const { className } of TOOLBAR_BREAKPOINTS) {
      host.classList.toggle(className, wanted.includes(className));
    }
  };

  apply();

  if (typeof ResizeObserver !== 'function') return () => {};

  const observer = new ResizeObserver(apply);
  observer.observe(toolbar);

  return () => observer.disconnect();
}
