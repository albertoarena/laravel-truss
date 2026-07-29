# Plan: Data dictionary + DBML export

Status: draft for review (not started)
Owner: Alberto Arena
Related: enhancement roadmap item (2); `docs/DESIGN.md` (Frontend, export pipeline)

## Goal

Add two client-side, structure-only exports next to the existing JSON / CSV /
PNG / SVG:

1. A **Markdown data dictionary**: tables, columns, types, keys, indexes, and
   foreign keys, formatted for pasting into a README or wiki.
2. A **DBML** export: opens in dbdiagram.io and other DBML tools.

Both operate on the snapshot the browser already holds, so no backend change, no
new dependency, no CDN, and the no-data guarantee is unchanged.

## Background (grounded in the code)

- Per-table serializers already live in `resources/js/table-export.js`
  (`toJson`, `toCsv`), pure functions unit-tested in
  `tests/js/table-export.test.js`. The new work mirrors this exactly.
- The whole-diagram export menu is built by `showExportMenu(anchor)`
  (`resources/js/truss.js:426`) and currently offers "Export PNG" / "Export
  SVG"; `runMenuAction(act)` (truss.js:280) dispatches actions and already has
  `downloadFile(name, content, mime)`.
- The per-table menu is built by `showTableMenu(anchor, table)` (truss.js:241)
  with Copy JSON / Download JSON / Download CSV.
- The PNG/SVG export reflects the **current filter/focus selection** (what is on
  screen), not the full schema.
- `website/scripts/copy-demo-assets.mjs` copies all of `resources/js`
  recursively into the demo, so new modules ship to the live demo automatically.

## Schema shape (input)

Each table object (as held in the browser, per `docs/DESIGN.md`):

```
{ name, columns: [{ name, type, nullable, default }],
  primary_key: [..], indexes: [{ name, columns:[..], unique }],
  foreign_keys: [{ name, columns:[..], references_table, references_columns:[..],
                  on_update, on_delete }] }
```

Types are the **native full type** (`varchar(255)`, `bigint unsigned`). Keys are
composite-first (arrays), so composite PK / index / FK must be handled.

## Design

### New pure modules (mirror `table-export.js`)

- `resources/js/markdown-export.js`
  - `tableToMarkdown(table)`: one table section (heading + column table + index
    and FK lines).
  - `schemaToMarkdown(tables, meta?)`: full document (title, optional connection
    line, one section per table).
- `resources/js/dbml-export.js`
  - `schemaToDbml(tables)`: full DBML (a `Table { }` block per table, then `Ref:`
    lines for foreign keys).
  - internal `tableToDbml(table)` helper for one block.

### Markdown format (per table)

```
## users

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| id | bigint unsigned | no |  | PK |
| company_id | char(36) | no |  | FK |
| email | varchar(255) | no |  |  |

Indexes: users_email_unique (email) UNIQUE
Foreign keys: company_id -> companies.id (on delete: cascade)
```

- Key column mirrors the diagram badges (PK, FK, or "PK, FK"), reusing the same
  derivation as `toCsv`.
- Escape `|` and newlines in cell values so the table cannot break.
- Whole document: `# Data dictionary` + optional `Connection: <name>` line, then
  a `## <table>` section per table. Empty selection yields the title only.

### DBML format

```
Table users {
  id "bigint unsigned" [pk]
  company_id "char(36)"
  email "varchar(255)"
}

Ref: posts.user_id > users.id
```

Rules:
- **Type quoting**: pass the native type through; wrap in double quotes when it
  contains a space or a character DBML would misparse (e.g. `bigint unsigned`,
  `enum('a','b')`). This is intentionally lossy (same stance as the native vs
  Laravel label caveat in `docs/DESIGN.md`).
- **Primary key**: single-column PK gets `[pk]` on the column; composite PK is
  emitted as an `indexes { (a, b) [pk] }` block inside the table.
- **Column settings**: add `[not null]` when the column is not nullable and
  `[default: ...]` when a default is present (defaults are structure and in
  scope). When both a key and settings apply, merge into one `[...]` group.
- **Indexes**: non-primary indexes become an `indexes { }` block, `[unique]`
  where applicable.
- **Foreign keys**: one `Ref:` per FK, `Ref: t.col > refTable.refCol`; composite
  FK uses `Ref: t.(a,b) > r.(x,y)`. Emit a Ref only when the referenced table is
  present in the exported set, so a filtered export never produces a dangling
  Ref (invalid DBML).
