# Instructions — Build Plan

Follow this order. Each phase should be finishable and independently verifiable before moving to the next. Every step starts with a failing Pest test, per the TDD rule in `CLAUDE.md`.

## Phase 1 — Package skeleton

1. Initialize `composer.json` (vendor `albertoarena/laravel-truss`), `LICENSE` (MIT), `.gitignore`
2. Install `spatie/laravel-package-tools`, set up `TrussServiceProvider` following its conventions
3. Set up Pest, Pint, and base `TestCase`
4. Set up GitHub Actions CI: Pest across the supported PHP 8.3+/Laravel 12+ matrix
5. Confirm `composer test` and `composer lint` both run clean on an empty package

## Phase 2 — Config

6. Build `config/truss.php` per the reference in `docs/DESIGN.md`: `route_prefix`, `enabled`, `cache.ttl`, `connections`, `excluded_tables`, `diagram` (incl. `diagram.type_labels`), `focus.default_depth`, `large_schema.warn_above` (no `gate` key — the `viewTruss` ability name is fixed); register via `mergeConfigFrom` and make it publishable
7. Test: the config publishes and merges; every key resolves to its expected default. (Behavioural assertions that depend on later layers — exclusions actually filtering, disabled connections not visualizable — live in those phases, not here.)

## Phase 3 — Introspection layer

8. Define value objects (`src/Introspection/Data/`), composite-first: `Table` (name, columns, `primaryKey: string[]`, indexes, foreign keys), `Column` (name, native type, nullable, default), `Index` (name, `columns: string[]`, unique), `ForeignKey` (name, `columns: string[]`, referencesTable, `referencesColumns: string[]`, onUpdate, onDelete)
9. Build `SnapshotBuilder`: resolve the target connection (default unless specified) and introspect its live schema; implement the in-memory SQLite migration-replay path as the no-connection fallback, flagged as degraded — replay *all* migrator-resolved paths (app + package), skipping and recording any migration that fails on SQLite rather than aborting
10. Build `SchemaSerializer`: introspects the connection, maps to the value objects, serializes to array/JSON
11. Test against real fixtures: a live connection (simple table, single + composite FKs, single + composite indexes, composite PK pivot, PK-less table, self-referential FK, nullable/default columns), plus the fallback case (no connection → SQLite replay of all migrator paths produces a snapshot flagged degraded; a migration that fails on SQLite is skipped and recorded, not fatal)
12. Confirm this layer has zero dependencies on HTTP, Blade, or caching (see `src/Introspection/CLAUDE.md`)

## Phase 4 — Caching & rebuild trigger

13. Build `SchemaCacheRepository`: read/write the snapshot via Laravel's `Cache` facade, keyed per connection, respecting `cache.ttl`
14. Build `RebuildOnMigrationsEnded` listener, register it in the service provider
15. Build `truss:rebuild` Artisan command
16. Test: migrating triggers a rebuild; the manual command forces one; cache respects TTL

## Phase 5 — HTTP layer

