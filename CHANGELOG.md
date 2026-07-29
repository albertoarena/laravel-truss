# Changelog

All notable changes to `laravel-truss` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Schema diff: see what changed since your last migration. After each migration Truss records the previous schema as a baseline and compares it against the current one. The dashboard gains a "Changes" panel that tints added and changed tables and lists every added, removed, or changed table, column, index, and foreign key, and a new `php artisan truss:diff` command prints the same diff in the terminal (handy in CI). Structure only, never row data. The baseline is a structure-only JSON file, the only thing Truss writes to disk, stored at `truss/baselines/{connection}.json` on the disk set by `truss.diff.disk`. Set `TRUSS_DIFF_ENABLED=false` to turn the feature off entirely so nothing is written to disk.

## [1.3.2] - 2026-07-29

### Changed

- The dashboard toolbar now shows the lowercase `truss` wordmark in IBM Plex Mono, matching the documentation site and the refreshed brand. Visual only, no behaviour change.

## [1.3.1] - 2026-07-28

### Fixed

- Schema introspection is now scoped to the connection's own database. On a server that hosts more than one database (a shared local MySQL, a PostgreSQL cluster), `truss:show`, `truss:rebuild`, and the diagram listed the tables of every database the connection could reach rather than just the application's own, which also made the snapshot build far slower and could collapse same-named tables from different databases into each other. The listing now resolves the current schema per driver: the database name on MySQL, the search-path schema on PostgreSQL, and `main` on SQLite. Structure only, as always. Thanks to @santos-sabanari for the thorough diagnosis and @m0shiurX for the fix.

## [1.3.0] - 2026-07-27

### Added

- Data dictionary and DBML exports. The diagram export button now also saves the current selection as a Markdown data dictionary (one section per table, with columns, keys, indexes, and foreign keys, ready to paste into a README or wiki) or as a DBML file that opens in [dbdiagram.io](https://dbdiagram.io) and other DBML tools. The per-table menu gains a Download Markdown option. Both are generated in the browser and contain structure only, never row data. DBML relationships are included only when both tables are in the current view, and the native type mapping is best-effort (types are passed through, quoted when needed).

## [1.2.0] - 2026-07-24

### Changed

- Self-referential foreign keys (a column pointing back at its own table, such as `parent_id` on `categories`) are now marked with a `self-ref` note on the column instead of a looping relationship line, which Mermaid drew as a large sweeping curve. Keeps the diagram tidy while the hierarchy stays visible on the row. Ordinary relationships are unaffected.

## [1.1.0] - 2026-07-23

### Added

- `truss:show` Artisan command: print the database structure as a terminal table (table, column count, foreign-key count), the text counterpart to the visual dashboard. Structure only.
- `truss:open` Artisan command: open the dashboard in the default browser, honouring the configured route prefix and app URL.

## [1.0.0] - 2026-07-23

First stable release. The API, config, and authorization model are considered stable and will follow semantic versioning from here.

### Added

- Diagram image export: an export button saves the whole current diagram (the current filter/focus selection) as a PNG or SVG. Fully client-side and dependency-free (no CDN, CSP-safe): labels are flattened to SVG text and the font is embedded, so the output is theme-matched and correct anywhere. Structure only.

## [0.3.0] - 2026-07-23

### Added

- Per-table export/focus menu: click a table name in the diagram to focus (or unfocus) it, copy its structure as JSON, or download its structure as JSON or CSV. Exports are generated in the browser and contain structure only (columns, keys, indexes), never row data.

## [0.2.0] - 2026-07-23

### Added

- Deep-linkable views: the current connection, filter, focus, depth, and type-label mode are reflected in the URL query string (for example `/truss?focus=projects`), updated live as you interact. On load the query string seeds the initial view, so a focused or filtered view can be bookmarked, shared, and reopened.

## [0.1.0] - 2026-07-23

### Added

- Introspection layer: composite-first value objects (`Table`, `Column`, `Index`, `ForeignKey`), a `SchemaSerializer`, and a `SnapshotBuilder` that reads the live connection via Laravel's native schema introspection, with an in-memory SQLite replay fallback when no connection is reachable.
- Caching: a per-connection `SchemaCacheRepository` respecting `cache.ttl`, a listener that rebuilds after migrations, and a `truss:rebuild` Artisan command.
- HTTP layer: the dashboard page and a JSON schema endpoint behind the fixed `viewTruss` gate, with a production-gated authorization model (an email allow-list default gate, overridable per app), configurable auth-context middleware, and 404 on denial.
- Frontend: a client-side ER diagram rendered with Mermaid, with focus mode (a table and its foreign-key neighbours, centred and highlighted), text filter, native/Laravel type labels, and clickable `enum`/`set` value popovers.
- Map-style pan and zoom (drag, wheel, pinch) with a readable auto-fit floor and a Fit button.
- A light and dark "blueprint" theme, a Node-triad brand mark, and a self-hosted, CDN-free asset pipeline (vendored Mermaid and IBM Plex Mono served from a gated package route).
- Documentation site built with Astro and Starlight.
