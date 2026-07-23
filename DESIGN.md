# Design

## Purpose

Truss gives a Laravel app a live, always-current view of its database structure, in the app, without ever touching row data. It's built for the moment you're staring at an unfamiliar codebase (or your own, six months later) and want to see how the tables actually connect, without opening a DB client or reverse-engineering migrations by hand.

## Architecture Overview

Truss has three layers, kept deliberately separate so each is testable in isolation.

### 1. Snapshot builder (`src/Introspection/`)

The core of the package. Given the app's migrations, it produces a plain, serializable schema representation.

**How it works:**
1. Resolve the target connection — the app's **default** database connection unless a specific one is requested (see Config below).
2. Introspect that live connection's current schema: tables, columns (name, type, nullable, default), indexes, and foreign keys.
3. Serialize into a plain array/JSON structure.

**Fallback (no connection available):** if no database connection is reachable, replay migrations against a fresh in-memory SQLite connection and introspect *that* instead. Replay **all migration paths the Laravel migrator knows about** (the app's `database/migrations` *and* package migrations registered via `loadMigrationsFrom`), resolved through the migrator rather than a hardcoded directory — so the fallback matches what `php artisan migrate` would actually produce. This is a degraded mode: SQLite storage classes replace native types, and driver-specific migrations may not replay cleanly. Replay is **graceful per-migration** — a migration that fails on SQLite is skipped and recorded, not fatal, and the snapshot is built from whatever replayed successfully. The UI surfaces a banner: *"A database connection is not available; SQLite fallback used"* and lists any skipped migrations. The structure-only guarantee holds in both modes.

Reading the live schema is preferred over static AST-parsing of migration files (which contain loops, conditionals, and raw SQL that are unreliable to parse statically) and over always replaying on SQLite (which loses native types and breaks on driver-specific migrations). The SQLite replay survives only as the no-connection fallback.

**Output shape (indicative):**

```json
{
  "connection": "mysql",
  "generated_at": "2026-07-22T10:00:00Z",
  "tables": [
    {
      "name": "posts",
      "columns": [
        { "name": "id", "type": "bigint unsigned", "nullable": false, "default": null },
        { "name": "user_id", "type": "bigint unsigned", "nullable": false, "default": null },
        { "name": "title", "type": "varchar(255)", "nullable": false, "default": null }
      ],
      "primary_key": ["id"],
      "indexes": [
        { "name": "posts_user_id_index", "columns": ["user_id"], "unique": false }
      ],
      "foreign_keys": [
        {
          "name": "posts_user_id_foreign",
          "columns": ["user_id"],
          "references_table": "users",
          "references_columns": ["id"],
          "on_update": "no action",
          "on_delete": "cascade"
        }
      ]
    }
  ]
}
```

Keys are modelled composite-first. `primary_key`, each index's `columns`, and a foreign key's `columns`/`references_columns` are all arrays, so composite primary keys (`"primary_key": ["post_id", "tag_id"]` on a pivot), composite indexes, and composite foreign keys are first-class. The primary key lives at table level, not in `indexes` (which holds only non-primary indexes); a table with no primary key serialises `"primary_key": []`. Per-column `PK`/`FK` badges in the diagram are **derived** by the generator from `primary_key` and `foreign_keys[].columns` — not stored redundantly on each column. Foreign-key referential actions (`on_update`/`on_delete`) are structure, not data, and are carried for display.

`type` is the **native full type** exactly as the database reports it (`varchar(255)`, `bigint unsigned`, `timestamp`) — the source of truth, never a reverse-mapped Laravel migration verb. Mapping a native type back to a Laravel-style name (`string`, `integer`) is inference, is lossy, and is therefore *not* done here; it is an optional presentation-layer label (see Frontend).

This layer has no knowledge of HTTP, Blade, caching, or Mermaid. It is a pure function: connection in, schema representation out. See `src/Introspection/CLAUDE.md` for the rules that keep it that way.

### 2. Cache & rebuild trigger (`src/Cache/`, service provider)

- The snapshot is stored via Laravel's `Cache` facade, keyed per connection, with a configurable TTL.
- Rebuilds are triggered automatically by listening to `Illuminate\Database\Events\MigrationsEnded`, fired after `migrate`, `migrate:rollback`, and `migrate:fresh`.
- A manual `php artisan truss:rebuild` command is available for CI, seeding workflows, or forcing a refresh.
- No database table is used for storage. This is disposable, derived data.

### 3. HTTP layer (`src/Http/`)

Two routes, matching the "index page + JSON endpoint" pattern used by Telescope/Horizon-style dashboards:

- `GET {route_prefix}` — renders the Blade shell (layout, connection switcher, container for the diagram).
- `GET {route_prefix}/api/schema?connection=...` — returns the cached schema JSON for the requested connection, with config `excluded_tables` filtered out **server-side** so they never reach the client. The frontend fetches from this endpoint and builds the Mermaid `erDiagram` definition client-side, applying interactive filter/focus to what it received.

Both routes sit behind a **fixed `viewTruss` gate** (same pattern as Telescope's `viewTelescope`). The package ships a default definition that allows access in `local` only; the host app customizes *who* may view by redefining the `viewTruss` gate in its own service provider. The ability name is not configurable — only its callback is, and that lives in the app, not in config.

#### Authorization model

Truss is designed to be safe to install in **production** and gated there — not merely a local-only tool. Access is guarded by two independent layers, enforced by the `Authorize` middleware, plus the auth-context middleware that runs ahead of it:

1. **`truss.enabled`** — when off, the routes respond **404**, behaving as if they do not exist. Defaults to local-only, so a production deploy is dark until you set `TRUSS_ENABLED=true`. This is the deploy switch: "does the dashboard exist in this environment at all".
2. **The `viewTruss` gate** — the access control: "who may view". It is consulted **only in non-local** environments (local is unconditionally open, mirroring Telescope). A denial returns **404**, not 403, so the dashboard never confirms it exists to someone who may not see it.

`enabled` alone does not grant access, and the gate alone does not make the routes exist. To expose Truss in a non-local environment (staging or production), a host must **both** set `TRUSS_ENABLED=true` *and* authorize the viewers.

**Establishing the auth context.** The gate can only identify the viewer if the request has already passed through session/auth middleware. The `truss.middleware` config key (default `['web']`) supplies that stack; the fixed `viewTruss` guard is always appended after it and cannot be configured away. Without this, `$request->user()` would be `null` in production and the gate would deny *everyone*, including the admins who should have access — so the middleware stack is load-bearing, not cosmetic. Apps that authenticate differently (a custom guard, Sanctum for an SPA) swap the stack here.

**Authorizing specific users — the zero-code path.** For the common "let these admins in" case, the shipped default `viewTruss` gate admits the emails in `truss.authorization.allowed_emails`, set via `TRUSS_ALLOWED_EMAILS` as a comma-separated list:

```dotenv
TRUSS_ENABLED=true
TRUSS_ALLOWED_EMAILS="ada@example.com,grace@example.com"
```

No gate code is needed. The list is ignored in local (the gate is not consulted there) and **fails closed** — an empty list means no one may view in non-local until emails are added or the gate is overridden. A guest resolves to a `null` user and is denied.

**Authorizing by role — override the gate.** When email lists aren't enough (e.g. a role or permission check), the host defines its own `viewTruss` gate in a service provider, exactly as with Telescope. A host definition fully replaces the default and the allow-list is then unused:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewTruss', fn (User $user) => $user->isAdmin());
```

The host definition always wins (the package registers its default only if the app has not already defined the gate, and a later app definition overrides it regardless of order). The ability *name* is fixed — only the callback varies, and that lives in the app. See `DECISIONS.md` → *Authorization: production-gated, Telescope-mirroring model*.

## Frontend

- Mermaid.js renders the ER diagram from the JSON schema, no build step required.
- Container wraps the resulting SVG with `overflow: auto` plus a small pan/zoom script (zoom via CSS `transform: scale()`, never a re-layout) for scrolling and zooming on large diagrams.
- **Selection pipeline.** Table selection happens in two places, split by nature:
  - *Config `excluded_tables`* — applied **server-side**: removed from the API response entirely, so their structure never reaches the browser (kept out of the payload, not hidden via CSS). The cached snapshot stays the full schema; exclusions are applied when serving, so toggling `excluded_tables` needs no rebuild.
  - *Filter and focus* — applied **client-side** on the received JSON, both feeding the same `MermaidDefinitionGenerator`. Filter (text search / table multiselect) and focus mode (a table plus its foreign-key neighbours, depth 1, configurable) are the same operation with different predicates, and they compose: focus operates on whatever the filter left in.

  The rule: permanent exclusions are server-side (never sent), transient interactive selection is client-side (no refetch).
- **Type label toggle.** Columns show the native full type by default (`varchar(255)`, `bigint unsigned`). A toggle switches to a best-effort Laravel-style short label (`string`, `integer`), computed here in the presentation layer from the native type — clearly lossy, never fed back into the schema output. The default mode is config-driven (`diagram.type_labels`).
- **Large-schema support (50+ tables) from day one.** Focus mode is the primary answer — reducing to a table and its neighbours keeps both layout time and legibility under control. Mermaid's `maxTextSize`/`maxEdges` guards are raised so large schemas render instead of erroring; a soft warning appears above a configurable table threshold.
- Connection switcher re-fetches from the JSON endpoint and re-renders, no full page reload.
- When the snapshot was built via the SQLite fallback, a banner states the connection was unavailable and native types may be approximate.

## Config reference (`config/truss.php`)

| Key | Purpose |
|---|---|
| `route_prefix` | URL prefix for the index page and API endpoint |
| `enabled` | Global on/off switch, expected to default to `env('APP_ENV') === 'local'` |
| `cache.ttl` | How long a schema snapshot is cached before being considered stale |
| `connections` | Which configured DB connections are visualizable, and any per-connection overrides. Defaults to the app's default connection when unset |
| `excluded_tables` | Tables hidden by default (e.g. `sessions`, `jobs`, `cache`, `cache_locks`), toggleable |
| `diagram` | Styling options passed through to the Mermaid theme (colors, font, spacing) |
| `diagram.type_labels` | Default column-type label mode: `native` (full DB type, default) or `laravel` (best-effort short label); user-toggleable in the UI |
| `focus.default_depth` | Foreign-key neighbour depth shown when focusing a table (default `1`) |
| `large_schema.warn_above` | Table count above which the UI shows a "large schema — use focus/filter" warning |

## Out of scope for v1

- **Semantic relationship labels from Eloquent models** — inferring "author" instead of `user_id → users.id` by scanning model relationship methods. Nice-to-have, not required since foreign keys already produce a correct diagram.

_Focus mode was originally deferred to v2 but is now v1: it is the primary large-schema legibility and performance mechanism, and it shares the selection pipeline with filtering, so the marginal cost is small._

## Directory structure

```
├── CLAUDE.md
├── README.md
├── LICENSE
├── composer.json
├── config/
│   └── truss.php
├── docs/
│   ├── DESIGN.md
│   ├── INSTRUCTIONS.md
│   └── DECISIONS.md
├── website/                        # Astro + Starlight docs site
├── src/
│   ├── TrussServiceProvider.php
│   ├── Introspection/               # pure schema-building logic
│   │   ├── CLAUDE.md
│   │   ├── SnapshotBuilder.php
│   │   ├── SchemaSerializer.php
│   │   └── Data/                    # value objects: Table, Column, ForeignKey, Index
│   ├── Cache/
│   │   └── SchemaCacheRepository.php
│   ├── Commands/
│   │   └── RebuildCommand.php       # truss:rebuild
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── IndexController.php
│   │   │   └── SchemaApiController.php
│   │   └── Middleware/
│   │       └── Authorize.php
│   └── Listeners/
│       └── RebuildOnMigrationsEnded.php
├── resources/
│   ├── views/
│   │   └── index.blade.php          # Blade shell (toolbar, banners, viewport)
│   └── js/                          # client-side, no build step; published as assets
│       ├── truss.js                 # browser entry: fetch → select → Mermaid render
│       ├── mermaid-definition.js    # schema subset → erDiagram string (the generator)
│       ├── selection.js             # filter + focus reducers
│       └── type-labels.js           # native → Laravel-style short label
└── tests/
    ├── TestCase.php
    ├── Unit/
    │   ├── Introspection/
    │   └── Cache/
    ├── Feature/
    │   └── Http/                     # IndexRoute, SchemaApi, Authorization
    ├── js/                           # Vitest unit tests for resources/js
    └── e2e/                          # Playwright browser tests (harness + specs)
```

> Note: the Mermaid definition generator is **client-side JavaScript**
> (`resources/js/mermaid-definition.js`), not PHP — the interactive
> filter/focus/label pipeline runs in the browser with no refetch, so generation
> must live there too. Pure logic is unit-tested with Vitest; rendering and
> interaction are covered by Playwright.