17. Register routes behind the fixed `viewTruss` gate (ship a default definition allowing `local` only; the host app overrides the callback), disabled outside `local` by default
18. Build `SchemaApiController`: returns cached schema JSON for the requested connection (defaulting to the app's default connection), filtering config `excluded_tables` out server-side (from the full cached snapshot, at serve time — no rebuild needed to change exclusions), and carrying a `fallback` flag when the SQLite path was used
19. Build `IndexController` and the Blade shell view
20. Test: gate blocks unauthorized access; API returns correct JSON shape; excluded tables never appear in the response body (this is the test that protects the "no data exposed" promise, treat it as non-negotiable); connections disabled in config are not visualizable

## Phase 6 — Frontend

21. Build `MermaidDefinitionGenerator`: turns a *selected subset* of the schema JSON into a Mermaid `erDiagram` string, deriving per-column `PK`/`FK` badges from `primary_key` and `foreign_keys[].columns`; raise Mermaid's `maxTextSize`/`maxEdges` guards so large schemas render
22. Build the client-side selection pipeline over the received JSON (config exclusions are already applied server-side): interactive filter (text search / multiselect) + focus mode (a table plus its FK neighbours, configurable depth), both reducing to the same generator. Add the type-label toggle: native full type (default) vs. a best-effort Laravel-style short label computed here, defaulting to `diagram.type_labels`
23. Wire up the Blade view: fetch from the API endpoint, render via Mermaid.js, wrap in scrollable/zoomable container (zoom via CSS transform, not re-layout); show the SQLite-fallback banner and the large-schema warning above the configured threshold
24. Build the connection switcher (re-fetch, re-render, no full page reload)
25. Manual verification: test against a schema with 50+ (ideally 100+) tables — confirm it renders without hitting Mermaid limits, that focus mode keeps it legible and fast, and that scroll/zoom holds up

## Phase 7 — Documentation

26. Write `README.md` (installation, quick start, config reference, screenshot)
27. Write `docs/` markdown files (mirrors what's user-facing in the README, expanded)
28. Scaffold `website/` with Astro + Starlight, following the structure used in `filament-event-sourcing` and `laravel-netsons-deploy`
29. Add `.github/workflows/deploy-docs.yml`, triggered on `website/**` changes, deploying to GitHub Pages
30. Confirm all three doc surfaces (`README.md`, `docs/`, `website/src/content/docs/`) agree with each other

## Phase 8 — Release

31. Tag `v0.1.0`, publish to Packagist
32. Verify `composer require albertoarena/laravel-truss --dev` works cleanly in a throwaway Laravel 12 app

---

## Done log

_Update as phases complete. One line per completed phase, dated._

- 2026-07-22 — Phase 1: package skeleton (composer.json, MIT license, `TrussServiceProvider` via spatie/laravel-package-tools, Pest + Pint, GitHub Actions CI on PHP 8.3/8.4 × Laravel 12). `composer test` and `composer lint` run clean. Namespace `AlbertoArena\Truss`.
- 2026-07-22 — Phase 2: config (`config/truss.php` merged under the `truss` key via `hasConfigFile()`, publishable as `truss-config`). Keys: `route_prefix`, `enabled`, `cache.ttl`, `connections`, `excluded_tables`, `diagram` (+`type_labels`), `focus.default_depth`, `large_schema.warn_above`; no `gate` key (fixed `viewTruss`). Tested for defaults + publishability.
- 2026-07-22 — Phase 3: introspection layer. Composite-first value objects (`Table`/`Column`/`Index`/`ForeignKey`), `SchemaSerializer` (VOs → documented array shape), `SnapshotBuilder` (live native introspection via `Schema::get*`, with an in-memory SQLite fallback that replays all migrator paths and skips/records incompatible migrations). Tested against real fixtures (composite PK/FK, self-ref, PK-less, indexes, native types) + fallback; Pest arch test enforces no HTTP/view/cache/routing deps.
- 2026-07-22 — Phase 4: caching & rebuild. `SchemaCacheRepository` (per-connection key, TTL-aware, stamps `generated_at` here so introspection stays deterministic), `RebuildOnMigrationsEnded` listener (rebuilds after migrate when enabled, targeting the migrated connection), `truss:rebuild` command (always works, `--connection` option). Tested: caching/TTL/forget, auto-rebuild on `MigrationsEnded` (and skip when disabled), command success.
- 2026-07-23 — Phase 6: frontend. Client-side, no build step. Pure ES modules (`resources/js`): `mermaid-definition.js` (schema subset → `erDiagram`, deriving PK/FK badges from `primary_key`/`foreign_keys[].columns`, sanitizing native types into Mermaid-safe tokens, only emitting edges when both endpoints are in the subset), `selection.js` (`filterTables` text search + `focusTables` bidirectional-BFS neighbourhood, composed filter-then-focus), `type-labels.js` (best-effort native → Laravel label). Browser entry `truss.js` wires the Blade toolbar to fetch → select → `mermaid.render` (guards raised: maxTextSize 5M, maxEdges 10k), CSS-transform zoom/scroll, SQLite-fallback + large-schema banners, and a connection switcher (re-fetch, no reload). Assets published via `truss-assets`; `IndexController` passes managed connections to the view. Vitest (23 unit tests: composite PK/FK, self-ref, focus depth, sanitization, label mapping) + Playwright (9 browser tests: render, filter, focus, label toggle, zoom, connection switch, banners, and a 100-table large-schema render + focus-reduce). Generator is JS (client-side), not the stale `src/Support/*.php` in the design tree. Assets are served from the package by a gated `{prefix}/assets/{file}` route (`AssetController`, allow-listed basenames = traversal guard, `Cache-Control` set) rather than published — zero-config and Pest-testable; unauthorized users 404 on assets too. Mermaid is vendored (`resources/js/vendor/mermaid.min.js`, MIT) and self-hosted by default (no CDN; CSP `script-src 'self'`), with `diagram.mermaid_url`/`TRUSS_MERMAID_URL` as a CDN escape hatch. CSS moved out of the Blade into a served `resources/css/truss.css`. Playwright drives the actual vendored Mermaid.
- 2026-07-23 — Phase 5: HTTP layer. Production-gated authorization model (mirrors Telescope): `Authorize` middleware returns 404 when `truss.enabled` is off, then consults the fixed `viewTruss` gate **only in non-local** (local is open) and returns **404** on denial so the dashboard stays invisible. Shipped default gate admits `truss.authorization.allowed_emails` (`TRUSS_ALLOWED_EMAILS`), fails closed; host overrides the gate for role-based checks. New config: `middleware` (default `['web']`, supplies the auth context so the gate can see the user; the `viewTruss` guard is always appended and non-configurable) and `authorization.allowed_emails`. Routes under `truss.route_prefix` (`truss.index`, `truss.api.schema`). `IndexController` renders the `truss::index` Blade shell (endpoint + config in data-attributes, no schema inlined). `SchemaApiController`: resolves the requested connection (default when absent), 404s connections not in `managedConnections()`, filters `excluded_tables` (global + per-connection) server-side at serve time from the full cached snapshot, carries the `fallback` flag. Tested: local skips the gate, allow-list admits/denies, guest denied, 404-not-403 on denial, configured middleware present, disabled hides, JSON envelope shape, excluded tables never on the wire (non-negotiable), per-connection exclusions, unmanaged connection not visualizable.
