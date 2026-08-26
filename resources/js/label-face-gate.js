// When the diagram may be measured.
//
// Mermaid sizes every label box to the text it measures and leaves no slack at
// all, so the box is only ever right for the face it was measured in. Render
// without waiting for IBM Plex Mono and `font-display: swap` will repaint wider
// glyphs into boxes measured in the system fallback, clipping the last
// character of every label. Where the fallback is metric-compatible (Menlo on
// macOS, DejaVu Sans Mono on Linux) nothing shows, which is why this went
// unseen; on Windows the fallback is Consolas at roughly 0.55em against IBM Plex
// Mono's 0.60em, and every label overflowed by about 9 percent. Issue #59.
//
// The policy has three parts, and the third is what makes the second safe:
// wait for the face; give up rather than holding the canvas blank on a slow
// connection; and redraw once if the face turns up after we gave up.

/**
 * @param {object}   options
 * @param {FontFaceSet} options.fonts    usually document.fonts
 * @param {string[]} options.faces       CSS font shorthands to load
 * @param {number}   options.timeoutMs   how long to wait before drawing anyway
 */
export function labelFaceGate({ fonts, faces, timeoutMs }) {
  let gate = null;
  let load = null;
  let redrawScheduled = false;

  const start = () => {
    if (!gate) {
      load = Promise.all(faces.map((face) => fonts.load(face))).catch(() => []);
      gate = Promise.race([
        load.then(() => true),
        new Promise((resolve) => setTimeout(() => resolve(false), timeoutMs)),
      ]);
    }
    return gate;
  };

  return {
    /**
     * Resolves true if the faces are available, false if we stopped waiting.
     * Only the first call fetches; renders are frequent and the rest are free.
     *
     * fonts.load() rather than fonts.ready: ready resolves once nothing is
     * pending and never requests anything itself, so before any label exists it
     * can resolve immediately with the face still unloaded. What it waits for
     * depends on whatever else the surface happens to have asked for, which
     * differs between the dashboard, the demo and the test harness.
     */
    settled: start,

    /**
     * Run `redraw` once, if and when a face we stopped waiting for arrives.
     * Normally called after `settled()` resolved false; starting the load here
     * too keeps a caller that gets the order wrong from dereferencing null.
     */
    whenLate(redraw) {
      start();
      if (redrawScheduled) return;
      redrawScheduled = true;
      load.then((results) => {
        redrawScheduled = false;
        // fonts.load() resolves with the faces that matched, so an unmatched
        // family gives [], and Promise.all wraps that as [[], []]. Flatten
        // before counting, or "nothing arrived" reads as two arrivals.
        if (!results.flat().length) return; // the fallback measurement stands
        gate = Promise.resolve(true);
        redraw();
      });
    },
  };
}
