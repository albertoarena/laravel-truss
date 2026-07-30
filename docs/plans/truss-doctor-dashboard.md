# Plan: doctor results in the dashboard (schema doctor, Phase 4)

Status: Base panel shipped on `feature/doctor-phase-1` (PR #17): the Health toolbar
toggle, the grouped panel, node badges, heuristic markers, and click-to-focus. A round of
UX refinements from first local testing is planned next, in **Phase 4.1** below. Phase 4
of the schema doctor (see `truss-doctor.md`), built on the same branch as Phase 1.
Owner: Alberto Arena
Roadmap: part of the Schema doctor concept published on trussphp.com.
Related: `src/Http/Controllers/SchemaApiController.php` (the endpoint the payload rides);
`src/Doctor/**` and `src/Commands/DoctorCommand.php` (the engine to reuse);
`resources/js/diff-view.js` (the panel to mirror); `config/truss.php` (the `doctor`
block).

## Goal

Let a developer see the same doctor findings they get from `php artisan truss:doctor`
inside the live dashboard, tied to the diagram they are already looking at. The point of
doing it in the UI (rather than only the terminal) is the link back to the drawing:
a table with findings is badged on the canvas, and clicking a finding focuses that table.
Reading a flat list is what the CLI is for; the dashboard earns its keep by making the
findings *spatial*.

## Scope decision: ship a read-only panel first

Build the **minimum that is genuinely useful**: a "Health" panel that lists findings
grouped by table, severity badges on the diagram nodes, and click-to-focus. That is the
MVP. Everything below the line (inline fix hints, per-rule mute from the UI, severity
filtering controls, a "copy suppression" button) is explicitly deferred until the read-only
panel has been used against real schemas. Do not pre-build the controls.

## Architecture: reuse the existing gated endpoint

The schema API (`SchemaApiController`) already attaches a `diff` key to the snapshot
response for the requested connection, filtered through the same `excluded_tables` list.
The doctor payload rides the **same response** as a sibling `doctor` key. No new route, no
second fetch, and the `viewTruss` gate + middleware already protect it. This mirrors how
`diff` was done and keeps the "one gated JSON endpoint" shape.

```
GET {prefix}/api/schema?connection=modules
{
  "connection": "modules",
  "tables": [ ... ],
  "diff": { ... } | null,
  "doctor": {                          // new, null when disabled
    "summary": { "error": 2, "warning": 5, "info": 0, "total": 7 },
    "findings": [
      { "code": "TRUSS-INT-003", "severity": "error", "table": "activity_logs",
        "column": "company_uuid", "message": "...", "hint": "...",
        "confidence": "high", "category": "integrity" }
    ]
  }
}
```

### Server: extract a `DoctorReport` service (the key refactor)

Today the assembly (inject live driver, filter excluded tables, resolve rules from the
preset/config, run `DoctorRunner`, apply severity overrides + ignore) lives inside
`DoctorCommand::handle`. To reuse it from the controller without duplicating logic, pull
that assembly into a small service, for example `AlbertoArena\Truss\Doctor\DoctorReport`,
with one method:

```php
public function for(string $connection, array $snapshot): FindingCollection
```

Then:
- `DoctorCommand` calls the service instead of assembling inline (its exit-code and
  formatter logic stay in the command). This is a pure refactor: the existing command
  tests must stay green unchanged, which is the proof it is behaviour-preserving.
- `SchemaApiController` calls the same service, serialises the `FindingCollection` via the
  existing `JsonFormatter` shape (already the array the CLI `--format=json` emits), and
  attaches it under `doctor`. `null` when `truss.doctor` is disabled (see config below).

Note the driver: the controller has a live connection, so injecting the real driver name
(for the engine-aware IDX-001) is available exactly as in the command.

### The "no data exposed" and purity invariants still hold

Doctor already reads only the structure snapshot, so nothing here touches row data. The
controller filters `excluded_tables` before the doctor runs, identical to the diagram and
the diff, so an excluded table cannot surface a finding either. `src/Doctor/` and
`src/Introspection/` stay HTTP-free; only the controller (already the HTTP boundary) knows
about the request.

## Frontend

Template to copy: `resources/js/diff-view.js` (the "Changes" panel) plus its toolbar
toggle and `resources/css/truss.css` styles. New module `resources/js/doctor-view.js`,
wired from `truss.js`. No build step, no CDN, CSP-safe, consistent with the frontend rules.

1. **Toolbar toggle**: a "Health" (or "Doctor") button next to "Changes", shown only when
   the response carries a non-null `doctor`. Its label carries the total count, for
   example "Health (7)", coloured by the worst severity present.
2. **Panel**: findings grouped by table (same grouping as the CLI table), each row a
   severity chip + code + location + message. Reuse the diff panel's show/hide and the
   overlay anchoring fix already in place for the legend.
3. **Diagram badges**: a small count badge on each table node that has findings, worst
   severity colour. This is the spatial payoff. Rendering hook lives with the Mermaid
   post-render step; badge data is derived purely from `doctor.findings` (unit-testable).
4. **Click to focus**: clicking a finding (or a node badge) focuses that table, reusing the
   existing selection/focus path (`selection.js`) and URL state (`url-state.js`) so the
   focused table is shareable/bookmarkable exactly like the rest of the dashboard.
5. **Connection-aware**: switching the connection picker re-fetches and the panel + badges
   follow, because `doctor` is part of the per-connection response. Free from the reuse.

### Config

One switch, defaulting on when the doctor itself is enabled:

```php
'doctor' => [
    // ...existing Phase 1 keys...
    'dashboard' => (bool) env('TRUSS_DOCTOR_DASHBOARD', true),
],
```

When false, the controller sends `doctor: null` and the toggle/badges never appear. This
lets a team keep the CLI/CI doctor but hide it from the in-app viewer if they prefer. The
panel also honours the same preset/ignore/severity config as the CLI, because it runs
through the same `DoctorReport` service.

## Testing (TDD, no exceptions)

- **Pest**: `DoctorReport` service (assembles rules, injects driver, filters excluded,
  honours overrides/ignore) with the in-memory `SchemaBuilder` fixture. `DoctorCommand`
  tests stay green unchanged (refactor proof). `SchemaApiController` feature tests: `doctor`
  present with the right summary/shape; `null` when disabled; excluded tables never appear
  in findings; gate still 404s the unauthorised.
- **Vitest** (pure JS): grouping findings by table, per-node badge counts and worst-severity
  colour, summary/label formatting. No DOM needed for these.
- **Playwright**: panel toggles open/closed; a badged node renders; clicking a finding
  focuses the table and updates the URL; switching connection updates panel + badges;
  panel absent when `doctor` is null.

## Constraints and rollout

- **Release-gated demo.** The live `/demo/` runs the shipped frontend from the package's
  latest **release tag**, in the separate docs repo (`albertoarena/laravel-truss-docs`).
  So the panel does not appear on trussphp.com until a release ships and the docs site
  rebuilds. The demo's sample `schema.json` for the multi-connection page may need a
  `doctor` block added there (docs repo, not here) to demo it; preview unreleased frontend
  with `PACKAGE_REF=main`.
- **Docs.** New `docs/reference/doctor` gains a "In the dashboard" section; README feature
  list gains one bullet; `docs/DECISIONS.md` records the "doctor rides the schema endpoint"
  decision. Docs-site (separate repo) updated when the release lands.
- **No new runtime dependency, no network call, structure-only.** As Phase 1.

## Definition of done

Read-only Health panel + node badges + click-to-focus, behind `truss.doctor.dashboard`.
`DoctorReport` extracted with the CLI unchanged in behaviour. Pest + Vitest + Playwright
green across the CI matrix. README + `docs/reference/doctor` + `docs/DECISIONS.md` updated
in the same PR. Demo/docs-site follow-up queued for the release (docs repo).

## Decisions (resolved)

1. **Panel name**: **Health**. It reads as a status and pairs naturally with the count
   badge ("Health (7)"), sitting beside the existing "Changes" toggle.
2. **Badge scope**: **badge every table that has any finding, coloured by its worst
   severity** (red for error, amber for warning), so warnings are not invisible on the
   canvas.
3. **Default depth of the panel**: **expanded**, with groups auto-collapsing only when the
   table count exceeds `truss.large_schema.warn_above`, so large schemas start compact.
4. **Confidence display**: **yes, mark heuristics** with a subtle marker, so low-confidence
   findings are not mistaken for certainties. Matches the `confidence` field the CLI/JSON
   already expose.
5. **Landing**: **extend the Phase 1 branch** (`feature/doctor-phase-1`, PR #17) with the
   dashboard rather than a separate PR, as further commits: first the server half
   (`DoctorReport` + endpoint, Pest), then the frontend panel (JS + Playwright).

## Phase 4.1: UX refinements (after first local testing, 2026-07-30)

Four refinements from running the panel against a real schema. All frontend, all on the
same branch, each test-first (Vitest for pure mapping, Playwright for interaction). Ordered
so the shared overlay behaviour lands before the pieces that depend on it: icon, then
overlay coordination, then maximize, then in-table markers.

1. **A legible, animated Health icon.** Replace the faint ECG-pulse glyph with a bolder
   **heart-with-pulse** SVG that reads at 15px. The button is shown whenever the panel is
   enabled: a **calm green heart with no count when the schema is clean**, and the
   worst-severity colour with the count when there are findings. Add a gentle CSS
   `@keyframes` **pulse** (scale/opacity) that runs **only when there are error or warning
   findings** (an attention beat), stays steady when clean, and is disabled under
   `prefers-reduced-motion`. No JS animation. (The earlier "blank icon" was a stale
   compiled view / cached CSS or the too-thin glyph, not a missing-when-clean case.)

2. **One overlay at a time, aligned.** Today Legend, Changes, Health, the Export menu, and
   the More menu each toggle independently, so they stack, overlap, and the Export menu is
   anchored differently from the rest. Introduce a single "active overlay" controller:
   opening any one popover or menu **closes the others**, and they all share one top-right
   anchor and offset. This supersedes the standalone "Export view menu misalignment" note.
   Playwright: opening B closes A; the Export menu no longer overlaps the Health panel.

3. **Maximize / restore the Health panel.** A control in the panel header expands it from
   the compact 300px popover to a large centred overlay (~80% of the viewport, scrollable),
   with a restore control back to the compact size. The maximized panel is modal, so it
   participates in the single-active-overlay rule from refinement 2 (opening it closes the
   others; opening another closes it). Purely a reading-comfort affordance.

4. **In-table finding markers.** When the Health view is active, annotate the rendered
   diagram so a table's own findings are visible on it, reusing the existing
   `annotateColumnTypes` post-render pass over the Mermaid SVG:
   - A per-column finding (e.g. INT-003 on `company_uuid`) marks **that column's name**
     in the severity colour with a dotted underline (the same cue the enum values use).
     An injected icon is avoided on purpose: Mermaid renders each cell at a fixed width and
     clips overflow, so an appended glyph disappears; the underline adds no width.
   - A table-level finding with no column (e.g. INT-001 "no primary key", INT-007 pivot
     without a unique key) marks the **table header** instead.
   - Clicking a marker opens the **existing popover** (`#truss-popover`) with the finding's
     message and hint. Markers appear only while the Health view is on, so the diagram stays
     clean otherwise.
   - Pure mapping (findings -> column/table -> marker model) is Vitest-tested; the marker
     render and the click-to-popover are Playwright-tested.

Definition of done for 4.1: heart-pulse icon (with reduced-motion-safe animation), single
active overlay with aligned anchors, maximize/restore, and in-table markers with popovers,
all green across Vitest + Playwright, on PR #17.
