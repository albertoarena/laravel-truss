# Decisions

One short entry per significant choice: context, decision, trade-off. Add new entries at the bottom as the project evolves.

## Schema snapshot method

**Context:** need a reliable, accurate schema representation for the running app.
**Decision:** introspect the **live default connection** directly (the app's real database as it currently exists). A specific connection can be requested via config/UI. If no connection is reachable, fall back to replaying migrations on a fresh in-memory SQLite connection and introspecting that, with a UI banner flagging the degraded mode.
**Trade-off:** requires a migrated database to be present for full fidelity; the SQLite fallback loses native types and can break on driver-specific migrations. Both beat static AST-parsing of migration files (unreliable against loops/conditionals/raw SQL), which stays rejected.

**Fallback specifics:** the replay runs *all* migration paths the Laravel migrator resolves (app + package migrations via `loadMigrationsFrom`), not just `database/migrations`, so it matches `php artisan migrate`. Replay is graceful per-migration — a migration that fails on SQLite is skipped and recorded (surfaced in the fallback banner), never fatal; the snapshot reflects whatever replayed successfully.

## Schema introspection tool

**Context:** need to read tables, columns, indexes, and foreign keys from a live connection (and from the SQLite fallback).
**Decision:** Laravel's native schema methods (`Schema::getTables/getColumns/getIndexes/getForeignKeys`). No `doctrine/dbal`.
**Trade-off:** DBAL has marginally richer introspection for exotic edge cases, but it is a heavy extra dependency that *normalizes* native types into its own abstract type system — the opposite of the native-full-type decision. Native ships with Laravel 11+, returns native `type`/`type_name`, exposes composite index/FK columns and a primary-key flag directly, and works identically across mysql/pgsql/sqlite so the live and fallback paths share one code path. Laravel 12+ minimum means the native APIs are always available without compatibility guards.

## Frontend diagram library

**Context:** need scrollable/zoomable ER diagrams without adding a JS build step to a PHP package.
**Decision:** Mermaid.js, rendered client-side from the JSON schema endpoint.
**Trade-off:** less visually customizable than React Flow/vis-network, but zero build tooling, which matters for a Composer package that shouldn't force a frontend toolchain on consumers.

## Package name

**Context:** needed a short, memorable, collision-free name for a public Packagist package.
**Decision:** `albertoarena/laravel-truss`. A truss is a structural framework of connected members and nodes, matches the shape of an ER diagram closely. Checked against Packagist: `truss/truss` exists but is an inactive, unrelated, zero-download framework under a different vendor namespace, not a real collision.
**Rejected:** `Lattice` (taken by the established `lattice-php/lattice` React/Inertia package), `Girder` (disliked on reflection), `Mosaic`, `Compass`, `Cairn`, `Vellum` (not pursued once Truss checked out clean).

## Minimum supported versions

**Context:** how wide a compatibility matrix to support.
**Decision:** Laravel 12+, PHP 8.3+, latest only.
**Trade-off:** simpler CI matrix and code (can use newer language features freely), at the cost of excluding users on older LTS Laravel versions.

## Config scope

**Decision:** full-featured config from v1: excluded tables, route path, gate/authorization callback, cache TTL, per-connection settings, diagram styling. Not deferred to a later version.

## Package skeleton

**Decision:** fresh skeleton using `spatie/laravel-package-tools`, standard Spatie-style layout. Not reusing the `laravel-event-sourcing` skeleton, since Truss has a different shape (no event sourcing involved).

## Route architecture

**Context:** single server-rendered route vs. index page + JSON API endpoint.
**Decision:** index page + JSON endpoint (matches the index-page-and-JSON-endpoint dashboard pattern).
**Trade-off:** more moving parts up front (an API route, a loading state) versus a single server-rendered route, but makes connection switching and manual refresh trivial without a full page reload, which the config's per-connection support makes a real, not hypothetical, need.

## Asset delivery: served from the package, Mermaid vendored, no CDN

**Context:** the frontend needs to load its ES modules, a stylesheet, and Mermaid. Two axes to decide: how our own assets reach the browser (publish to `public/` vs. serve from the package) and where Mermaid comes from (CDN vs. self-hosted).
**Decision — serve assets from a gated package route.** A `GET {route_prefix}/assets/{file}` route serves the files straight from the package: zero-config (no `vendor:publish`), and the delivery mechanism becomes Pest-testable. The `{file}` param is allow-listed by basename — that mapping is also the traversal guard. The route lives **inside the gated group**, so unauthorized users get 404 on assets exactly as on the page; a public asset route would leak Truss's existence and undo the 404-on-denial decision. Assets are served with `Cache-Control: private, max-age` (gated → per-user, not shared-cacheable). The earlier `truss-assets` publish tag is removed — one delivery path.
**Decision — vendor Mermaid, self-host by default.** A verbatim `mermaid.min.js` (MIT, ~3.4 MB) ships in the package and is served from the same route, so there is no CDN dependency out of the box and a strict CSP needs only `script-src 'self'`. `diagram.mermaid_url` (`TRUSS_MERMAID_URL`) is an escape hatch: set it to load Mermaid from a CDN or a custom copy; null (default) self-hosts.
**Trade-off:** +3.4 MB in the (dev-only) package and a manual bump on Mermaid releases, in exchange for a tool that works offline, out of the box, and under CSP — which the user prioritised over package weight. Serving through PHP is slightly slower than a static file, but the assets are few, small (bar Mermaid), and browser-cached. **CSP caveat:** self-hosting fixes `script-src`, and moving the CSS out of the Blade into a served file keeps `style-src 'self'` for our own styles — but Mermaid injects a `<style>` into the rendered SVG at runtime, so a strict policy still needs `style-src 'self' 'unsafe-inline'`. That is a Mermaid limitation, documented rather than worked around.

## License & CI

**Decision:** MIT license. CI via GitHub Actions running Pest across the supported PHP/Laravel matrix.

## Documentation approach

**Decision:** three doc surfaces kept in sync: `README.md`, `docs/` (markdown), `website/src/content/docs/` (Astro Starlight, deployed to GitHub Pages). Follows the same pattern as `filament-event-sourcing` and `laravel-netsons-deploy`.

## CLAUDE.md structure

**Context:** avoiding the common failure mode of a CLAUDE.md that grows until it degrades Claude's own performance.
**Decision:** root `CLAUDE.md` stays lean (overview, pinned stack versions, exact commands, always-true conventions, pointers out). Heavy material lives in `docs/DESIGN.md`, `docs/INSTRUCTIONS.md`, `docs/DECISIONS.md`. A nested `src/Introspection/CLAUDE.md` holds rules specific to the introspection layer, loaded only when Claude is actually working in that folder.

## Table selection: server-side exclusions, client-side interaction

**Context:** `excluded_tables` filtering was described as client-side in one place and asserted server-side (never in the response body) in another.
**Decision:** split by nature. Permanent config `excluded_tables` are applied **server-side** — removed from the API response so their structure never reaches the browser. Transient interactive filter and focus are applied **client-side** on the received JSON. The cached snapshot stays the full schema; exclusions are applied at serve time, so toggling `excluded_tables` needs no rebuild.
**Trade-off:** two filtering sites instead of one, but each matches its job — server-side keeps excluded structure off the wire and the payload lean; client-side keeps interactive selection instant with no refetch. Resolves the earlier contradiction.

## "No data" boundary: column defaults are structure

**Context:** the core promise is "no data exposed, ever — never row contents." Column defaults (`default: "pending"`, `CURRENT_TIMESTAMP`) are serialized in the schema, which sits right on the fuzzy edge of that promise.
**Decision:** the boundary is **`CREATE TABLE` definition vs. table rows**. Everything in the table definition — including column defaults — is structure and is in scope. Row contents are never read or exposed. Column defaults stay in the output.
**Trade-off:** a default *can* embed a sensitive literal (a hardcoded token set as a default), which is the one place structure can leak a value that feels data-like. Judged acceptable for v1 given how rare it is and how useful defaults are. A `redact_defaults` config escape hatch is **deferred** — added only if someone asks, not built speculatively.

## Authorization: fixed gate name, app-defined callback

**Context:** should the authorization ability name be configurable, or fixed?
**Decision:** fixed `viewTruss` gate. The package ships a default definition (allow in `local` only); the host app customizes access by redefining the `viewTruss` gate in its own service provider. There is no `gate` config key — the name is not configurable.
**Trade-off:** slightly less flexible than a renamable ability, but matches the fixed-ability convention Laravel developers expect, and removes a real failure mode (a typo'd gate name silently missing → lockout or open access). The only meaningful customization — *who* may view — already lives in the gate callback, not in a name.

## Authorization: production-gated model

**Context:** Truss is meant to be safe to install in **production** and gated there, not just a local dev tool. That makes the authorization model a primary concern, not an afterthought, and raises three sub-decisions: when the gate is consulted, how a viewer is identified, and what a denial returns.
**Decision — gate only in non-local.** The `Authorize` middleware consults `viewTruss` **only when the environment is not `local`**; local is unconditionally open (subject to `truss.enabled`). This keeps local development open and avoids the gotcha of a gate accidentally locking the author out of their own local environment. Access is two independent layers, both required: `truss.enabled` (off → 404, routes act as if absent; defaults to local-only, so production is dark until `TRUSS_ENABLED=true`) and the `viewTruss` gate.
**Decision — 404 on denial, not 403.** A denied gate returns 404, so the dashboard never confirms its own existence to someone who may not view it. `enabled`-off also returns 404; the two failure modes are indistinguishable from outside.
**Decision — an env-driven email allow-list as the shipped default gate.** Truss is a package and cannot publish a gate stub into the app. So the ergonomic equivalent is config: the default `viewTruss` gate admits the emails in `truss.authorization.allowed_emails` (`TRUSS_ALLOWED_EMAILS`, comma-separated). This is the zero-code path for "gate production to these admins". It **fails closed** — an empty list admits no one in non-local. A host needing role-based logic overrides the whole gate (`fn (User $user) => $user->isAdmin()`), and the list is then unused. This softens the earlier "no authorization config" stance, but the invariant that mattered still holds: the ability *name* is fixed and the callback is app-controlled; the email list is merely the default gate's *data*, not a renamable ability.
**Decision — a configurable auth-context middleware stack (`truss.middleware`, default `['web']`).** The gate can only identify the viewer if the request first passed through session/auth middleware. Without `web` (or an equivalent), `$request->user()` is `null` in production and the default gate denies everyone — including legitimate admins. So the stack is load-bearing. It is config-driven for apps that authenticate via a custom guard or Sanctum. The fixed `viewTruss` guard is always appended after the configured stack and cannot be configured away — the config controls the auth *context*, not whether authorization runs.
**Trade-off:** more moving parts than a single gate closure (a middleware key, an allow-list key, two failure layers), but each earns its place for the production-gated use case, and every misconfiguration fails *closed* (locks down), never *open*. Guests resolve to a `null` user and are denied; the shipped default uses a nullable `$user = null` so it can still be evaluated for a guest rather than being skipped.
**Registration order:** the package defines the default only `if (! Gate::has('viewTruss'))`, but a host definition wins regardless of order — package providers boot before app providers, so a host `Gate::define('viewTruss', …)` overrides the default; the guard only avoids clobbering a host that defined it earlier (e.g. in `register()`).

## Schema data model: composite-first keys, explicit primary key

**Context:** the initial shape modelled foreign keys as single-column and had no primary-key concept (PK was only implicit via an index). Composite PKs (pivots), composite FKs, and composite unique indexes are all real.
**Decision:** model keys composite-first. `Table.primaryKey` is a `string[]` (empty when none) hoisted out of the index list; `Index` keeps `columns[] + unique` (no primary flag, since the PK is hoisted); `ForeignKey` carries `columns[]`, `referencesColumns[]`, plus `name`, `onUpdate`, `onDelete`. Per-column `PK`/`FK` badges are derived at render time from `primaryKey` and `foreign_keys[].columns`, not stored on columns.
**Trade-off:** a small transform away from the raw native output (hoisting the primary index, keeping FK actions we don't render yet), in exchange for correctly representing composite keys, a single source of truth for PK/FK, and no retrofit later. Native introspection supplies all of this directly.

## Column types: native full type, optional Laravel-style label

**Context:** introspection can only see native DB types (`varchar(255)`), not the Laravel migration verbs (`string`) that produced them. Reverse-mapping native → Laravel is inference and is lossy/ambiguous (`tinyint(1)` = boolean or small int?).
**Decision:** store and display the **native full type** as the source of truth, by default. A UI toggle offers an optional best-effort Laravel-style short label, computed in the presentation layer and never written back into the schema output. Default mode is config-driven (`diagram.type_labels`, default `native`).
**Trade-off:** native types differ across drivers and the SQLite fallback (already flagged by the fallback banner), but they are truthful and inference-free — consistent with rejecting AST-parsing for the same reason. The Laravel label is convenience only, explicitly best-effort.

## Large-schema rendering: filter + focus share one pipeline

**Context:** diagrams must stay usable at 50+ tables from v1. Rendering every table at once is both slow (dagre layout is superlinear in nodes/edges) and illegible.
**Decision:** insert a single table-selection step between the cached JSON and the Mermaid definition. Config exclusions, interactive filtering, and focus mode are all reducers over the same set, feeding the same `MermaidDefinitionGenerator`. Focus mode (a table + its FK neighbours, depth-1 default) is promoted from v2 to v1 as the primary large-schema mechanism, implemented as reduce-and-re-render (regenerate the definition over the subset) rather than dim-in-place, so dagre lays out only the neighbourhood.
**Trade-off:** focus/filter add interactive UI and state up front, but because they are one shared operation with different predicates, the marginal cost over building either alone is small — and it is what makes the tool usable on real (non-toy) schemas. Mermaid's `maxTextSize`/`maxEdges` guards are raised so large schemas render rather than error.
