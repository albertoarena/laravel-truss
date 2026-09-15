# Changelog

All notable changes to `laravel-truss` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries say what changed and what it means for you. The reasoning behind a change
lives in its commit, and the decisions behind a feature in `docs/`.

## [Unreleased]

### Added

- The dashboard payload reports how many tables the exclusion list removed, as `excluded.count`. A count only, never the names, and always present even at zero. Without it there was nothing to tell a filtered diagram apart from one that had failed to see the missing tables.
- The footer says how much of the schema is on screen: `32 of 40 tables` when `excluded_tables` hides some, and a plain `32 tables` when it is drawing everything it knows about.
- A **Show hidden tables** toggle draws the tables `excluded_tables` hides, muted, and takes them away again. New config `reveal_excluded` (`TRUSS_REVEAL_EXCLUDED`) decides whether they reach the browser at all: on by default in local, off everywhere else, matching `enabled` and the `viewTruss` gate. Off, they never leave the server and only the count does, so hiding a table to keep it off a shared dashboard still works; there is deliberately no query parameter, so the decision stays the operator's and never the viewer's. The diff and the doctor always run on the filtered set, so a revealed table carries no change marks and no findings, which is why it is drawn muted.

### Fixed

- `truss:show` applies `excluded_tables` like every other surface. It printed the tables the diagram hides, while its own documentation said it filtered them, so the terminal and the dashboard disagreed about the same connection. It now prints `1 of 2 tables on <connection>` when config hid some, and says so plainly when a connection is excluded down to nothing.
- The footer no longer reports the whole schema while the diagram draws a narrowed view. Filtering or focusing now updates the count (`2 of 4 tables`), where before it was written once per schema load and never again.
- Native controls follow the chosen theme instead of the operating system. On a machine set to dark, forcing the dashboard to light left every unchecked checkbox painted black in a white toolbar, and the same mismatch reached selects, scrollbars and focus rings. `color-scheme` was declared once as `light dark`, which means "ask the OS", and that is right only while the theme is on auto.


## [1.12.0] - 2026-09-13

### Added

- `Truss::payload(?string $connection = null)` returns the array the dashboard runs on, in process and with no HTTP request: the exclusion-filtered snapshot plus the schema diff, the doctor report and the cache and baseline unavailability flags, none of which `Truss::snapshot()` carries. It runs no authorization, which belongs to whatever exposes the data.
- The diagram can run on a payload embedded in the page. Put one in a `<script type="application/json" data-truss-payload>` block inside the app container and no schema endpoint is requested or needed; filter, focus and type labels still work client-side. Truss's own dashboard is unchanged and keeps fetching.

### Fixed

- Laravel Boost discovery follows Boost v2.8.1 onto its Roster API. Nothing Truss ships changed and the integration was never broken in the field; only the contract test that proves it needed rewriting.

## [1.11.1] - 2026-09-02

### Fixed

- The details popover no longer clips its own content, which could hide the note explaining why the server-backed exports are unavailable.

## [1.11.0] - 2026-09-01

### Added

- `TRUSS-INT-010` finds a foreign key that points at the wrong table: a single-column `*_id` key referencing one table while a table named after the column exists and is a different one. Aliases like `parent_transaction_id` are not flagged, because no table carries that name; ambiguity reports nothing rather than guessing, and morph targets and composite keys are skipped. High confidence and an **error**, so it runs under the default `recommended` preset and can turn a `truss:doctor --fail-on=error` build red on an unchanged schema.

## [1.10.0] - 2026-08-27

### Added

- Truss ships Laravel Boost guidelines and a skill, so an agent set up through Boost reaches for your real schema instead of guessing at columns. Run `php artisan boost:install` and tick `albertoarena/laravel-truss (guidelines, skills)`; nothing third-party is selected by default. Additive, with no new dependencies. Turn either off from your own `config/boost.php`: `guidelines.exclude` takes `albertoarena/laravel-truss/truss`, `skills.exclude` takes `truss-schema`. Boost cannot register an MCP server for a package, so the MCP path stays independent.

### Changed

- `TRUSS-INT-007` no longer calls every table with two foreign keys a pivot: the table must also carry its key pair and little else. On twelve real applications it fired 69 times with 56 wrong, and now fires 14. Some true positives are lost on purpose. See `docs/adr/0003-pivot-detection.md`.
- **The default preset's output changed**, so `truss:doctor --fail-on=error` in CI may report differently on an unchanged schema. That is why this is a minor rather than a patch.
- The minimum PHP version is now **8.2**, down from 8.3, matching Laravel 12. Projects pinning `config.platform.php` to `8.2.0` could not install Truss before. See `docs/adr/0001-php-82-minimum.md`.

### Fixed