- **Enums**: kept as a quoted column type string in v1; no separate `Enum`
  blocks (noted as a limitation).

### Export scope

Match the PNG/SVG behaviour: export the **current filter/focus selection** (the
same table set that feeds the diagram generator), so "export what I see" is
consistent across image and text exports. To export everything, clear the filter
/ focus first. DBML Refs to tables outside the set are dropped (see above).

### UI wiring (`resources/js/truss.js`)

- Extend `showExportMenu` (line 426) with two items:
  `<button data-act="md">Data dictionary (Markdown)</button>` and
  `<button data-act="dbml">DBML</button>`.
- Extend `runMenuAction` to handle them: build from the current selection and
  `downloadFile('schema.md', schemaToMarkdown(...), 'text/markdown')` and
  `downloadFile('schema.dbml', schemaToDbml(...), 'text/plain')`.
- Source the selected tables from the same array the diagram generator uses, so
  exports and the on-screen diagram never disagree.
- (Optional, pending decision) add "Download Markdown" to the per-table
  `showTableMenu`, reusing `tableToMarkdown`.

## TDD test plan (write failing tests first)

Per the repo rule, tests land before implementation.

### Vitest (pure logic)

`tests/js/markdown-export.test.js`
- per-table: heading is `## <name>`; header row exact; one row per column; key
  column shows PK / FK / "PK, FK" (reuse `roleUser` composite fixture); nullable
  and default rendered; index and FK lines present; a value containing `|` is
  escaped.
- whole-schema: title present; one `##` section per table; empty array yields
  just the title.

`tests/js/dbml-export.test.js`
- single-column PK renders `[pk]` on the column.
- composite PK renders an `indexes { (a, b) [pk] }` block (use `roleUser`).
- `bigint unsigned` is quoted; a plain `integer` is not.
- `[not null]` present for non-nullable, absent for nullable; `[default: ...]`
  when a default exists.
- one `Ref:` per FK with correct direction; composite FK uses `(a,b)`.
- a Ref whose referenced table is NOT in the exported set is dropped.
- `enum('a','b')` stays a quoted type, no `Enum` block.

Fixtures: reuse `tests/js/fixtures.js`; add a small two-table related fixture if
the existing ones do not cover a cross-table FK for the Ref tests.

### Playwright (rendering / interaction)

`tests/e2e/truss.spec.js` (extend, modelled on "clicking a table name opens the
export/focus menu", line 147)
- open the diagram export menu; assert it lists "Data dictionary (Markdown)" and
  "DBML".
- click each and capture the `download` event; assert the filename
  (`schema.md`, `schema.dbml`) and that the payload is non-empty and contains a
  sentinel (e.g. `# Data dictionary`, `Table `).

No PHP changes, so no Pest tests.

## Docs to update in the same change (docs-in-sync rule)

- `README.md`: features list mentions the Markdown data dictionary and DBML
  exports.
- `website/src/content/docs/`: the page describing exports (locate the one
  listing PNG/SVG/JSON/CSV) gains the two new formats.
- `docs/DESIGN.md`: the Frontend export bullet (JSON/CSV/PNG/SVG) gains MD/DBML,
  with the DBML lossy-type caveat noted next to the existing type-label caveat.
- Demo: `copy-demo-assets.mjs` already copies `resources/js` recursively, so the
  new modules ship automatically; verify the demo export menu shows both and a
  download works.

## Non-goals / caveats

- No `Enum` blocks in DBML v1 (enum stays a quoted type string).
- DBML native-type mapping is deliberately lossy; documented, not solved.
- No server round-trip, no new dependency, CSP-safe (`script-src 'self'`).
- Structure only; both serializers read the in-browser snapshot, never row data.

## Decision points for review

1. **Scope**: current selection (recommended, matches PNG/SVG) vs always full
   schema.
2. **Per-table Markdown**: add to the table menu too, or whole-schema only.
3. **DBML column settings**: include `[not null]` / `[default]` (recommended,
   defaults are structure) or keep minimal (types + keys + refs).
4. **File names**: `schema.md` / `schema.dbml` (recommended) vs including the
   connection name.

## Rollout order

1. Write Vitest specs for both modules (failing).
2. Implement `markdown-export.js`, then `dbml-export.js` until green.
3. Wire the two menu items + `runMenuAction` handlers.
4. Extend the Playwright menu spec (failing, then green).
5. Update README, website export docs, `docs/DESIGN.md`; rebuild the demo and
   verify.
6. `composer test` / `npm test` / `npx playwright test` all green; then commit.
