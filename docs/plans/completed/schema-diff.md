# Plan: Schema diff ("what changed since last migration")

Status: implemented on branch feature/schema-diff (pending review)
Owner: Alberto Arena
Related: enhancement roadmap item (1); `docs/DESIGN.md` (Cache layer); `src/Cache/SchemaCacheRepository.php`; `src/Listeners/RebuildOnMigrationsEnded.php`

## Goal

Compare the current schema snapshot against the schema as it was before the last
migration run and surface what changed: tables, columns, indexes, and foreign
keys added, removed, or changed. Two surfaces:

1. A dashboard **"Changes" toggle** that tints changed tables/columns and lists
   the added/removed/changed items.
2. A **`php artisan truss:diff`** command that prints the structural diff in the
   terminal, mirroring `truss:show`.

Structure only, never row data. The diff is a pure function over two serialized
snapshots.

## Decisions locked (from the planning conversation, 2026-07-29)

1. **Diff engine: PHP.** A pure `SchemaDiffer` compares two snapshot arrays,
   Pest-tested. This keeps the browser payload small (server sends the computed
   diff, not two full snapshots) and makes the `truss:diff` CLI a thin wrapper
   over the same engine.
2. **Baseline storage: durable file on a configurable disk.** The baseline is
   the one piece of state Truss cannot rebuild on demand (you cannot reconstruct
   the pre-migration schema from the live database), so it must not live in the
   volatile cache. It is written as a structure-only JSON file on a Laravel
   filesystem disk. Still no DB table, no `vendor:publish`, no data exposure.
3. **Surfaces: both** the dashboard toggle and the CLI command.

## Background (grounded in the code)

- `SchemaCacheRepository` (`src/Cache/SchemaCacheRepository.php`) reads/writes the
  current snapshot per connection via the `Cache` facade. `rebuild()` overwrites
  the cache key with a fresh snapshot; `get()` builds on a miss. It stamps
  `generated_at` in `build()`.
- `RebuildOnMigrationsEnded` (`src/Listeners/RebuildOnMigrationsEnded.php`) calls
  `rebuild()` for the affected connection(s) on `MigrationsEnded` (fired by
  migrate, migrate:rollback, migrate:fresh). This is the one place that knows a
  migration just ran, so it is where the baseline is captured.
- `SchemaSerializer` (`src/Introspection/SchemaSerializer.php`) defines the wire
  shape the differ consumes (see below). The introspection layer stays pure and
  must not learn about diffing (`.claude/rules/introspection.md`).
- `ShowCommand` (`src/Commands/ShowCommand.php`) is the template for `truss:diff`:
  read the snapshot from the cache repository, print a table, honour
  `--connection`.
- Config lives in `config/truss.php`, the single source of truth. It already has
  a `cache` block and a `connections` block to model a new `diff` block on.

## Snapshot shape (differ input)

Each snapshot is the envelope `{ connection, generated_at, fallback?, tables: [...] }`.
Each table (per `SchemaSerializer`):

```
{ name,
  columns: [{ name, type, nullable, default }],
  primary_key: [..],
  indexes: [{ name, columns:[..], unique }],
  foreign_keys: [{ name, columns:[..], references_table, references_columns:[..],
                  on_update, on_delete }] }
```

Types are the native full type (`varchar(255)`, `bigint unsigned`). Keys are
composite-first (arrays), so the differ must compare arrays, not scalars.

## Architecture

### New layer: `src/Diff/` (pure PHP, framework-light)

Kept out of `src/Introspection/` on purpose: that layer takes a connection in and
returns a schema out, and its rules forbid it from knowing about anything else.
The differ is a different concern (two serialized arrays in, a diff out) and the
baseline store touches the filesystem, so both live in a new namespace.

**`AlbertoArena\Truss\Diff\SchemaDiffer`** (pure, no framework, no I/O)

```
public function diff(array $baseline, array $current): array
```

Returns a plain, ordered array shaped for both terminal rendering and JSON:

```
{
  tables_added:   [ { name }, ... ],
  tables_removed: [ { name }, ... ],
  tables_changed: [
    { name,
      columns_added:   [ { name, type, nullable, default } ],
      columns_removed: [ { name } ],
      columns_changed: [ { name, changes: { type?: {before, after},
                                            nullable?: {before, after},
                                            default?: {before, after} } } ],
      indexes_added:   [ { name, columns, unique } ],
      indexes_removed: [ { name } ],
      indexes_changed: [ { name, changes: { columns?: {..}, unique?: {..} } } ],
      foreign_keys_added:   [ { ...full FK definition } ],
      foreign_keys_removed: [ { name } ],
      foreign_keys_changed: [ { name, changes: { ... } } ],
      changes: { primary_key?: { before, after } },   // table-level changes
    }
  ],
  has_changes: bool,          // false when every bucket is empty
  baseline_generated_at,      // echo the two envelope timestamps
  current_generated_at,
}
```

