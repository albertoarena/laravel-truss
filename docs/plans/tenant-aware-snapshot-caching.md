# Plan: Tenant-aware snapshot caching

Status: proposed, PAUSED pending user demand (2026-07-29). Analysis is complete and
ready to build, but the maintainer is holding off until more users ask for
multi-tenant support. Briefly promoted to Approved next, then returned to Exploring
to reflect that it is not yet committed.
Owner: Alberto Arena
Roadmap: Exploring (community requested). Revisit when demand appears.
Related: `src/Cache/SchemaCacheRepository.php`; `src/Introspection/SnapshotBuilder.php`
(the `getCurrentSchemaName()` scope); `src/Diff/BaselineStore.php`;
`src/Listeners/RebuildOnMigrationsEnded.php`; `docs/DESIGN.md` (Cache layer);
`docs/DECISIONS.md`

## Goal

Make the cached schema snapshot identify a tenant by the database it is actually
resolved against, not just by the connection name. In multi-tenant apps that swap
the database behind one connection at runtime (the common stancl/tenancy and
spatie/laravel-multitenancy pattern), Truss currently caches one snapshot per
connection name and serves it to every tenant, so tenant B can see tenant A's
schema. Keying the cache by the resolved database fixes that: each tenant gets its
own snapshot, and switching tenants shows the right structure.

Structure only, as always. This changes cache identity, not what is exposed.

## The bug, concretely

`SchemaCacheRepository::key()` today:

```php
public function key(string $connection): string
{
    return "truss:schema:{$connection}";
}
```

The key is the connection name and nothing else. But a tenancy package keeps the
connection name fixed (often `tenant`, sometimes the default connection) and
rebinds its `database` at runtime per request:

1. Request for tenant A resolves connection `tenant` to database `tenant_a`. Truss
   builds and caches the snapshot under `truss:schema:tenant`.
2. Request for tenant B resolves the same connection `tenant` to database
   `tenant_b`. The key is still `truss:schema:tenant`, so Truss returns tenant A's
   cached snapshot. Wrong schema, and a cross-tenant structure leak.

The introspection layer is already correct: `SnapshotBuilder::introspect()` scopes
to `$builder->getCurrentSchemaName()`, so a freshly built snapshot always reflects
the currently resolved database. The defect is purely in the cache identity: the
key does not carry the database the snapshot was built from.

`src/Diff/BaselineStore.php` has the identical latent bug: its file path is
`truss/baselines/{connection-slug}.json`, keyed by connection name only, so tenant
B's migration overwrites tenant A's diff baseline. See "Baseline parity" below.

## Background (grounded in the code)

- `SchemaCacheRepository` reads and writes the current snapshot per connection via
  the `Cache` facade. `get()` builds on a miss, `rebuild()` overwrites, and
  `peek()`/`has()`/`forget()` all route through `key()`. It stamps `generated_at`
  in `build()`; the introspection layer stays timestamp-free.
- `SnapshotBuilder::introspect()` already resolves per-driver via
  `getCurrentSchemaName()`: the database name on MySQL, the search-path schema on
  PostgreSQL, `main` on SQLite. This is the exact boundary that determines what is
  in the snapshot, and therefore the correct thing to key the cache on.
