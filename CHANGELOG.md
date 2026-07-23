# Changelog

All notable changes to `laravel-truss` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Per-table export/focus menu: click a table name in the diagram to focus it, copy its structure as JSON, or download its structure as JSON or CSV. Exports are generated in the browser and contain structure only (columns, keys, indexes), never row data.

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
