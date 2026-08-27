# Changelog

All notable changes to `laravel-truss` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.10.0] - 2026-08-27

### Added

- Truss now ships Laravel Boost guidelines and a skill inside the package, so an agent set up through Boost knows Truss is installed and reaches for your real schema instead of guessing at columns. Run `php artisan boost:install` and tick `albertoarena/laravel-truss (guidelines, skills)` in the third-party list; nothing third-party is selected by default, so it stays your call, and Boost remembers it for later `boost:update` runs. The guideline is short and always in context: what Truss is, the commands that ground a task in the real structure, and the boundary. The skill is longer and loads only when a task is actually about the database: read the structure, check it with `truss:doctor`, make the change, confirm it with `truss:diff`. This is additive and adds nothing to your dependencies. Boost becomes a third way in alongside the MCP server, which stays the richer surface, and `truss:export` for CI and any CLI-capable agent. Boost has no mechanism for a package to register an MCP server, so it does not install the Truss MCP server for you, and the two paths stay independent. Turn either off from your own `config/boost.php`: `guidelines.exclude` takes `albertoarena/laravel-truss/truss`, `skills.exclude` takes `truss-schema`. Prompted by the Laravel Daily video review of Truss, which made the case that Boost is where many developers now expect an agent to get its Laravel context.

### Changed

- `TRUSS-INT-007` no longer calls every table with two foreign keys a pivot. Two single-column foreign keys is now necessary but not sufficient: the table must also carry its key pair and little else. Pointed at the twelve real Laravel applications that could run both versions, the old rule fired 69 times and 56 of those were wrong, which was 47% of everything the default `recommended` preset reported. One of them advised a unique constraint that would have limited a user to a single budget. It now fires 14 times on the same schemas, which are also the schemas the new rule was tuned on. **Some true positives are lost on purpose**: a genuine pivot carrying two payload columns escapes, because a missed finding costs you nothing and a wrong one costs you a destructive migration. See `docs/adr/0003-pivot-detection.md`.
- **This changes the default preset's output**, so a CI job running `truss:doctor --fail-on=error` may see a different result on an unchanged schema. That is why this is a minor release rather than a patch.
- The minimum PHP version is now **8.2**, down from 8.3, which is what Laravel 12 itself requires. Truss previously excluded projects that pin `config.platform.php` to `8.2.0`, which is careful practice rather than a mistake, and BookStack could not install Truss for exactly that reason. See `docs/adr/0001-php-82-minimum.md`.

### Fixed

