# Laravel Truss

[![Latest Version on Packagist](https://img.shields.io/packagist/v/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![Tests](https://img.shields.io/github/actions/workflow/status/albertoarena/laravel-truss/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/albertoarena/laravel-truss/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![License](https://img.shields.io/packagist/l/albertoarena/laravel-truss.svg?style=flat-square)](LICENSE)

A Telescope-style live database structure viewer for Laravel. Truss scans your live schema and renders it as a scrollable, zoomable **ER diagram** right inside your app — so you can see how the tables actually connect without opening a DB client or reverse-engineering migrations by hand.

**Structure only. No row data is ever read or exposed.**

> 📖 Full documentation: **[albertoarena.github.io/laravel-truss](https://albertoarena.github.io/laravel-truss)**

<!-- Add a screenshot of a demo schema here, e.g. art/screenshot.png (do not commit a real app's schema). -->
<!-- ![Truss dashboard](art/screenshot.png) -->

## Features

- 🧭 **Live ER diagram** of your database — tables, columns, primary/foreign keys, and indexes — rendered with [Mermaid](https://mermaid.js.org/).
- 🔒 **Structure only** — only the `CREATE TABLE` shape (names, types, keys). Row contents are never queried. This is a hard guarantee, not a config default.
- 🎯 **Focus mode** — click a table to see just it and its foreign-key neighbours; it's centred and highlighted, keeping large schemas legible.
- 🔎 **Filter** by table name, and toggle native types (`varchar(255)`) vs. Laravel-style labels (`string`).
- 🖱️ **Pan & zoom** like a map — drag to pan, scroll or pinch to zoom, with auto-fit and a Fit button.
- 🧩 **`enum`/`set` values** on tap — long value lists are compacted and revealed in a popover.
- 🌓 **Light & dark** "blueprint" theme that follows the OS (with a manual toggle).
- 📦 **Self-contained** — Mermaid and fonts are vendored and served from the package. **No CDN**, so it works offline and under a strict Content-Security-Policy.
- ⚡ **Cached & auto-rebuilt** — the snapshot is cached and refreshed automatically after migrations run.
- 🔌 **Multi-connection** — visualise any configured database connection, with a switcher.

## Requirements

- PHP 8.3+
- Laravel 12+

## Installation

Install via Composer (as a dev dependency — Truss is a development/inspection tool):

```bash
composer require albertoarena/laravel-truss --dev
```

That's it. The service provider is auto-discovered; there is nothing to publish to get started.

Optionally publish the config file to customise it:

```bash
php artisan vendor:publish --tag=truss-config
```

## Quick start

By default Truss is **enabled in the `local` environment only**. Start your app and visit:

```
/truss
```

You'll see your schema as an ER diagram. Drag to pan, scroll/pinch to zoom, pick a table in **Focus** to zoom into its neighbourhood, or type in **Filter** to narrow things down.

The route prefix, and everything else, is configurable — see [Configuration](#configuration).

## Authorization

Truss is safe to install in production and gated there. Access is guarded by two independent layers:

1. **`truss.enabled`** — the deploy switch. When off, the routes respond **404**, as if they don't exist. Defaults to local-only, so a production deploy is dark until you set `TRUSS_ENABLED=true`.
2. **The fixed `viewTruss` gate** — the access control. It is consulted **only in non-local** environments (local is open, mirroring Telescope). A denial returns **404**, so the dashboard never confirms it exists to someone who may not see it.

To expose Truss in production you must do **both**: enable it, and authorize the viewers.

### Authorize by email (zero code)

The shipped `viewTruss` gate admits the emails you list — the quick path for "let these admins in":

```dotenv
TRUSS_ENABLED=true
TRUSS_ALLOWED_EMAILS="ada@example.com,grace@example.com"
```

The list is ignored in local, and it fails closed — an empty list means no one may view in non-local until you add emails or override the gate.

### Authorize by role (override the gate)

For anything beyond an email list, define your own `viewTruss` gate in a service provider — exactly as with Telescope. Your definition fully replaces the default:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewTruss', fn (User $user) => $user->isAdmin());
```

The gate can only identify the viewer if the request carries an auth context, which the `truss.middleware` stack (default `['web']`) provides — swap it for a custom guard or Sanctum if your app authenticates differently.

## Configuration

All behaviour lives in `config/truss.php`. Every key has a sensible default and an `env()` override.

| Key | Env | Default | Purpose |
|---|---|---|---|
| `route_prefix` | `TRUSS_ROUTE_PREFIX` | `truss` | URL prefix for the page and the JSON/asset routes |
| `enabled` | `TRUSS_ENABLED` | `local` only | Global on/off switch; off → routes 404 |
| `middleware` | — | `['web']` | Auth-context middleware so the gate sees the user |
| `authorization.allowed_emails` | `TRUSS_ALLOWED_EMAILS` | `[]` | Emails the default gate admits in non-local (comma-separated) |
| `cache.ttl` | `TRUSS_CACHE_TTL` | `3600` | Seconds a schema snapshot is cached |
| `connections` | — | `[]` | Which DB connections are visualizable (defaults to the app's default) |
| `excluded_tables` | — | framework tables | Tables filtered out server-side (never sent to the browser) |
| `diagram.type_labels` | `TRUSS_TYPE_LABELS` | `native` | Default column-type labels: `native` or `laravel` |
| `diagram.theme` | `TRUSS_DIAGRAM_THEME` | `default` | Mermaid base theme |
| `diagram.mermaid_url` | `TRUSS_MERMAID_URL` | `null` | Where to load Mermaid from; null self-hosts (no CDN) |
| `diagram.min_zoom` | `TRUSS_MIN_ZOOM` | `0.7` | Readable floor for auto-fit; the Fit button ignores it |
| `focus.default_depth` | `TRUSS_FOCUS_DEPTH` | `1` | Foreign-key neighbour depth when focusing a table |
| `large_schema.warn_above` | `TRUSS_LARGE_SCHEMA_WARN_ABOVE` | `60` | Table count above which a "large schema" hint is shown |

`excluded_tables` are applied **server-side at serve time**, so their structure never reaches the browser and toggling them needs no rebuild. Per-connection overrides are supported via `connections.<name>.excluded_tables`.

## Commands

```bash
# Manually rebuild the cached schema snapshot (all managed connections)
php artisan truss:rebuild

# Rebuild a specific connection
php artisan truss:rebuild --connection=mysql
```

Rebuilds also happen automatically after `migrate`, `migrate:fresh`, and `migrate:rollback`.

## How it works

Truss reads the **live schema** of the target connection via Laravel's native schema introspection (`Schema::getTables/getColumns/getIndexes/getForeignKeys`) — no Doctrine DBAL, no static migration parsing. The result is a plain, serializable snapshot, cached via the `Cache` facade. If no database connection is reachable, it falls back to replaying migrations on an in-memory SQLite connection and flags the diagram with a banner.

The frontend fetches that snapshot as JSON and builds the Mermaid diagram in the browser. Filtering and focusing happen client-side (no refetch); permanent `excluded_tables` are removed server-side.

## Assets & Content-Security-Policy

Truss serves its JavaScript, CSS, a vendored copy of **Mermaid**, and the **IBM Plex Mono** font from a gated route inside the package — no `vendor:publish`, no CDN. Under a strict CSP, `script-src 'self'`, `style-src 'self'`, and `font-src 'self'` cover Truss's own assets. Mermaid injects styles into the rendered SVG at runtime, so `style-src` also needs `'unsafe-inline'`. To load Mermaid from a CDN instead, set `TRUSS_MERMAID_URL`.

## Testing

```bash
composer test        # Pest (PHP)
composer lint        # Laravel Pint
npm test             # Vitest (client-side diagram logic)
npx playwright test  # Playwright (browser: rendering & interaction)
```

## Security

Truss exposes **structure only** — table, column, index, and foreign-key definitions, plus column defaults (which are part of the `CREATE TABLE` shape). It never queries or exposes row data. Access is protected by the fixed `viewTruss` gate; see [Authorization](#authorization). If you discover a security issue, please email arena.alberto@gmail.com rather than opening a public issue.

## Credits

- [Alberto Arena](https://github.com/albertoarena)
- Built with [spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools) and [Mermaid](https://mermaid.js.org/)

## License

The MIT License (MIT). See [LICENSE](LICENSE).