Added items (columns, indexes, foreign keys) carry their **full serialized
definition** so the panel/CLI can show details without cross-referencing; removed
items carry just `{ name }`; changed items carry `{ name, changes }` with
before/after only for the fields that moved. A primary-key change is a table-level
`changes.primary_key` entry (a table can be "changed" on its PK alone).

Matching rules:
- Tables, columns, indexes, foreign keys are matched **by name**. A rename reads
  as a remove plus an add (documented limitation, same spirit as the lossy DBML
  caveat).
- A column is "changed" when `type`, `nullable`, or `default` differ. Composite
  arrays (index/FK/PK columns) compare by value and order.
- A table appears in `tables_changed` only when at least one of its buckets is
  non-empty; identical tables produce nothing.
- Empty baseline (no prior snapshot) is a valid input: everything reads as added,
  but see "capture" below, that case is normally avoided because the first
  migration establishes the baseline before there is anything to diff.

**`AlbertoArena\Truss\Diff\BaselineStore`** (structure-only file persistence)

```
public function save(string $connection, array $snapshot): void
public function get(string $connection): ?array   // null when absent
public function has(string $connection): bool
public function forget(string $connection): void
```

- Persists via `Storage::disk(config('truss.diff.disk'))` to a fixed path
  `truss/baselines/{connection}.json`. When `truss.diff.disk` is null it uses the
  app's default filesystem disk.
- Writes the snapshot as-is (already structure-only); the file is derived data,
  gitignore-friendly, and safe to delete (diff just goes empty until the next
  migration re-seeds it).
- Connection name is slugified for the filename to stay filesystem-safe.

**The durable file, documented for users.** Because this is the first thing Truss
writes to the host's filesystem (everything else is cache-backed), it must be
called out plainly in the user docs, not buried:

- **What it is:** a structure-only JSON copy of the pre-migration schema snapshot,
  the exact same shape already cached. No row data, by construction.
- **Where it lives:** `truss/baselines/{connection}.json` on the configured disk
  (default disk otherwise). Example concrete path on the local disk:
  `storage/app/truss/baselines/mysql.json`.
- **When it is written:** only on `MigrationsEnded`, and only when
  `truss.diff.enabled` is true. Nothing is written on page loads, API calls, or
  ordinary cache misses.
- **How to opt out:** set `truss.diff.enabled` to false (see config below), and
  Truss writes nothing to disk. Deleting an existing file is safe at any time.
- **Version control:** the file is derived and machine-specific; docs recommend
  gitignoring `storage/app/truss/` (or the chosen disk path) the same way
  `storage/` is already ignored in a standard Laravel app.

### Capture wiring (`RebuildOnMigrationsEnded`)

`MigrationsEnded` fires **after** the migration has run, so the live database is
already in the new state. The only source of the pre-migration schema is the
snapshot already sitting in the cache. So, per affected connection, in order:

1. Read the **currently cached** snapshot without triggering a rebuild
   (`SchemaCacheRepository::has()` + a non-building read). If present, hand it to
   `BaselineStore::save()` as the new baseline.
2. Then call `rebuild()` as today to write the fresh current snapshot.

If no snapshot was cached (fresh install, cache cleared just before the
migration), no baseline is written this round. The baseline advances only when a
current snapshot existed beforehand. This is the honest behaviour: we never
fabricate a pre-migration state we did not capture.

Add a `SchemaCacheRepository::peek(?string $connection): ?array` (read the cached
value without building on miss) so the listener does not have to touch the `Cache`
facade directly and the "read only what is cached" intent is explicit.

### Dashboard API

The dashboard already loads the current snapshot once, so the diff is **embedded
in that same response** (no second round-trip, decided):

- The schema API controller computes `SchemaDiffer::diff(baseline, current)` when
  a baseline exists and includes it as a `diff` key (or `diff: null` when there is
  no baseline, or when `truss.diff.enabled` is false). Excluded tables are already
  filtered out of both snapshots by the time they reach here, so the diff is
  automatically exclusion-consistent.
- Alternative considered and rejected for v1: a separate `/api/diff` endpoint hit
  lazily when the toggle flips. Embedding is simpler and the payload is small
  (structure-only). Note it as a future option if payload size ever matters.