- Every label in the diagram lost its last character in Firefox on Windows: table names, column names and column types alike, cut in proportion to their length. Reported by [@diboma](https://github.com/diboma) in [#59](https://github.com/albertoarena/laravel-truss/issues/59). Mermaid sizes each label box to the text it measures and leaves no slack at all (measured: about 0.002px), so the box is only ever correct for the face it was measured in. The diagram was rendered without waiting for IBM Plex Mono, so with `font-display: swap` the labels could be measured in the system fallback and then repainted in the real face. Where that fallback is metric-compatible nothing shows, which is why macOS, Linux and CI never saw it and why Chrome on the same Windows machine was fine: it won the race. Windows falls back to Consolas at roughly 0.55em against IBM Plex Mono's 0.60em, so every label painted about 9 percent wider than its box. Measured on the reporter's machine at 78 of 78 labels clipped, median ratio 1.0904, against 1.0909 predicted from the two advance widths. The render now waits for the two weights the diagram actually paints before measuring, and the Blade shell preloads the face so that wait is usually over before it starts. A slow connection is not made to stare at a blank canvas: the diagram is drawn in the fallback after a short timeout and redrawn once the real face arrives. The label-geometry spec now runs in Firefox as well as Chromium, since no other engine reproduced this.
- `TRUSS-INT-002` asked for a foreign key on polymorphic columns, contradicting `TRUSS-INT-009` inside the same report. Morph columns are now excluded.
- Truss no longer resolves the `Gate` contract while booting. An application that ships its own authentication and binds no `Gate`, as October CMS does, could not run **any** artisan command with Truss installed, including `truss:doctor`, which never consults the gate and is documented as safe for CI. The gate is now defined when a request needs it. See `docs/adr/0002-defer-gate-registration.md`.
- The dashboard returns 404 rather than an error when the host application binds no `Gate` at all, so a route that cannot be authorised stays invisible instead of confirming it exists.
- `TRUSS-INT-007` claimed that duplicate pairs were possible on a table where one of the two foreign keys already carried a single-column unique index. A unique `user_id` makes `(user_id, insurance_plan_id)` unique by itself, so the finding was not merely noisy, it was false, and the composite unique key it recommended would have been redundant. The rule now accepts a unique index over any part of the pair, provided those columns are `NOT NULL`, since most engines allow repeated `NULL`s in a unique index. Unlike the narrowing above, this cannot cost a true positive: it is arithmetic rather than a heuristic. Reported by [@belabiedredouane](https://github.com/belabiedredouane).

## [1.9.1] - 2026-08-20

### Fixed

- Six export actions were offered by dashboards that cannot perform them, and choosing one failed with nothing shown at all: no file, no error, nothing. The structural formats (Data dictionary and DBML on the Export view menu, and Copy JSON, Download JSON, Download CSV and Download Markdown on a table's menu) are generated server-side, so a dashboard rendered without an export endpoint, which is a static page or an install that turned the route off, has nothing to ask. It offered them anyway and threw from inside a promise, where the failure was swallowed. Unavailable items now render `aria-disabled` with a note giving the reason, and the handler ignores them. `aria-disabled` rather than the `disabled` attribute is deliberate: a disabled button leaves the tab order, so the item and its explanation would disappear for exactly the people who cannot see it dimmed, and the note carries the reason in words so the dimming is never the only signal.
- PNG export produced nothing on a large diagram. It rasterises through a canvas at a fixed 2x with no ceiling, and past roughly 268 megapixels the browser hands back a blank canvas whose `toBlob` yields null, which the caller discarded. Measured rather than estimated: 33 tables came to 98 megapixels and worked, around 50 tables came to 384 and failed silently. The scale is now fitted to what a canvas will actually rasterise, staying at 2x where there is room, stepping towards 1x where there is not, and refusing outright when even 1x will not fit, pointing at SVG and at focusing a table instead. It never goes below 1x, because downscaling discards the pixels that make labels legible, which is the only reason to want a raster at all. The cutoff comes from the diagram's measured geometry rather than a table count, since twenty wide tables can outgrow fifty narrow ones. An empty diagram now reports as nothing to export rather than as something too large.

## [1.9.0] - 2026-08-18

### Added

- The Focus picker is now searchable. It was a native dropdown with one entry per table, which is fine on a small schema and unusable on a large one: at a couple of hundred tables it is a long scrolling list whose only search is the browser's prefix jump, so `item` never reaches `order_items` and nothing shows you what matched. Type to narrow it, matching anywhere in the name, with the matched span highlighted in each row and the number of matches announced as you type. It agrees with the toolbar filter now, which has always matched substrings. Reported by Alberto Peripolli (@trippo) from a schema of roughly that size.
- The diagram is now operable from the keyboard. Table names, enum type labels, and health markers already carried a button role, so screen readers announced them as buttons; they now behave like one, answering Enter and Space. Escape closes the open menu and returns focus to whatever opened it, so a keyboard user is never dropped at the top of the document, and opening a menu with a key moves focus into it. Each of those triggers also has a visible focus ring, which is a shape rather than a colour change so it does not depend on distinguishing two colours.
- The rendered diagram now names and describes itself for assistive technology (`accTitle` / `accDescr`, which Mermaid renders as `<title>` and `<desc>`). The description tracks the view you are actually looking at: the table and relationship counts, the active filter, and the focused table with its depth.
- An accessibility check runs on every push: axe-core scans the dashboard and its overlays against WCAG 2.2 AA in the browser suite, reported as its own CI step, alongside specs covering keyboard operation. Run it locally with `npm run test:a11y`.

### Fixed

- The zoom slider had a tooltip but no accessible name, so screen readers announced an unlabelled slider. It now carries an explicit label; nothing changes visually.

### Upgrading

- Nothing to do, unless you have published the dashboard view with `vendor:publish --tag=truss-views`. The Focus control changed from a `<select>` to a combobox, and the frontend wires the picker only when the new markup is present, so a published copy of the old view leaves Focus inert rather than erroring. Re-publish the view to pick it up.
- This release closes the Level A keyboard failures found in the dashboard. It is not an audit of every success criterion, so it is not a claim of WCAG conformance: what is covered and what is not is written up at https://trussphp.com/guides/accessibility/.

## [1.8.4] - 2026-08-17

### Fixed

- A cache store that was configured but not usable could fail `php artisan migrate` and take the dashboard down. `CACHE_STORE=database` is Laravel's own default and its `cache` table is not always there yet (a partial `migrate --path=` batch, a `migrate:rollback` past the table, a secondary connection), and a Redis or Memcached server can simply be unreachable. Two things went wrong. The rebuild that runs after a migration threw *after* every migration had already committed, so Artisan reported a failure on work that had succeeded, which can break a deploy step running `migrate --force`. And the schema endpoint returned HTTP 500, so a first visit to `/truss` on a fresh app showed "Could not load schema" over an empty diagram. Truss now treats an unreachable cache store the way it already treats an unreachable database or disk: the structure is read live and simply not cached, the dashboard says why, and the migration listener never throws. `truss:show`, `truss:doctor`, `truss:diff` and `truss:export` print a notice and keep working, with `truss:export` writing it to stderr so a piped export stays clean and `--check` still fails only on real drift. `truss:rebuild` is the deliberate exception: it reports a failed write and exits non-zero, because storing the snapshot is its only job. No configuration changes, and nothing to do on upgrade. Reported by @HafizMMoaz.

## [1.8.3] - 2026-08-12

### Fixed

- On a connection configured with a table prefix (`'prefix' => 'portal_'`, or `DB_PREFIX`), every table rendered as an empty block: no columns, no types, no keys, and no relationship lines. Laravel reports table names exactly as the database stores them, prefix included, but prepends the prefix again to any name passed into its column, index and foreign key methods, so introspection was asking for `portal_portal_users` and getting an empty result back with no error to explain it. The prefix is now suspended for the duration of a snapshot, and introspection works in real database names throughout. Suspending it rather than trimming each name also covers a schema shared between apps, where the prefix separates the two and the other app's tables never carried it. The same fault silently dropped every native column comment from `truss:export` annotations on a prefixed connection, and is fixed with it. Reported by @locshino.
- Export annotations could pick up table and column comments from a database the app has nothing to do with. Reading them used an unscoped table listing, which on Laravel 12 and later covers every schema on the server, so on a shared host another database's comments entered the map, and a table sharing a name with one of yours could annotate it with that other application's meaning. The listing is now scoped to the connection's own schema, matching what the diagram has always done. Structure only as ever: comments are part of the table definition, never row data.

## [1.8.2] - 2026-08-12

### Fixed

- A clean install could return HTTP 500 from the schema endpoint, showing "Could not load schema" over an empty diagram, on any app whose default filesystem disk is remote (for example `FILESYSTEM_DISK=s3`). The schema-diff baseline was written to the application's default disk, so reading it went through the remote adapter and threw, and nothing in the resulting error pointed at the cause. Three things change. The baseline disk now defaults to `local` rather than following the application, since it is derived tooling state and does not belong in an application bucket. Every baseline operation now degrades instead of throwing, so a disk problem costs you the Changes panel and nothing else: the diagram needs no filesystem access at all, and the same fault could previously fail `php artisan migrate` through the migration listener. And the failure is now explained rather than silent, as a dashboard notice and, in `truss:diff`, a message naming the disk and `TRUSS_DIFF_DISK`.
- The dark border around a table was only drawn on three sides of the title block, leaving the body outlined in the pale hairline colour, so the entity looked unfinished rather than deliberate. Mermaid draws each row as a full-width rect after the outer path, so the rows were repainting the shared left and right edges. The outline is now re-drawn over the rows, giving one continuous border at one width and colour, with the hairlines between rows kept. It holds for the focused, changed and health-flagged variants too, since they all restyle the same outline. Reported by Alberto Peripolli (@trippo).

### Changed

- A custom theme no longer flattens the table outline and the row separators into one colour. The `border` knob paints four things at once, so setting it used to give every line in a table the same weight, losing the hierarchy the shipped palette has (a strong outline against pale row separators). The row hairline is now derived as a translucent tint of the border colour, the same way the background grid follows the accent, so a few knob values keep reading as a designed table rather than a uniform grid. Existing custom themes will show lighter row separators than before. A border given as `rgb()`, `hsl()` or a colour keyword is unchanged, since tinting needs the colour channels.

## [1.8.1] - 2026-08-11

### Fixed

- Filtering while a table was focused emptied the diagram with no explanation. The two selections compose, so searching for a table outside the focused neighbourhood correctly matched nothing, but the banner named neither and the search simply looked broken. The empty state now says which filter and which focus produced it, and offers a **Clear focus** action when dropping the focus would actually show something (it stays silent about the focus when the filter alone matches nothing, since clearing it would leave the diagram just as empty). Clearing keeps the filter you typed and removes `focus` from the URL. The same dead end used to appear when a deep link focused a table that config excludes on that connection; it is now explained too. Thanks to @trippo for reporting it, after spotting it in @PovilasKorop's Laravel Daily video.

### Changed

- Dashboard copy no longer uses em dashes: the large-schema banner, the empty option in the Focus picker, and the table-count placeholder in the footer. Wording only, no behaviour change.

## [1.8.0] - 2026-08-10

### Added

- Truss as AI context: the export now doubles as grounding context for a coding agent.
  - **Annotations**: declare business meaning a type cannot express (that `status = 1` means paid, that a table is deprecated) under `truss.annotations` (per-table, per-column, and global notes), or read it from native database comments by keeping `'database'` in `annotations.source`. Annotations render into every text format and are stripped with `--no-annotations`. Structure only: a comment is part of the `CREATE TABLE` definition, not row data.
  - **`--compact`**: drop column defaults and non-unique indexes to shrink the output without losing any table, column, or foreign key.
  - **`--focus=<table>` / `--depth=<n>`**: reduce the export to a table and its foreign-key neighbourhood.
  - **`llm` format**: a dense, token-trimmed plaintext format tuned for feeding a coding agent.
  - **`truss.export.default_format`** config for the format used when none is given.
- A gated `GET {prefix}/export/{format}` HTTP route that serves the same structural export as the command and facade (behind the `viewTruss` gate), so the dashboard download and any HTTP client share one pipeline.
- An optional read-only, structure-only MCP server (Model Context Protocol) that exposes the live schema to a coding agent over local stdio, built on `laravel/mcp`. It adds the tools `list_tables`, `describe_table`, `get_schema`, `focus_table`, and `get_structural_review`, plus a `truss://schema` resource. Opt in with `composer require laravel/mcp` and start it with `php artisan mcp:start truss`; toggle with `truss.mcp.enabled`. Every tool advertises the MCP `readOnlyHint`, so a client can present them as read-only instead of prompting for write approval on each call. No row data, ever, and it honours the same exclusion and managed-connection safeguards as the rest of Truss.
- A fluent, immutable `Truss` facade for building exports programmatically, for example `Truss::snapshot()->focus('orders', depth: 1)->compact()->toDbml()`, with `only()`, `except()`, `focus()`, `compact()`, `withoutAnnotations()`, `fresh()`, and `connection()` filters and a terminal per format.

### Changed

- The dashboard now generates structural downloads (DBML, Markdown, JSON, CSV) server-side through the gated export route instead of duplicating the generators in JavaScript, so the CLI, the facade, and the dashboard produce identical output from one PHP source of truth. PNG and SVG are unchanged (still rendered in the browser).
- CI now runs the test suite against MySQL and Postgres service containers in addition to SQLite, so native-comment and engine-specific behaviour is exercised.

## [1.7.0] - 2026-08-05

### Added

- A `SECURITY.md` security policy that documents a private disclosure channel and the reporting scope, so a vulnerability can be reported privately instead of through a public issue.

### Changed

- The frontend test suites now run in CI. A new workflow installs Node, runs the Vitest unit tests, and runs the Playwright browser tests on every push and pull request, so client-side changes and dependency bumps are exercised automatically rather than relying on a local run.
- The development test runner (Vitest) was upgraded to 4.x. Test tooling only, with no change to the shipped package.

### Security

- Every GitHub Actions reference in CI is now pinned to a full commit SHA with a trailing version comment, so a moved or compromised tag can no longer redirect a workflow to unintended code.
- Added a Dependabot configuration covering GitHub Actions, Composer, and npm, on a weekly schedule with a seven-day cooldown, so dependency and action updates arrive as reviewable pull requests and the pinned SHAs stay current.

## [1.6.1] - 2026-08-03

### Fixed

- Theming now re-skins the whole diagram, not just the tables. A custom `truss.theme` palette previously left the relationship lines and labels, the label backdrop, the background grid, and (in dark mode) the odd table rows and input backgrounds on the shipped Blueprint colours, so a themed diagram showed blue lines and mismatched rows. The `background`, `surface`, and `muted` knobs now also drive the label, row, and input tokens, and the background grid is derived as a faint tint of the `accent` colour, so a few knob values re-skin the diagram completely.

## [1.6.0] - 2026-08-03

### Added

- Theming and custom palettes: match Truss to the app it is embedded in by defining your own colours and fonts from config under `truss.theme`. A small set of semantic knobs (`accent`, `background`, `surface`, `text`, `border`, and more) plus two font-family knobs re-skin the whole dashboard, chrome and diagram, in both light and dark; only the knobs you set are overridden, the rest stay on the default, so a handful of values is enough. Delivered as a same-origin stylesheet, so a strict Content-Security-Policy still needs only `style-src 'self'`, with no build step and no extra request on a default install. Each value is validated before it is emitted, so an invalid value falls back to the default rather than breaking the sheet.
- Schema export from the command line: `php artisan truss:export` writes the database structure to DBML, JSON, CSV, a Markdown data dictionary, or Mermaid, for CI, tooling, and version control. The command-line counterpart to the dashboard export button, generated from PHP so it needs no browser. Writes to stdout by default (pipeable) or a file with `--output`, filters with `--tables` / `--exclude` (config `excluded_tables` always wins), targets a connection with `--connection`, and rebuilds first with `--fresh`. Output is deterministic (the same schema always produces the same bytes), so `--check` can fail the build when a committed export file has gone stale. Exit codes: `0` written or up to date, `1` `--check` found drift, `2` a usage or runtime error. Structure only, with no network call.

### Removed

- The unused `truss.diagram.theme` config key (it was wired to nothing). Theme selection now lives under the new `truss.theme` block.

### Fixed

- The dashboard toolbar no longer overflows the viewport on a small desktop. With every control active (filter, focus, depth, type labels, and the export, changes, health, legend, and theme buttons) and long table names, the bar could grow wider than the window; because it is sticky it pinned only vertically, so a horizontal drag slid the whole header sideways and clipped the brand. The secondary controls (focus, depth, type labels) now fold into the more menu below 1024px, the focus and connection selects are width capped, and the search field can shrink, so the toolbar always fits.
- On a phone the legend now opens as a top-right dropdown, matching the changes and health panels, instead of a full-width bottom sheet, so all the toolbar overlays behave consistently.

## [1.5.0] - 2026-07-30

### Added

- Schema doctor: `php artisan truss:doctor` (aliased `truss:check`) reviews the database structure for problems visible from structure alone, a table with no primary key, an unindexed foreign key, duplicate or redundant indexes, a foreign key type mismatch, money stored as a float, and more, and can fail CI. Deterministic and structure only, with no AI and no network call. Thirteen rules across integrity, index, and type categories, presets (recommended, strict, none), per-rule and per-category configuration, ignore patterns, a grouped console table (findings grouped per table, long messages wrapped) and JSON output, and exit codes for CI. Configured under `truss.doctor`.
- Schema doctor in the dashboard: a "Health" panel lists the findings grouped by table, badges the tables that have findings on the diagram (coloured by worst severity), marks heuristic findings, and focuses a table when you click it. The toggle is an animated heart, the panel can be maximized for reading, the offending column is marked on the diagram itself (click it for the finding detail), and opening any dashboard overlay now closes the others. Tables with findings are flagged on the diagram at all times (toggle with `truss.doctor.flag_tables` / `TRUSS_DOCTOR_FLAG_TABLES`). It rides the existing schema endpoint and is toggled with `truss.doctor.dashboard` (`TRUSS_DOCTOR_DASHBOARD`).

## [1.4.2] - 2026-07-29

### Changed

- The dashboard connection switcher label now reads "Connections" instead of "Conn".
- The legend overlay anchors to the dashboard container rather than the viewport, so it stays correctly placed when the dashboard is embedded below other page chrome (an iframe, or an embed).

## [1.4.1] - 2026-07-29

### Changed

- The dashboard toolbar and overlay labels (Filter, Focus, Legend, Export view, and the rest) now read in sentence case instead of all caps, matching the documentation site. Diff badges read "Added" / "Removed" / "Changed". Visual only, no behaviour change.

## [1.4.0] - 2026-07-29

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