- `RebuildOnMigrationsEnded` rebuilds (and captures the baseline) for the affected
  connection on `MigrationsEnded`. It takes the connection name from
  `$event->options['database']` (migrate's `--database` flag names a connection),
  falling back to `managedConnections()`. It never enumerates tenants, and it does
  not need to: a migration runs against whatever database is currently resolved, so
  rebuilding "the current resolved database for this connection" is already right
  once the key is tenant-aware.
- Config in `config/truss.php` is the single source of truth, with an existing
  `cache` block (`ttl`) and a `connections` block to model any new option on.

## Design

The whole change is: the cache key (and the baseline path) gain a database
identity segment resolved from the live connection. Every operation already
resolves a connection and calls `key()`, so the surface is small and centred on
one helper.

### 1. A shared identity resolver

Introduce one place that answers "what database is this connection resolved
against right now", used by both the cache repository and the baseline store so
the two never disagree about tenant identity.

Proposed: `AlbertoArena\Truss\Support\DatabaseIdentity` (new `src/Support/`
namespace, autoloaded by the existing PSR-4 root).

```php
public function for(string $connection): string
```

- Returns a token built from **both** halves of what the snapshot actually covers:
  the physical database, `DB::connection($connection)->getDatabaseName()`, and the
  introspected schema, `Schema::connection($connection)->getCurrentSchemaName()`
  (the same value `SnapshotBuilder` scopes `getTables()` to). Combining them is the
  only universally correct identity: the database name alone misses PostgreSQL
  schema-per-tenant, and the schema name alone is the constant `main` on SQLite and
  `public` for Postgres database-per-tenant. Verified against the Laravel 12 source
  (`Schema/MySqlBuilder`, `SQLiteBuilder`, `PostgresBuilder`): both halves are
  config reads on MySQL/SQLite/PostgreSQL, with no query and no PDO, so the identity
  is cheap and a warm cache hit still never touches the database.
- Wrapped in a try/catch: if the connection cannot be resolved (misconfigured
  driver, etc.), it degrades to a stable literal (the connection name) so keying
  falls back to today's per-connection behaviour rather than throwing.
- The raw database string (a MySQL name, a SQLite absolute path, `:memory:`) is not
  safe as-is for a cache key or a filename, so the resolver returns a sanitized
  token: a slug of the database name plus a short `sha1` suffix for collision
  safety (two distinct paths that slug to the same value stay distinct). The suffix
  also keeps long SQLite paths bounded.

Keeping this in one class (rather than duplicating the logic in the cache repo and
the baseline store) is the point: tenant identity is defined once.

### 2. Cache key becomes tenant-aware

```php
public function key(string $connection): string
{
    return "truss:schema:{$connection}:{$this->identity->for($connection)}";
}
```

`get()`, `rebuild()`, `peek()`, `has()`, and `forget()` are unchanged: they
already funnel through `key()`, so they all become tenant-aware for free. The
identity resolver is injected into the repository constructor (defaulted, matching
the existing `SnapshotBuilder` default) so tests can substitute it.

One-time effect on upgrade: every app (not just multi-tenant ones) gets a new key
string, so the first request after upgrading rebuilds the snapshot once. The cache
is disposable derived data, so this is harmless, but it is noted in the changelog.

### 3. Stamp the resolved database on the envelope

The cache layer's `build()` already adds `generated_at`; have it also add the
resolved `database` name (human-readable, not the sanitized token). This stays out
of the introspection layer (which must not learn about tenancy or caching) and
gives the dashboard and `truss:show` a way to display *which* tenant database is on
screen, which matters once one connection can show many. Low cost, high clarity.

### 4. Baseline parity (schema diff)

For the fix to be coherent, the diff baseline must use the same identity, or
per-tenant diffs cross-contaminate (tenant B's migration overwrites tenant A's
baseline). `BaselineStore` already slugs the connection into its path; it should
incorporate the same `DatabaseIdentity` token:

```
truss/baselines/{connection-slug}/{database-token}.json
```

The listener (`RebuildOnMigrationsEnded`) threads the resolved identity through to
`BaselineStore::save()`, so a migration for tenant A writes A's baseline without
touching B's. This reuses the shared resolver, so it is a small, consistent
addition rather than a second mechanism.

## Open decisions for review

These are the genuine choices. My recommendation is marked; happy to change any of
them before implementation.

1. **Identity source: combine `getDatabaseName()` and `getCurrentSchemaName()`,
   rather than either alone.** Verified against the Laravel 12 source, neither is
   universally correct on its own:
   - `getDatabaseName()` distinguishes tenants by database. Covers
     database-per-tenant on every driver (including SQLite file-per-tenant, since
     each file is a distinct path), but misses PostgreSQL schema-per-tenant (one
     database, different search-path schema per tenant).
   - `getCurrentSchemaName()` distinguishes tenants by schema and matches exactly
     what `SnapshotBuilder` scopes `getTables()` to. Covers PostgreSQL
     schema-per-tenant, but on SQLite it is the constant `main` for every file, and
     on PostgreSQL database-per-tenant it is `public` for everyone, so it misses
     both of those.
   - Both are config reads (no query, no live PDO) on MySQL/SQLite/PostgreSQL:
     MySql's `getCurrentSchemaListing()` returns `getDatabaseName()`, SQLite returns
     `['main']`, Postgres parses the configured `search_path`. So the earlier
     concern that `getCurrentSchemaName()` would touch the DB on every hit does not
     hold, and combining both costs nothing extra.
   - Recommendation: a token of `{database}:{schema}`. It covers database-per-tenant,
     PostgreSQL schema-per-tenant, and SQLite file-per-tenant at once, and keeps
     warm hits DB-free. Residual gap: a package that switches tenants by a raw
     runtime `USE`/`SET search_path` without updating connection config or
     reconnecting (documented under Risks, not a mainstream pattern).

2. **Always-on vs a config toggle.** Recommendation: always-on, no new knob.
   Single-database apps get a stable identity, so their effective behaviour is
   unchanged (just a different key string). A toggle (`truss.cache.per_database`,
   default true) is easy to add if you would rather ship an escape hatch, but it is
   config surface for a strictly-more-correct default.

3. **Include the diff baseline in this change?** Recommendation: yes. Shipping a
   tenant-aware cache while leaving tenant-blind baselines would let the "Changes"
   panel show the wrong tenant's diff, which is worse than the cache bug because it
   looks authoritative. The shared resolver makes it cheap to do together. The
   alternative is to scope diff out and track it as a separate follow-up.

4. **Envelope `database` field.** Recommendation: add it (section 3). If you would
   rather keep the envelope minimal for now, it can be deferred without affecting
   the core fix.

5. **Identity token format.** Recommendation: sanitized slug plus short `sha1`
   suffix (readable in cache inspection and `truss:show`, still collision-safe and
   filesystem-safe). Alternative: a pure hash (opaque but simplest).

## TDD test plan (write failing tests first)

Cache and diff tests may use real database connections; the introspection-layer
rule about real DBs is satisfied because the snapshots are built against real
connections. Two tenants are simulated with two file-based SQLite databases and one
connection whose `database` config is swapped between them (then `DB::purge`), which
is exactly the runtime database-swap a tenancy package performs.

`tests/Unit/Support/DatabaseIdentityTest.php` (new):
- resolves distinct tokens for two different resolved databases on one connection.
- returns a stable token across repeated calls for the same database (no churn).
- degrades to a stable connection-name-based token when the connection cannot be
  resolved, without throwing.
- produces a filesystem-safe and cache-key-safe token for an absolute SQLite path
  and for `:memory:`.

`tests/Feature/Cache/SchemaCacheRepositoryTest.php` (extend):
- `key()` includes the resolved database identity; two resolved databases on the
  same connection yield different keys.
- **the leak test**: build and cache under tenant A's database, swap the
  connection to tenant B's database, and assert `get()` returns B's schema, not A's
  cached snapshot.
- `has()`/`peek()`/`forget()` all operate on the resolved-database key (forgetting
  tenant A leaves tenant B's entry intact).
- single-database app: repeated `get()` calls reuse one key (no per-request churn).
- envelope carries the resolved `database` (if decision 4 is yes).

`tests/Feature/Diff/BaselineStoreTest.php` (extend, if decision 3 is yes):
- two resolved databases on one connection store independent baselines; saving B's
  does not overwrite A's, and `get()` returns each tenant's own baseline.

`tests/Feature/Cache/RebuildTriggerTest.php` (extend):
- a migration run while tenant A's database is resolved saves A's baseline and
  rebuilds A's cache key; tenant B's baseline and cache entry are untouched.

## Docs to update in the same change (docs-in-sync rule)

- `README.md`: a short "Multi-tenancy" note explaining that Truss caches one
  snapshot per resolved database, so tenant-per-database apps show the right schema
  automatically, plus the PostgreSQL schema-per-tenant limitation if decision 1
  stands.
- `docs/DESIGN.md`: the cache key now includes a resolved-database identity segment;
  the new `DatabaseIdentity` resolver in `src/Support/`; the envelope `database`
  field; baseline path parity.
- `docs/DECISIONS.md`: record (a) cache identity is the resolved database, not the
  connection name, and why; (b) the identity source choice from decision 1 and its
  known limitation; (c) whether the diff baseline shares the identity.
- `config/truss.php`: only if a toggle is added (decision 2).
- Docs website (`albertoarena/laravel-truss-docs`, separate repo): a multi-tenancy
  guide. Lands with the next release, since the site reads from the latest release.

## Non-goals / caveats

- **No tenant enumeration.** Tenant databases are dynamic and not knowable from
  config, so Truss always shows the currently resolved tenant, never an all-tenants
  overview. `managedConnections()` and `truss:rebuild` still operate on the
  currently resolved database for a connection.
- **Runtime-only tenant switches are the residual leak.** The identity reads the
  connection's config (database and search_path). A tenancy package that switches
  tenants with a raw `USE db` or `SET search_path` on the live PDO, without updating
  the connection config or reconnecting, would not move the identity and the leak
  would remain. The mainstream packages (stancl/tenancy, spatie) reconfigure and
  purge/reconnect, so config reflects the tenant; this is documented and asserted as
  a test assumption.
- **Cardinality grows with tenant count.** One cache entry, and (if the diff
  baseline is included) one file under `truss/baselines/`, per resolved database.
  Cache entries expire on TTL; baseline files do not, so the docs note that the
  baselines directory grows with tenant count and is safe to prune.
- **One-time cache rebuild on upgrade** for every app, because the key format
  changes. Harmless (derived data), noted in the changelog.
- **Structure only.** This changes cache identity, not exposure. No row data, by
  construction.

## Rollout order

1. Pest specs for `DatabaseIdentity` (failing), then implement the resolver.
2. Extend `SchemaCacheRepositoryTest` with the leak test and key assertions
   (failing), then wire the resolver into `key()` and stamp the envelope `database`.
3. If decision 3 is yes: extend `BaselineStoreTest` and `RebuildTriggerTest`
   (failing), then thread the identity through `BaselineStore` and the listener.
4. Update `README.md`, `docs/DESIGN.md`, `docs/DECISIONS.md`, and `config/truss.php`
   (if a toggle is added).
5. `composer test` / `composer lint` / `npm test` green (Playwright unaffected),
   then commit. Changelog notes the one-time rebuild.
6. Follow-up in the docs repo: the multi-tenancy guide, landing with the release.
