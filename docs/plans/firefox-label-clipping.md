# Plan: diagram labels clipped in Firefox on Windows

Every label in the ER diagram loses its last character or so in Firefox on
Windows: table names, column names and column types alike, in proportion to
their length. Reported as
[#59](https://github.com/albertoarena/laravel-truss/issues/59) by
[@diboma](https://github.com/diboma), against the live demo.

The cause is a font race, and it is now measured rather than suspected. This
plan records the diagnosis, the fix, and how to verify the fix on a real
Windows machine before claiming it.

## Diagnosis

Mermaid sizes every label to the text it measures, with no slack. From the
shipped bundle (`erBox.ts` -> `addText()`):

```js
h = d.getBoundingClientRect(), f.attr("width", h.width), f.attr("height", h.height)
```

`d` is the inner div, `f` the `<foreignObject>`. There is no padding term, and a
`<foreignObject>` clips its content. Measured slack on real labels is around
0.002px, so any disagreement between measure time and paint time is visible
immediately rather than absorbed.

`render()` never waits for the webfont. With `font-display: swap`, the labels
can be **measured in a fallback face and painted in IBM Plex Mono**. On Windows
the fallback that resolves is Consolas.

| face | advance | source |
|---|---|---|
| Consolas | ~0.55em | Windows system font |
| IBM Plex Mono | ~0.60em | the shipped webfont |

0.60 / 0.55 = **1.0909**. Every label is painted about 9 percent wider than the
box it was measured into, so the overflow scales with label length and the last
glyph is shaved.

Measured with a console probe on the live demo, at 100 percent zoom and
`devicePixelRatio` 1:

| environment | node labels | clipped | median ratio |
|---|---|---|---|
| Firefox, Windows 11 | 78 | **78** | **1.0904** |
| Chrome, Windows 11 | 78 | 0 | 1.0000 |
| Firefox, macOS | 78 | 0 | 0.9998 |
| Chrome, macOS | 78 | 0 | 1.0000 |

Every individual label implies 1.0908, against a predicted 1.0909.

This also explains each observation that looked contradictory:

- **macOS is blind to it** in both engines, because the fallback there is Menlo
  at ~0.6023em, which agrees with IBM Plex Mono. The same is true of the Linux
  font sets CI runs on, which is why the test suite never saw it.
- **Chrome on Windows is fine** because it wins the race and measures in the
  real face. The bug is timing dependent, and engines schedule font fetches
  differently.
- **Opening the developer tools before loading makes it disappear**, at any
  panel width. That rules out viewport size and points at load timing, which is
  what a race is.
- **A hard reload and a private window both still show it**, because both are
  cold-cache conditions and both lose the race.

Two published precedents for the same symptom class:
[mkdocs-material#3528](https://github.com/squidfunk/mkdocs-material/issues/3528)
and [mermaid#5785](https://github.com/mermaid-js/mermaid/issues/5785).

## The fix

Three parts. The first is the fix, the second closes its failure mode, the third
makes the failure mode rare.

### 1. Gate the render on the font

The policy lives in `resources/js/label-face-gate.js`, so it can be unit-tested
with fake timers rather than raced against in a browser. `render()` awaits it
before calling `mermaid.render`:

```js
const faceGate = labelFaceGate({
  fonts: document.fonts,
  faces: ['13px "IBM Plex Mono"', '600 13px "IBM Plex Mono"'],
  timeoutMs: 1500,
});

const measuredWithFace = await faceGate.settled();
// ...draw...
if (!measuredWithFace) faceGate.whenLate(render);
```

Two details that are easy to get wrong:

- **Use `document.fonts.load()`, not `document.fonts.ready`.** `ready` resolves
  when nothing is *pending*, and it does not itself request anything. Before the
  diagram exists nothing has asked for the face, so `ready` can resolve
  instantly with the font unloaded. What it waits for depends on what else the
  surface happens to have requested, which differs between the dashboard, the
  demo and the test harness. A gate that behaves differently on the surface you
  test and the surface users hit is worse than no gate. For the same reason,
  `document.fonts.check()` is not a reliable readiness signal on its own: it
  reports `true` for any family it takes to be a system font, including one that
  is not installed anywhere.
- **Two faces, not three.** `font-weight: 500` appears only in its own
  `@font-face` rule and is used nowhere, so loading it would force a cold-start
  download of a face the page never paints. That unused face should be either
  used or dropped, as its own change.

Only the first render pays for it. Later renders, from filtering or focusing,
await something already settled.

### 2. Re-render if the gate times out

A timeout race reintroduces the bug by design on a slow connection: the timer
fires, the diagram is measured in the fallback, and swap clips it again. That
trade is correct (a briefly clipped diagram beats a blank canvas) but it must
not be the end state. If the timeout won, re-render once when the face actually
arrives.

Without this, part 1 is only a probabilistic fix. With it, the diagram is always
correct, at worst after one extra render on a cold slow load.

### 3. Preload the woff2

In `resources/views/index.blade.php`, alongside the stylesheet link:

```blade
<link rel="preload" as="font" type="font/woff2"
      href="{{ route('truss.asset', 'ibm-plex-mono-400.woff2') }}" crossorigin>
```

The font is currently discovered only when the stylesheet parses. Preloading
starts the fetch immediately and makes the part 2 path rare. `crossorigin` is
required even for a same-origin font, or the preload is not reused.

This shrinks the race window. It does not close it, so it is an addition to
part 1, never a substitute.

## Considered and not taken

**`font-display: optional`.** Changing the three `@font-face` rules from `swap`
to `optional` would fix this in one line: the browser either has the face almost
immediately or commits to the fallback for that page load and never swaps, so
measure and paint agree by construction, with no JavaScript at all. It is
rejected because on a cold cross-network fetch, which is exactly the demo,
visitors would see the whole dashboard in a system mono rather than the typeface
the design is built on. Correctness bought with identity. Worth revisiting if
the gate ever proves costly.

**Removing the paint-time `font-weight: 600`** on focused, diffed and flagged
table names. Ruled out by the evidence: unbadged tables and column type cells,
which no rule ever bolds, are clipped in the same proportion. It would also have
changed the look of those states for everyone.

**Padding every `foreignObject` after render.** A constant padding large enough
to cover a 9 percent mismatch is a proportional fudge on the hot path, and it
hides the cause rather than removing it.

**Unifying the three font stacks.** The measure stack
(`resources/js/truss.js`), the paint stack (`--bp-mono` in
`resources/css/truss.css`) and the SVG export stack are all slightly different,
which is a real latent defect of the same family. It is not the cause here, and
changing the export stack alters SVGs people have committed to their own
repositories. Own change, own tests, later.

## Un-clipping the label boxes

A CSS rule under `#truss-canvas` stopping the label boxes clipping their content
is the long-standing community remedy for this symptom class, and it is worth
having as a belt once the geometry is correct.

It must ship **after** parts 1 and 2, not before. At the measured 9 percent, an
un-clipped label does not politely reveal one more glyph: it paints into the
neighbouring cell, because attribute cells sit directly against each other. A
clipped label is better than an overlapping one. Once the geometry is right the
residual is sub-pixel (0.9998 measured in Firefox on macOS), which is exactly
what a belt should be absorbing.

This also fixes the assertion the test needs. "The label stays inside the entity
box" is too loose, because the entity box is sized by the widest attribute row
and names have room to spare. The guard is **no label's painted ink overlaps the
next cell's ink**.

## Tests

TDD, ordering rule: write the test, watch it fail for the right reason, then
implement. What that produced, and why it is split the way it is.

**The gate's policy is unit-tested, not driven through a browser.**
`tests/js/label-face-gate.test.js` covers `resources/js/label-face-gate.js` with
fake timers: it loads only the two weights the diagram paints, reports settled
when the face arrives, gives up rather than holding the canvas blank, asks once
however many renders consult it, redraws exactly once on a late arrival, and
does *not* redraw when the face never turns up.

That last case is the one worth having. `fonts.load()` resolves with the faces
that matched, so an unmatched family gives `[]`, and `Promise.all` wraps that as
`[[], []]`, whose length is 2. Anything counting the outer array reads "nothing
arrived" as two arrivals and redraws forever.

Driving this from Playwright was tried and abandoned. The timeout path only
happens when the face loses a race, and winning a race in a chosen direction
from outside the browser is not reliable: the face is requested when the
stylesheet is parsed, while the gate's timer starts at the first render, which
waits on the schema fetch, so the two clocks drift apart by however long startup
takes. The browser spec flaked two to four times in twelve on a loaded machine,
and every attempt to stabilise it moved the delay rather than removing the race.
Fake timers decide the ordering instead of hoping for it.

**What is asserted in a real browser**, in `tests/e2e/label-clipping.spec.js`:

- **The ordering.** With the face held back, the diagram must not be drawn until
  it has arrived. This fails without the fix for the most direct possible
  reason: the 400 weight is never requested at all before the draw, because
  nothing needs it until the labels exist.
- **The geometry.** No label's painted ink exceeds the box it was given. This is
  a regression guard rather than a red phase, since it is green on any
  metric-compatible fallback.

Both assert ordering and geometry rather than metrics. A metric assertion would
be green on every machine available to run it: macOS falls back to Menlo and
Linux to DejaVu Sans Mono, both of which agree with IBM Plex Mono to within a
third of a percent, which is exactly why the suite never caught this.

**Two prerequisites made the harness capable of expressing the bug at all.**
`tests/e2e/harness.html` loaded no stylesheet, so there was no `@font-face` to
race against and no paint-side font stack to diverge from the measured one. It
now loads the real stylesheet on `?css=1`, opt-in because a webfont changes text
metrics and the other specs assert on layout. And `tests/e2e/serve.mjs` now
serves `.woff2` with the right content type and resolves every font request to
`resources/fonts`, because the `@font-face` URLs are relative and resolve
against the stylesheet's directory, which is the asset route in production but
not under the test server.

**Firefox runs one spec.** The label-geometry spec is scoped to a second engine
with `testMatch` in `playwright.config.js`, and CI installs Firefox alongside
Chromium. The interaction specs stay on Chromium alone: they would be
re-verifying logic that is not engine-specific at roughly double the frontend CI
time. The accessibility step stays on Chromium for the same reason, and needs no
change, since its script names the a11y spec directly.

## Verifying on a real Windows machine

CI cannot see this and neither can a macOS or Linux workstation, so the fix has
to be confirmed on Windows before it is described as fixed. It does not need a
deployment. Both routes below serve over a local network, so a Windows machine
on the same router can reach them.

**Prefer Route B if a local Laravel application already points at the package
checkout.** It is the only route that exercises all three parts, it serves the
real asset route (where the relative `@font-face` URLs resolve the way they do
for users), and it needs no copy step, because the package is already the live
dependency. Route A is the fallback when no such application is to hand.

### Route A: the demo shell, over the local network

Covers parts 1 and 2, which are the fix itself.

```bash
cd ../laravel-truss-docs
PACKAGE_PATH=../laravel-truss npm run copy-demo-assets
npm run build
```

`PACKAGE_PATH` exists for exactly this: it builds the demo shells against a
local package checkout instead of the latest release, so an unreleased frontend
change is visible. Then serve the built output on all interfaces and open the
printed network address on the Windows machine.

**A LAN is too fast, and this is the trap.** The bug is a race, and over a local
network the woff2 arrives in a couple of milliseconds, so Firefox will very
likely win the race and show nothing wrong **even without the fix**. A green
result from that setup means nothing.

So the server must delay the font deliberately, and the delay must be applied to
the **unfixed** build first:

1. **Control run.** Unfixed build, woff2 delayed by a few hundred milliseconds.
   Confirm on Windows Firefox that the labels truncate. If they do not, increase
   the delay until they do. Until this run truncates, the rig cannot prove
   anything.
2. **Fixed run.** Same delay, fix applied. Confirm the labels are intact.
3. **Regression.** Confirm Chrome on Windows and both engines on macOS are still
   clean, and that first paint on a warm load is not visibly slower.

Two things will otherwise waste a run:

- **Test with the developer tools closed.** Opening them before load hides the
  bug. To inspect a broken render, load it with them closed, then open them: the
  client re-renders only on filter and focus changes, never on resize, so the
  broken geometry survives the panel opening.
- **Use a private window for each run**, so the font cache is cold.

### Route B: the dashboard itself

Covers all three parts, including the preload, which lives in the Blade view and
is therefore not exercised by the demo shell at all (the demo is hand-authored
static HTML that never renders the view).

Use a local Laravel application whose Truss dependency is a path repository
pointing at the package checkout, so edits are live with no build or copy step.

**Reaching it from another machine.** A local development server that serves
`.test` domains typically resolves them only on the host machine and binds to
localhost, so another machine on the network cannot reach it as it stands. The
simplest way around that is to bypass it for the test:

```bash
php artisan serve --host 0.0.0.0 --port 8000
```

`php artisan serve` binds to localhost by default, so `--host 0.0.0.0` is what
makes it reachable. Then open `http://<host-lan-address>:8000/truss` from the
Windows machine. Two notes:

- The built-in server is single threaded unless told otherwise, which serialises
  requests and distorts exactly the timing under test. Set
  `PHP_CLI_SERVER_WORKERS=4` or higher.
- Truss's gate is consulted only outside local environments, so an app running
  with `APP_ENV=local` serves the dashboard without any authorization setup. If
  the environment is anything else, expect a 404 rather than a prompt.

**Delaying the font.** Add a middleware to the *host application* that sleeps on
requests whose path ends in `.woff2`. It must not go into the package: this is
test scaffolding for one investigation, not a feature, and an artificial delay
shipped to users would be worse than the bug.

**A public tunnel is an alternative worth knowing about.** Most local
development tools can expose a site through a temporary public URL. That solves
the latency problem for free, because the font then crosses the real internet
and the race reproduces without any artificial delay. The cost is that the whole
application, not just the Truss dashboard, is publicly reachable for the
duration at a URL that is unlisted rather than protected. Reasonable for a
throwaway test on a development database, not for anything else.

On macOS the firewall may block the first incoming connection. Allow it for
`php` or `node` when prompted.

## Delivery

- Branch and PR, per the usual workflow for a code change.
- The fix, the tests and this plan ship together.
- **Do not claim #59 is fixed on the strength of CI.** CI is structurally blind
  to this. The Windows verification above is what earns the claim.
- Credit @diboma in the changelog and the release notes. The report produced the
  zero-slack finding, the edge-label control and the environment data that
  identified the fallback face by name, and the developer-tools observation that
  pinned it to load timing was theirs.
- Comment on the issue when the release ships and ask for a re-check. Close it
  on the reporter's confirmation, not on ours.
