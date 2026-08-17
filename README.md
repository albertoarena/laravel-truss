# Laravel Truss

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/cover-dark.png">
  <img src="art/cover-light.png" alt="Laravel Truss: see your database structure as a live, zoomable ER diagram">
</picture>

[![Documentation](https://img.shields.io/badge/docs-website-2f6feb?style=flat-square)](https://trussphp.com)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![Total Downloads](https://img.shields.io/packagist/dt/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![Tests](https://img.shields.io/github/actions/workflow/status/albertoarena/laravel-truss/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/albertoarena/laravel-truss/actions/workflows/run-tests.yml)
[![License](https://img.shields.io/badge/license-MIT-red.svg?style=flat-square)](LICENSE)
![Repo views](https://raw.githubusercontent.com/albertoarena/laravel-truss/traffic-data/badge.svg)

Laravel Truss is a live database structure viewer. It scans your live schema and renders it as a scrollable, zoomable ER diagram right inside your app, so you can see how the tables actually connect without opening a DB client. It reads **structure only** (tables, columns, keys, indexes); row data is never queried or exposed.

**[Try the live demo](https://trussphp.com/demo/)** to pan, zoom, focus, and export a sample schema in your browser, no install needed, then build a palette in the **[theme builder](https://trussphp.com/theme-builder/)** and copy the config. See what has shipped and what is next on the **[roadmap](https://trussphp.com/roadmap/)**.

> **Stay updated:** click **Watch > Custom > Releases** to hear about new features, or follow along in [Discussions](https://github.com/albertoarena/laravel-truss/discussions).

## Features

- Live ER diagram of your database, rendered with Mermaid.
- Focus mode: a table and its foreign-key neighbours, centred and highlighted.
- Filter by table name, and toggle native types against Laravel-style labels.
- Map-style pan and zoom, with auto-fit and a Fit button.
- Export the diagram as PNG or SVG, or its structure as JSON, CSV, a Markdown data dictionary, DBML, or a token-trimmed `llm` format, from the browser or, for CI and tooling, from the command line with `php artisan truss:export`. Structure-only and deterministic.
- Feed your real, live structure to a coding agent as grounding context: annotate it with business meaning, trim it with `--compact`, and narrow it with `--focus`, so the agent stops inventing columns. Structure only, never data.
- Schema diff: see what changed since your last migration, in a dashboard "Changes" panel and via `php artisan truss:diff`. Structure-only, added / removed / changed tables, columns, indexes, and foreign keys.
- Schema doctor: review your structure for problems (missing primary keys, unindexed foreign keys, duplicate indexes, risky types) in the terminal or in CI with `php artisan truss:doctor`, and in a dashboard "Health" panel that flags the same problems on the diagram. Deterministic and structure-only, no AI.
- Multiple connections: list them in config and switch between their diagrams with a toolbar picker, each scoped to its own database.
- Light and dark "blueprint" theme, or bring your own: define custom colours and fonts from config to match your app. Config driven, CSP-safe, no build step.
- Self-contained: Mermaid and fonts are vendored and served from the package, so it works offline and under a strict Content-Security-Policy (no CDN).
- Cached snapshot, rebuilt automatically after migrations.

## Documentation

Full documentation is at **[trussphp.com](https://trussphp.com)**.

- [Installation](https://trussphp.com/getting-started/installation/)
- [Quick start](https://trussphp.com/getting-started/quick-start/)
- [Authorization](https://trussphp.com/guides/authorization/)
- [Configuration reference](https://trussphp.com/reference/configuration/)
- [Roadmap](https://trussphp.com/roadmap/)
- [Video overview](https://www.youtube.com/watch?v=zogsFocamlU) by Laravel Daily (7 min)

## Installation

For local use, install Truss as a dev dependency:

```bash
composer require albertoarena/laravel-truss --dev
```

To run Truss gated on staging or production, install it as a **regular dependency** instead. Dev dependencies are excluded from `composer install --no-dev` builds, so a `--dev` install never reaches a production deploy and `/truss` returns 404 there:

```bash
composer require albertoarena/laravel-truss
```

Requires **PHP 8.3+** and **Laravel 12+**. The service provider is auto-discovered, so there is nothing to publish to get started.

## Quick start

By default Truss is enabled in the `local` environment only. Start your app and visit:

```
/truss
```

To use Truss in a non-local environment you must both enable it and authorize the viewers. See [Authorization](https://trussphp.com/guides/authorization/).

## Multiple connections

Out of the box Truss visualizes your application's default database connection. If your app spans more than one connection, for example a main database alongside a separate module database, list the connections you want to visualize under `truss.connections`:

```php
// config/truss.php
'connections' => [
    'mysql' => [],
    'modules' => ['excluded_tables' => ['module_jobs']],
],
```

When two or more connections are configured, a connection picker appears in the dashboard toolbar. Switching it re-renders that connection's schema, and the selection is kept in the URL so a given view can be shared or bookmarked. Each connection is introspected against its own database only, so a shared server never shows tables that belong to another database.

The keys are Laravel connection names from `config/database.php`. Per-connection options mirror the global ones (such as `excluded_tables`), so you can hide different tables on each connection.

## Schema doctor

`php artisan truss:doctor` (aliased `truss:check`) reviews your database structure for problems visible from structure alone: a table with no primary key, a foreign key with no index, duplicate indexes, money stored as a float, and more. It is deterministic and structure-only, with no AI and no network call, so it is safe to run in CI.

```bash
php artisan truss:doctor
php artisan truss:doctor --connection=mysql --format=json
php artisan truss:doctor --preset=strict --fail-on=warning
```

It exits `0` when clean, `1` when a finding is at or above the `--fail-on` level (default `error`), and `2` on a bad option or a snapshot error, so a migration that introduces a problem can fail the build. Presets (`recommended`, `strict`, `none`), per-rule severity and enable / disable, ignore patterns, and the fail level are all configurable under `truss.doctor`. See the [configuration reference](https://trussphp.com/reference/configuration/).

Every finding carries a stable code (e.g. `TRUSS-IDX-001`) shown in both the command and the panel; the [schema doctor guide](https://trussphp.com/guides/schema-doctor/) lists all the rule codes and what each checks.

Structure only: it reads the same cached snapshot the diagram uses and never queries row data.

### In the dashboard

The same findings show in the dashboard, under the name **Health**: the command is `truss:doctor`, and the dashboard front end for it is the heart icon in the toolbar labelled "Health". Same feature, same findings. The Health panel lists them grouped by table, and every table with a problem carries a small severity badge on the diagram, so you can see what needs attention at a glance. Open the panel to read the findings, click a table to focus it, or click the marked column to see the finding for that field. Heuristic (lower-confidence) findings are marked as such.

It rides the schema endpoint the diagram already loads, so there is no extra request. Two switches control it under `truss.doctor`:

- `dashboard` (env `TRUSS_DOCTOR_DASHBOARD`, default on): show the Health panel at all. When off, the dashboard never receives any findings and the CLI is untouched.
- `flag_tables` (env `TRUSS_DOCTOR_FLAG_TABLES`, default on): always badge tables with findings on the diagram, even with the panel closed. Turn it off to keep the diagram clean and surface findings only when the panel is open.

## Schema export

`php artisan truss:export` writes your database structure to a standard format for CI, tooling, and version control. It is the command-line counterpart to the dashboard's export button, generated from PHP so it does not need a human with the diagram open. Deterministic and structure-only, with no network call, so it is safe in CI and commit hooks.

```bash
php artisan truss:export                                  # DBML to stdout
php artisan truss:export --format=json                    # dbml, json, csv, markdown, mermaid, or llm
php artisan truss:export --format=dbml --output=docs/schema.dbml
php artisan truss:export --tables=orders,order_lines      # only these (config exclusions still apply)
php artisan truss:export --connection=mysql --exclude=telemetry
```

Output goes to stdout by default so it pipes cleanly; `--output` writes a file. The output is deterministic: the same schema always produces the same bytes, whatever order the database reports its tables in. That is what makes the CI drift-check reliable:

```bash
# Fail the build if the committed schema file is out of date
php artisan truss:export --format=dbml --output=docs/schema.dbml --check
```

`--check` regenerates the export, compares it against `--output`, writes nothing, and exits non-zero when they differ, so a migration that changes the schema without refreshing the committed file fails the build. Exit codes: `0` written or up to date, `1` `--check` found drift, `2` a usage or runtime error (unknown format, unwritable path, an unmanaged connection, `--check` without `--output`, or no tables matched the filters). Add `--fresh` to rebuild the cached snapshot before exporting.

Config `excluded_tables` always wins over `--tables`, so the export never exposes a table the dashboard hides. Structure only: it reads the same cached snapshot the diagram uses and never queries row data.

### Truss as AI context

The same export doubles as grounding context for a coding agent: hand it your real, live structure so it stops inventing columns. Three flags make the output worth pasting or piping into Claude Code, Cursor, or any agent:

```bash
php artisan truss:export --format=llm                     # a dense, token-trimmed plaintext format
php artisan truss:export --compact                        # drop defaults and non-unique indexes
php artisan truss:export --focus=orders --depth=1         # one table and its FK neighbourhood
```

**Annotations** add the business meaning a type cannot: that `status = 1` means paid, that a table is deprecated. Declare them in `config/truss.php` under `annotations` (per-table, per-column, and global notes), or read them from native database comments by keeping `'database'` in `annotations.source`. They render into every text format and are stripped with `--no-annotations`.

This stays structure only. Native comments are part of the `CREATE TABLE` definition, not row content (the same boundary as column defaults), and no export, in any format or flag combination, ever contains row data. A schema is not a semantic layer: Truss says what exists, not what the business means beyond the annotations you write.

The same pipeline is available programmatically through the `Truss` facade, so you can build context in your own code, tooling, or tests without shelling out to the command:

```php
use AlbertoArena\Truss\Facades\Truss;

$dbml = Truss::snapshot()
    ->only(['orders', 'order_lines'])
    ->focus('orders', depth: 1)
    ->compact()
    ->toDbml();
```

The builder is immutable (each filter returns a new instance, so a base builder is safe to share) and offers `only()`, `except()`, `focus()`, `compact()`, `withoutAnnotations()`, `fresh()`, and `connection()`, plus a terminal per format (`toDbml()`, `toJson()`, `toCsv()`, `toMarkdown()`, `toMermaid()`, `toLlm()`, `toArray()`). It produces exactly the same bytes as `truss:export` for the same filters, and honours the same `excluded_tables` and managed-connection safeguards.

The dashboard's structural downloads (DBML, Markdown, JSON, CSV) are served by the same pipeline over a gated `GET {prefix}/export/{format}` route (behind the `viewTruss` gate), which accepts the same filters as query parameters (`only`, `except`, `focus`, `depth`, `compact`, `connection`). The command, the facade, and the dashboard therefore share one source of truth. PNG and SVG stay in the browser (they are rendered from the live diagram).

### MCP server

For coding agents that speak the Model Context Protocol (Claude Code, Cursor, and others), Truss ships an optional read-only, structure-only MCP server, so the agent queries your current schema on demand instead of working from a paste that goes stale. It is opt-in and adds no required dependency:

```bash
composer require laravel/mcp
php artisan mcp:start truss
```

Point your MCP client at that command (local stdio). For Claude Code or Cursor:

```json
{
  "mcpServers": {
    "truss": { "command": "php", "args": ["artisan", "mcp:start", "truss"] }
  }
}
```

The server exposes five tools and one resource, all read-only and structure-only:

- `list_tables`: the tables, each with a one-line structural summary.
- `describe_table`: one table's columns, keys, indexes, foreign keys, and annotations.
- `get_schema`: the whole structure in any format (`dbml`, `json`, `csv`, `markdown`, `mermaid`, `llm`), optionally compact or limited to some tables.
- `focus_table`: a table and its foreign-key neighbourhood.
- `get_structural_review`: the deterministic `truss:doctor` findings.
- Resource `truss://schema`: the whole structure as one compact document.

Every tool answers with structure only, never data, and honours the same `excluded_tables` and managed-connection safeguards as the rest of Truss. It requires Laravel 12.41.1 or newer (or Laravel 13); Truss's own minimum is unaffected. Set `truss.mcp.enabled` to `false` to turn it off. A note on safety: if you pair a schema like this with a tool that executes generated SQL, that tool needs its own read-only connection and validation; Truss produces context, it never runs a query for you.

## Theming

Truss ships a light and dark "blueprint" theme. To match the app it is embedded in, redefine its colours and fonts from config under `truss.theme`. Everything is optional: you set a few semantic knobs and the rest stay on the default, so a handful of values re-skins the whole dashboard (chrome and diagram) in both light and dark.

Prefer to design it visually? The **[theme builder](https://trussphp.com/theme-builder/)** lets you dial in colours and fonts against a live dashboard preview and copy the config block straight into `config/truss.php`.

If you have not published the config yet (Truss works fine without it), publish it first with `php artisan vendor:publish --tag=truss-config`, then edit the `theme` block:

```php
// config/truss.php
'theme' => [
    'fonts' => [
        'sans' => 'Inter, system-ui, sans-serif',
    ],
    'colors' => [
        'light' => [
            'accent' => '#3730a3',
            'background' => '#ffffff',
        ],
        'dark' => [
            'accent' => '#a5b4fc',
            'background' => '#0b1020',
        ],
    ],
],
```

The colour knobs are `accent`, `accent-secondary`, `background`, `surface`, `surface-alt`, `text`, `muted`, and `border`; each maps onto the tokens it paints (`accent`, for instance, covers headings, primary-key badges, entity borders, and the focus ring). Some tokens are derived rather than painted flat: the row hairlines inside a table are a translucent tint of `border`, and the background grid a faint tint of `accent`, so a themed diagram keeps its visual hierarchy instead of going uniform. Set a knob under both `light` and `dark` to theme both modes, or omit `dark` to theme light only. Colours accept hex, `rgb()` / `hsl()`, or a CSS colour keyword; fonts are family names only, so name a font your app already loads or a system font (Truss serves no font files here).

The overrides are delivered as a same-origin stylesheet, so a strict Content-Security-Policy still needs only `style-src 'self'` (no inline styles), and a default install with no custom theme makes no extra request. Each value is validated before it is emitted, so an invalid value is ignored and falls back to the default rather than breaking the sheet. Contrast is yours to check: a custom palette can fail accessibility, so verify both modes against WCAG AA.

## Storage

Truss keeps its schema snapshot in the cache, which is derived and disposable. The one thing it writes to disk is the **schema-diff baseline**: a structure-only JSON file (never row data) recorded after each migration so the diff can show what changed. It lives at `truss/baselines/{connection}.json` on the disk set by `truss.diff.disk` (`local` by default, deliberately not your application's default disk, since this is derived tooling state rather than application data), is safe to delete, and is worth gitignoring alongside `storage/`. If that disk is unreadable, the diff is simply unavailable: the diagram, the doctor, and the exports are untouched. To turn the feature off entirely so nothing is written to disk, set `TRUSS_DIFF_ENABLED=false` (or `truss.diff.enabled` to `false`).

## Security

Truss exposes structure only and never queries row data. Access is protected by the fixed `viewTruss` gate. If you discover a security issue, please email hello@albertoarena.it rather than opening a public issue.

## Contributing

Contributions are welcome. Feel free to fork, improve, and open a pull request. Forking to contribute needs no permission and keeps this project's name: [TRADEMARK.md](TRADEMARK.md) is about publishing your own distribution, not about pull requests.

## Support

Laravel Truss is free and open source. If it has saved you time, you can support its ongoing maintenance and new features with a coffee:

**[ko-fi.com/albertoarena](https://ko-fi.com/albertoarena)**

Starring the repo and sharing it help just as much.

## 📬 Stay updated

Subscribe and get my free Spatie Event Sourcing cheat sheet (printable PDF), plus practical notes on Laravel and AI-assisted development, roughly once a month. No spam.

**[Get the cheat sheet →](https://albertoarena.it/subscribe/?utm_source=github&utm_medium=readme&utm_campaign=newsletter&utm_content=laravel-truss)**

## License

The MIT License (MIT). See [LICENSE](LICENSE).

The licence covers the code. The project name, the logo and the tagline are not part of it, and [TRADEMARK.md](TRADEMARK.md) says what you can do with them. Most things need no permission, including writing about Truss, naming an add-on package, and contributing.