- Every diagram label lost its last character in Firefox on Windows. Labels were measured in the system fallback font and repainted in IBM Plex Mono, which is about 9 percent wider. The render now waits for the real font before measuring, drawing in the fallback after a short timeout on a slow connection and redrawing when it arrives. Reported by [@diboma](https://github.com/diboma) in [#59](https://github.com/albertoarena/laravel-truss/issues/59).
- `TRUSS-INT-002` asked for a foreign key on polymorphic columns, contradicting `TRUSS-INT-009` in the same report. Morph columns are now excluded.
- Truss no longer resolves the `Gate` contract while booting. An application binding no `Gate`, as October CMS does, could not run any artisan command with Truss installed, including `truss:doctor`. See `docs/adr/0002-defer-gate-registration.md`.
- The dashboard returns 404 rather than an error when the host application binds no `Gate`, so a route that cannot be authorised stays invisible.
- `TRUSS-INT-007` reported possible duplicate pairs on a table where one key already carried a single-column unique index, and recommended a redundant composite key. It now accepts a unique index over any part of the pair when those columns are `NOT NULL`. Reported by [@belabiedredouane](https://github.com/belabiedredouane).

## [1.9.1] - 2026-08-20

### Fixed

- Six export actions were offered by dashboards that cannot perform them, and choosing one failed silently: no file, no error. The structural formats are generated server-side, so a dashboard rendered without an export endpoint has nothing to ask. Unavailable items now render `aria-disabled` with the reason in words, keeping them in the tab order.
- PNG export produced nothing on a large diagram: past roughly 268 megapixels the browser returns a blank canvas. The scale is now fitted to what a canvas will rasterise, never below 1x, and refuses outright when even 1x will not fit, pointing at SVG or focusing a table instead.

## [1.9.0] - 2026-08-18

### Added

- The Focus picker is searchable, matching anywhere in the name with the matched span highlighted and the number of matches announced. It was a native dropdown, unusable at a couple of hundred tables. Reported by Alberto Peripolli (@trippo).
- The diagram is operable from the keyboard. Table names, enum type labels and health markers answer Enter and Space, Escape closes a menu and returns focus to whatever opened it, and each trigger has a visible focus ring.
- The rendered diagram names and describes itself for assistive technology (`accTitle` / `accDescr`), tracking the view you are looking at: table and relationship counts, the active filter, and the focused table with its depth.
- An accessibility check runs on every push: axe-core scans the dashboard and its overlays against WCAG 2.2 AA, as its own CI step. Run it locally with `npm run test:a11y`.

### Fixed

- The zoom slider had a tooltip but no accessible name. Nothing changes visually.

### Upgrading

- Nothing to do, unless you published the dashboard view with `vendor:publish --tag=truss-views`. Focus changed from a `<select>` to a combobox, and a published copy of the old view leaves it inert. Re-publish to pick it up.
- This closes the Level A keyboard failures found in the dashboard. It is not an audit of every success criterion and not a claim of WCAG conformance: https://trussphp.com/guides/accessibility/.

## [1.8.4] - 2026-08-17

### Fixed

- A configured but unusable cache store could fail `php artisan migrate` and take the dashboard down with a 500. Truss now treats an unreachable cache the way it treats an unreachable database: the structure is read live and not cached, the dashboard says why, and the migration listener never throws. The commands print a notice and keep working. `truss:rebuild` is the deliberate exception and still exits non-zero, since storing the snapshot is its only job. Reported by @HafizMMoaz.

## [1.8.3] - 2026-08-12

### Fixed

- On a connection with a table prefix, every table rendered as an empty block with no relationship lines, and native column comments vanished from `truss:export` annotations. Laravel reports prefixed names but prepends the prefix again to names passed back in, so introspection was asking for `portal_portal_users`. The prefix is now suspended for the duration of a snapshot. Reported by @locshino.
- Export annotations could pick up comments from another database on the same server, since the listing was unscoped. It is now scoped to the connection's own schema.

## [1.8.2] - 2026-08-12

### Fixed

- A clean install could return a 500 from the schema endpoint on any app whose default filesystem disk is remote (for example `FILESYSTEM_DISK=s3`). **The diff baseline disk now defaults to `local`** rather than following the application, every baseline operation degrades instead of throwing, and the failure is explained in the dashboard and in `truss:diff` rather than being silent.
- The dark border around a table was drawn on three sides only, leaving the body outlined in the pale hairline colour. Reported by Alberto Peripolli (@trippo).

### Changed

- A custom theme no longer flattens the table outline and the row separators into one colour: the row hairline is derived as a translucent tint of `border`. **Existing custom themes will show lighter row separators than before.** A border given as `rgb()`, `hsl()` or a colour keyword is unchanged, since tinting needs the colour channels.

## [1.8.1] - 2026-08-11

### Fixed

- Filtering while a table was focused emptied the diagram with no explanation, since the two selections compose. The empty state now names both and offers **Clear focus** when dropping the focus would show something. Thanks to @trippo for reporting it, after spotting it in @PovilasKorop's Laravel Daily video.

### Changed

- Dashboard copy no longer uses em dashes. Wording only.

## [1.8.0] - 2026-08-10

### Added

- Truss as AI context: the export doubles as grounding context for a coding agent.
  - **Annotations**: declare business meaning a type cannot express under `truss.annotations`, or read it from native database comments by keeping `'database'` in `annotations.source`. Rendered into every text format, stripped with `--no-annotations`. Structure only: a comment is part of the `CREATE TABLE` definition.
  - **`--compact`**: drop column defaults and non-unique indexes without losing any table, column or foreign key.
  - **`--focus=<table>` / `--depth=<n>`**: reduce the export to a table and its foreign-key neighbourhood.
  - **`llm` format**: a dense, token-trimmed plaintext format for feeding an agent.
  - **`truss.export.default_format`** for the format used when none is given.
- A gated `GET {prefix}/export/{format}` route serving the same export as the command and facade, so the dashboard download and any HTTP client share one pipeline.
- An optional read-only MCP server exposing the live schema to a coding agent over local stdio, built on `laravel/mcp`. Tools `list_tables`, `describe_table`, `get_schema`, `focus_table` and `get_structural_review`, plus a `truss://schema` resource. Opt in with `composer require laravel/mcp` and `php artisan mcp:start truss`; toggle with `truss.mcp.enabled`. Every tool advertises `readOnlyHint`. No row data, and the same exclusion and managed-connection safeguards as the rest of Truss.
- A fluent, immutable facade for building exports: `Truss::snapshot()->focus('orders', depth: 1)->compact()->toDbml()`, with `only()`, `except()`, `focus()`, `compact()`, `withoutAnnotations()`, `fresh()` and `connection()`.

### Changed

- The dashboard generates structural downloads (DBML, Markdown, JSON, CSV) server-side through the gated export route instead of duplicating the generators in JavaScript, so all three surfaces produce identical output. PNG and SVG are still rendered in the browser.
- CI runs the test suite against MySQL and Postgres containers in addition to SQLite.

## [1.7.0] - 2026-08-05

### Added

- A `SECURITY.md` policy documenting a private disclosure channel and the reporting scope.

### Changed

- The frontend test suites (Vitest and Playwright) now run in CI on every push and pull request.
- The development test runner (Vitest) was upgraded to 4.x. Test tooling only.

### Security

- Every GitHub Actions reference is pinned to a full commit SHA, so a moved or compromised tag cannot redirect a workflow.
- Added Dependabot for GitHub Actions, Composer and npm, weekly with a seven-day cooldown, so the pinned SHAs stay current.

## [1.6.1] - 2026-08-03

### Fixed

- Theming now re-skins the whole diagram, not just the tables. A custom `truss.theme` palette left the relationship lines and labels, the background grid and the dark-mode rows on the shipped Blueprint colours.

## [1.6.0] - 2026-08-03

### Added

- Theming and custom palettes under `truss.theme`: semantic knobs (`accent`, `background`, `surface`, `text`, `border` and more) plus two font families re-skin the whole dashboard in both light and dark. Only the knobs you set are overridden. Delivered as a same-origin stylesheet, so a strict CSP still needs only `style-src 'self'`, with no build step. Invalid values fall back to the default rather than breaking the sheet.
- `php artisan truss:export` writes the structure to DBML, JSON, CSV, a Markdown data dictionary or Mermaid. Writes to stdout or a file with `--output`, filters with `--tables` / `--exclude` (config `excluded_tables` always wins), targets a connection with `--connection`, rebuilds with `--fresh`. Output is deterministic, so `--check` fails the build when a committed export has gone stale. Exit codes: `0` written or up to date, `1` drift, `2` usage or runtime error.

### Removed

- The unused `truss.diagram.theme` config key, which was wired to nothing. Theme selection lives under `truss.theme`.

### Fixed

- The dashboard toolbar no longer overflows the viewport on a small desktop. Secondary controls fold into the more menu below 1024px, the selects are width capped, and the search field can shrink.
- On a phone the legend opens as a top-right dropdown, matching the changes and health panels, instead of a full-width bottom sheet.

## [1.5.0] - 2026-07-30

### Added

- Schema doctor: `php artisan truss:doctor` (aliased `truss:check`) reviews the structure for problems visible from structure alone, such as a table with no primary key, an unindexed foreign key, duplicate indexes, a foreign key type mismatch or money stored as a float. Thirteen rules across integrity, index and type categories, with presets (recommended, strict, none), per-rule and per-category config, ignore patterns, console and JSON output and CI exit codes. Deterministic and structure only, with no AI and no network call. Configured under `truss.doctor`.
- Schema doctor in the dashboard: a Health panel lists findings grouped by table, badges affected tables by worst severity, marks heuristic findings, marks the offending column on the diagram and focuses a table when you click it. Flagging is toggled with `truss.doctor.flag_tables`, the panel with `truss.doctor.dashboard`.

## [1.4.2] - 2026-07-29

### Changed

- The connection switcher label reads "Connections" instead of "Conn".
- The legend overlay anchors to the dashboard container rather than the viewport, so it stays placed when the dashboard is embedded below other page chrome.

## [1.4.1] - 2026-07-29

### Changed

- Toolbar and overlay labels read in sentence case instead of all caps, matching the documentation site. Diff badges read "Added" / "Removed" / "Changed". Visual only.

## [1.4.0] - 2026-07-29

### Added

- Schema diff: after each migration Truss records the previous schema as a baseline and compares it against the current one. The dashboard gains a Changes panel listing every added, removed or changed table, column, index and foreign key, and `php artisan truss:diff` prints the same in the terminal. The baseline is a structure-only JSON file, the only thing Truss writes to disk, at `truss/baselines/{connection}.json` on the disk set by `truss.diff.disk`. Set `TRUSS_DIFF_ENABLED=false` to write nothing at all.

## [1.3.2] - 2026-07-29

### Changed

- The toolbar shows the lowercase `truss` wordmark in IBM Plex Mono, matching the documentation site. Visual only.

## [1.3.1] - 2026-07-28

### Fixed

- Introspection is now scoped to the connection's own database. On a server hosting more than one, `truss:show`, `truss:rebuild` and the diagram listed every reachable database's tables, which was slow and could collapse same-named tables into each other. The current schema now resolves per driver: the database name on MySQL, the search-path schema on PostgreSQL, `main` on SQLite. Thanks to @santos-sabanari for the diagnosis and @m0shiurX for the fix.

## [1.3.0] - 2026-07-27

### Added

- Data dictionary and DBML exports. The export button saves the current selection as a Markdown data dictionary or a DBML file that opens in [dbdiagram.io](https://dbdiagram.io), and the per-table menu gains Download Markdown. Generated in the browser, structure only. DBML relationships are included only when both tables are in view, and the native type mapping is best-effort.

## [1.2.0] - 2026-07-24

### Changed

- Self-referential foreign keys (such as `parent_id` on `categories`) are marked with a `self-ref` note on the column instead of a looping relationship line, which Mermaid drew as a large sweeping curve. Ordinary relationships are unaffected.

## [1.1.0] - 2026-07-23

### Added

- `truss:show`: print the database structure as a terminal table (table, column count, foreign-key count). Structure only.
- `truss:open`: open the dashboard in the default browser, honouring the configured route prefix and app URL.

## [1.0.0] - 2026-07-23

First stable release. The API, config and authorization model are considered stable and will follow semantic versioning from here.

### Added

- Diagram image export: save the current selection as a PNG or SVG. Fully client-side and CSP-safe, with labels flattened to SVG text and the font embedded, so the output is theme-matched anywhere.

## [0.3.0] - 2026-07-23

### Added

- Per-table menu: click a table name to focus or unfocus it, copy its structure as JSON, or download it as JSON or CSV. Generated in the browser, structure only.

## [0.2.0] - 2026-07-23

### Added

- Deep-linkable views: connection, filter, focus, depth and type-label mode are reflected in the URL query string (for example `/truss?focus=projects`) and seed the initial view on load, so a view can be bookmarked and shared.

## [0.1.0] - 2026-07-23

### Added

- Introspection layer: composite-first value objects (`Table`, `Column`, `Index`, `ForeignKey`), a `SchemaSerializer`, and a `SnapshotBuilder` reading the live connection via Laravel's native schema introspection, with an in-memory SQLite replay fallback.
- Caching: a per-connection `SchemaCacheRepository` respecting `cache.ttl`, a listener that rebuilds after migrations, and `truss:rebuild`.
- HTTP layer: the dashboard page and a JSON schema endpoint behind the fixed `viewTruss` gate, with a production-gated authorization model (an email allow-list default gate, overridable per app), configurable auth-context middleware, and 404 on denial.
- Frontend: a client-side ER diagram rendered with Mermaid, with focus mode, text filter, native/Laravel type labels and clickable `enum`/`set` value popovers.
- Map-style pan and zoom (drag, wheel, pinch) with a readable auto-fit floor and a Fit button.
- A light and dark "blueprint" theme, a Node-triad brand mark, and a self-hosted, CDN-free asset pipeline (vendored Mermaid and IBM Plex Mono served from a gated package route).
- Documentation site built with Astro and Starlight.