### Frontend

- **Render mapping is a pure module** `resources/js/diff-view.js` (Vitest), taking
  the `diff` object and returning, for a given table, its change status
  (`added` | `removed` | `changed` | `unchanged`) and the per-column badges, plus
  a flat list model for the "Changes" panel. No DOM in this module, mirroring the
  export modules.
- **Wiring in `resources/js/truss.js`**: a "Changes" toggle in the toolbar. When
  on, apply a tint class to changed and added tables and open a panel listing the
  changes. **Removed tables are list-only** in the panel for v1: they no longer
  exist in the current snapshot, so ghosting them would mean injecting phantom
  nodes and edges into the generated Mermaid source and fighting the layout
  engine (deferred as a possible later enhancement). As built, the toggle button
  is simply **hidden whenever the API returns `diff: null`** (feature off, or no
  baseline yet). The API cannot distinguish "disabled" from "no baseline", and
  both mean "nothing to show", so hiding is cleaner than the earlier plan's
  disabled-with-"No baseline yet"-hint state, which was dropped.
- Respect the existing zoom-crispness rule: never add `will-change: transform` to
  `#truss-canvas`; tinting uses colour/opacity classes only.

## Config additions (`config/truss.php`)

A new `diff` block, documented in the file's comment style:

```
'diff' => [
    // Master switch for the schema-diff feature. When false, Truss writes
    // nothing to disk: no baseline is captured on migration, the dashboard
    // "Changes" toggle is hidden, and `truss:diff` reports the feature is off.
    // Set this to false if you do not want the package storing files on your
    // filesystem. Everything else about Truss stays purely cache-backed.
    'enabled' => env('TRUSS_DIFF_ENABLED', true),

    // Filesystem disk the pre-migration baseline is written to. null = the
    // app's default disk. The baseline is a structure-only, derived JSON file
    // stored on disk (not in the cache) because it is the one piece of state
    // Truss cannot rebuild from the live database. It is safe to delete.
    'disk' => env('TRUSS_DIFF_DISK', null),
],
```

**Opt-out is a hard requirement.** Some hosts do not want a package writing to
their disk at all. `truss.diff.enabled = false` is the switch that guarantees it:

- `RebuildOnMigrationsEnded` skips baseline capture entirely (no file ever
  written), the same way it already skips rebuilds when `truss.enabled` is false.
- `BaselineStore` is never invoked; the schema API returns `diff: null`.
- The dashboard hides the "Changes" toggle (not just disables it), and
  `truss:diff` prints a short "schema diff is disabled (truss.diff.enabled)"
  message and returns success.

This is checked before any filesystem touch, so a host that sets it false can be
certain Truss leaves their disk untouched.

## TDD test plan (write failing tests first)

### Pest (PHP)

Concrete paths (matching the existing layout): differ in `tests/Unit/Diff/`
(mirrors `tests/Unit/Introspection/`); baseline store, listener, command, and API
under `tests/Feature/`. The `src/Diff/` namespace autoloads via the existing
`AlbertoArena\Truss\` PSR-4 root, so no `composer.json` change is needed.

`tests/Unit/Diff/SchemaDifferTest.php` (pure array fixtures, no DB needed here,
the differ operates on already-serialized snapshots):
- identical snapshots -> `has_changes` false, all buckets empty.
- table added / table removed.
- column added / removed within a table.
- column changed: type change, nullable change, default change (each isolated and
  combined; `changes` carries before/after only for the fields that moved).
- index added / removed / changed (columns and unique flag), composite index.
- foreign key added / removed / changed, composite FK.
- a table with only unchanged content does not appear in `tables_changed`.
- empty baseline vs populated current -> all tables added.

`tests/Feature/Diff/BaselineStoreTest.php` (`Storage::fake`):
- save then get round-trips the snapshot unchanged.
- get returns null when absent; has reflects presence; forget removes it.
- two connections are stored independently; connection name is slugified safely.
- honours `truss.diff.disk`.

`tests/Feature/Cache/RebuildTriggerTest.php` (extend the existing listener
coverage; `SchemaCacheRepositoryTest.php` covers the new `peek()`; real DB per the
introspection rule where a snapshot is built):
- with a snapshot already cached, a migration saves the prior snapshot as the
  baseline, then rebuilds current; the baseline equals the pre-migration snapshot.
- with nothing cached, no baseline is written.
- disabled (`truss.enabled` false) does neither, matching current behaviour.
- **`truss.diff.enabled` false writes no file** even when a snapshot is cached and
  a migration runs (the opt-out guarantee); the rebuild still happens.

`tests/Feature/Commands/DiffCommandTest.php`:
- prints added / removed / changed sections from a known baseline+current pair.
- "no baseline yet" path prints a clear message and returns success.
- honours `--connection`.

API test (`tests/Feature/Http/SchemaApiTest.php`, extend the schema endpoint test):
- response includes `diff: null` when no baseline exists.
- response includes a populated `diff` when a baseline exists, and it reflects a
  known structural change.

### Vitest (pure client logic)

`tests/js/diff-view.test.js`:
- table status derivation (added/removed/changed/unchanged) from a diff fixture.
- per-column badge derivation (added/removed/changed).
- the flat "Changes" list model: correct counts and ordering.
- `diff: null` yields an empty/disabled model.

### Playwright (rendering / interaction)

`tests/e2e/truss.spec.js` (extend, with a fixture snapshot that carries a diff):
- the "Changes" toggle is present.
- toggling it on tints the changed table(s) and opens the panel listing the
  changes.
- with no baseline, the toggle is disabled and shows the hint.

## Docs to update in the same change (docs-in-sync rule)

- `README.md`: features list gains "schema diff" and the `truss:diff` command;
  commands section documents `php artisan truss:diff`. A short "Storage" note
  documents the durable baseline file (what/where/opt-out), since it is the only
  thing Truss writes to disk.
- `docs/DESIGN.md`: new `src/Diff/` layer (differ + baseline store), the
  capture-on-`MigrationsEnded` flow, the `diff` API field, and the new config
  block.
- `docs/DECISIONS.md`: record three decisions, (a) the baseline lives on disk, not
  in the cache, because it is the only non-reconstructible state (the
  reconstructability asymmetry), (b) the baseline is captured only on
  `MigrationsEnded` from the previously cached snapshot, so a diff reflects
  exactly the last migration and never a TTL expiry, and (c) because this is the
  package's first on-disk write, it is opt-out via `truss.diff.enabled` with a
  guarantee that false means nothing is written.
- `config/truss.php`: the `diff` block with the explanatory comment.
- Docs website (`albertoarena/laravel-truss-docs`, separate repo): feature page +
  command reference, lands with the next release.
- Demo: the live demo lives in the separate, release-gated docs repo
  (`albertoarena/laravel-truss-docs`), not in this package, so the **canned diff
  sample** for the demo is a follow-up there once this ships in a release. The
  demo has no migration event, so it needs a mocked `diff` in its sample payload
  to show the "Changes" toggle. Tracked as a docs-repo task, not part of this
  package change.

## Non-goals / caveats

- **Renames read as remove + add.** No heuristic rename detection in v1.
- **Single baseline per connection.** No history, no multi-step diffs. The
  baseline is always "immediately before the last captured migration".
- **Best-effort baseline.** Deleting the baseline file (or a fresh install before
  any migration) yields an empty diff until the next migration re-seeds it. This
  is far more durable than a cache key but still not an audit log.
- **Structure only.** Both snapshots are already structure-only; the diff cannot
  expose row data by construction.

## Decisions (all resolved during planning, 2026-07-29)

- **Diff engine:** pure PHP `SchemaDiffer`, one engine feeding both surfaces.
- **Baseline storage:** durable structure-only file on a configurable disk.
- **Opt-out:** `truss.diff.enabled` (default true) guarantees no disk writes when
  false; a hard requirement, not an option.
- **Surfaces:** dashboard "Changes" toggle + `php artisan truss:diff`.
- **API delivery:** embed `diff` in the existing schema response (no second
  round-trip).
- **Removed tables:** list-only in the "Changes" panel for v1; ghost nodes in the
  diagram deferred (they would fight the Mermaid layout).
- **Demo:** ship a canned diff sample in the demo fixture so the toggle demos.

No open decision points remain; the plan is ready to build.

## Rollout order

1. Pest specs for `SchemaDiffer` (failing), then implement until green.
2. Pest specs for `BaselineStore` (failing), then implement.
3. Wire capture into `RebuildOnMigrationsEnded` (+ `SchemaCacheRepository::peek`),
   listener test first.
4. `truss:diff` command, test first.
5. API: embed `diff`, endpoint test first.
6. Vitest for `diff-view.js` (failing), then implement.
7. Wire the toolbar "Changes" toggle + panel; extend the Playwright spec.
8. Config block; update README, `docs/DESIGN.md`, `docs/DECISIONS.md`; rebuild and
   verify the demo.
9. `composer test` / `composer lint` / `npm test` / `npx playwright test` all
   green, then commit.
