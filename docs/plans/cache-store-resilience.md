# Plan: cache-store resilience

Status: planned, not started. Discussed and locked on 2026-08-17.
Owner: Alberto Arena
Reported: [#51](https://github.com/albertoarena/laravel-truss/issues/51) by @HafizMMoaz
Roadmap: bug fix, no roadmap card.
Related: `src/Cache/SchemaCacheRepository.php`; `src/Listeners/RebuildOnMigrationsEnded.php`;
`src/Http/Controllers/SchemaApiController.php`; `src/Commands/{Rebuild,Show,Doctor,Diff,Export}Command.php`;
`src/Diff/BaselineStore.php` (the pattern this copies); `resources/js/truss.js` (the banner);
`docs/DECISIONS.md` (Schema diff: durable baseline on disk).

An unusable cache store must never crash the host application's `php artisan migrate`,
and must never take the dashboard down. Today it does both.

---

## The bug, as verified

Reproduced locally on 2026-08-17 with a throwaway Pest test, `cache.default` set to
`database` against a database with no `cache` table:

1. **`migrate` reports failure after succeeding.** `MigrationsEnded` fires, the listener
   calls `peek()`, `Cache::get` throws `QueryException`, and nothing in the chain catches
   it. Laravel does not swallow listener exceptions, so the Artisan command exits
   non-zero even though every migration already committed.
   Trace: `SchemaCacheRepository.php:79` from `RebuildOnMigrationsEnded.php:48`.
2. **The schema endpoint 500s.** `SchemaApiController.php:51` calls `get()`, and
   `Cache::remember` throws the same exception.
   Trace: `SchemaCacheRepository.php:39` from `SchemaApiController.php:51`.

Two clarifications on top of the report:

- **`/truss` itself returns 200.** `IndexController` does inject
  `SchemaCacheRepository`, which is why this looks wrong at a glance, but the only method
  it calls is `managedConnections()`, which reads `truss.connections` and falls back to
  `database.default`. It never reaches the store. The failure is the dashboard's `fetch`
  of `/truss/api/schema`, so the shell loads and then the diagram fails. The fix
  therefore needs a client-side state, not only a server-side status code.
- **Stock `php artisan migrate` does not break.** The default Laravel batch includes
  `create_cache_table`, and `MigrationsEnded` fires after the whole run, so the table
  exists by the time the listener reads it. The same is true of `migrate:fresh`.

The triggers that do reach it:

- `php artisan migrate:rollback` on a stock app, which rolls back the batch containing
  the `cache` table and then fires the event against a store that no longer exists.
  (Deduced from the event ordering. Verify this end to end during implementation.)
- A partial batch, `migrate --path=...`, run before the `cache` table exists.
- Modular or multi-connection setups where the cache store's connection is not the one
  being migrated.
- **Redis or Memcached unreachable.** Same bug class, and the one that can break a
  production deploy step running `migrate --force`.

Reach is wider than the two reported entry points. Ten files reference the repository and
nine call a method on it (`TrussManager` only injects it and hands it to `ExportBuilder`),
but the count that matters is the **seven files containing a call that touches the store**
(`get`, `rebuild`, `peek`): `SchemaApiController`, `RebuildOnMigrationsEnded`,
`ShowCommand`, `DoctorCommand`, `DiffCommand`, `RebuildCommand`, `ExportBuilder`. The
remaining two, `IndexController` and `ExportCommand`, call only `managedConnections()`,
which reads config, so they cannot fail this way (`truss:export` still can, because its
read happens inside `ExportBuilder`).

As user-facing surfaces that is the schema endpoint, `truss:show`, `truss:diff`,
`truss:doctor`, `truss:rebuild`, `truss:export`, the `Truss` facade, the MCP tools, and
`php artisan migrate`. The MCP tools catch `InvalidArgumentException` only, so a store
exception escapes them as well. The write path (`Cache::put` / `Cache::forever` in
`rebuild()`) is exposed too, not only reads.

## Precedent: this policy already exists twice

This is not a new policy, it is a missing application of an existing one.

- **Database unreachable:** `SnapshotBuilder` replays migrations into a disposable
  in-memory SQLite connection and returns `fallback: true`, which the dashboard shows as
  a banner and a footer stat. Degrade to a real answer, and say so.
- **Disk unreadable:** `BaselineStore::attempt()` degrades every operation to a fallback
  value and records `lastError()`, which `SchemaApiController` turns into
  `diff_unavailable`. Its docblock (lines 96 to 107) describes precisely this failure
  mode, including the `migrate` crash, for the filesystem.

The cache is the third external dependency and the only one with no handling at all.

## Locked decisions (2026-08-17)

1. **Reads build uncached and signal it.** A broken cache store degrades to a live
   introspection, not to an error page. No cap on schema size: a slow dashboard beats a
   dark one, and it is one build per request, which is exactly what `truss:rebuild`
   does.
2. **The repository is the seam, the callers decide how to respond.**
   `SchemaCacheRepository` catches and records; each caller chooses what that means. A
   central catch alone would turn `truss:rebuild` into a silent no-op, and a
   listener-plus-API fix would leave five store-touching files (seven user-facing
   surfaces) still able to crash.
3. **Log levels:** `warning` in the listener (rare, and it explains a skipped rebuild),
   `debug` on the read path (a banner is already telling a human, and a per-request
   warning would flood logs while Redis is down).
4. **No new config keys.** Nothing here is a preference worth exposing.
5. **The signal reuses the existing conventions:** a `cache_unavailable` flag beside
   `diff_unavailable`, and the banner treatment already used by `fallback`.

## Design

### `SchemaCacheRepository`

Add a private `attempt()` helper and a public `lastError()`, both copied in shape from
`BaselineStore`, catching `Throwable` for the same reason it does: a database store
throws `QueryException`, Redis throws its own connection exceptions, an unconfigured
store name throws `InvalidArgumentException`, and all three mean one thing here.

| Method | Today | After |
| --- | --- | --- |
| `get()` | `Cache::remember` | Read, and on a miss or a failure build the snapshot. Attempt to store it, ignoring a write failure. Always returns a usable snapshot. |
| `rebuild()` | build, then `Cache::put`/`forever` | Unchanged build, then an attempted write. Returns the fresh snapshot even when the write failed, and records the error for the caller. |
| `peek()` | `Cache::get` | `null` on failure, which is the same answer as "nothing cached yet", and records the error. |
| `has()` | `Cache::has` | `false` on failure, records the error. |
| `forget()` | `Cache::forget`, returns `void` | Swallows, records the error. Return `bool` to match `BaselineStore::forget()`. Widening `void` to `bool` breaks no caller. |

Do **not** keep `Cache::remember` in `get()`. `remember` both reads and writes, so a
failure inside it is ambiguous, and recovering by building again can mean building
twice. Splitting it into an explicit read, build, attempted write makes each failure
distinct and keeps the work to one build. The `ttl <= 0` forever branch stays as it is.
Behaviour is otherwise identical, since the cached value is always an array, so a
`Cache::get` returning `null` is unambiguously a miss.

`lastError()` is per instance, and the repository is not bound as a singleton, so each
caller must read `lastError()` from the instance it called. That is already how every
call site is written (`$this->cache`, or the injected `$cache` in a command).

### Per-caller posture

| Caller | Behaviour on a cache failure |
| --- | --- |
| `RebuildOnMigrationsEnded` | **Never throws, as an invariant.** Skip the baseline, attempt the rebuild, `Log::warning` once per affected connection, carry on to the next connection. |
| `SchemaApiController` | 200 with the live snapshot plus `cache_unavailable: true`. Never 500, never 503. |
| `truss:rebuild` | Builds, attempts to cache, and **exits non-zero** with the store's error message when it could not. It is the documented escape hatch and it has one job. |
| `truss:show`, `truss:doctor`, `truss:diff` | Print a warning line, still exit 0. They have a usable snapshot. |
| `truss:export` | Warning on stderr. The exit code stays driven purely by drift, so `--check` in CI never goes red because of an unrelated cache outage. |
| `ExportBuilder` / `Truss` facade, MCP tools | Nothing to add. They inherit a working `get()`. Worth noting the MCP tools catch `InvalidArgumentException` only, so today a store exception escapes them. |
| `IndexController` | No change. It calls `managedConnections()` only, which reads config, so it already returns 200 on a broken store. |

The listener invariant needs its own `try`/`catch(Throwable)` around the per-connection
work, not just a guarded cache layer: `SnapshotBuilder` handles connectivity failures
but is not the only thing that can throw, and the promise being made is about
`php artisan migrate`, whatever the cause.

### Client side

`state.cacheUnavailable` from `payload.cache_unavailable`, and a banner using the same
treatment as the `fallback` banner: "Schema built live, the cache store is unavailable."
Coordinate with PR #41 (accessibility), which touches the same banner region in
`resources/js/truss.js`, to avoid a rebase conflict.

## Tests, in TDD order

The whole suite forces `cache.default = 'array'` in `TestCase::defineEnvironment`, so
nothing today can see this bug. Two new PHP lanes, plus the frontend test the frontend
rules require:

1. **A throwing store, driver agnostic.** `tests/Support/BrokenCacheStore.php`, an
   `Illuminate\Contracts\Cache\Store` that throws on `get`, `many`, `put`, `forever` and
   `forget`, registered via `Cache::extend`. Covers both directions and stands in for a
   dead Redis. Assertions: the `MigrationsEnded` event does not throw; the API returns
   200 with `cache_unavailable` and a full table list; `truss:rebuild` exits non-zero;
   `truss:show` exits 0 with a warning; `peek()`, `has()` and `forget()` degrade.
2. **The reported scenario, literally.** `cache.default = 'database'` against a database
   with no `cache` table, asserting the two failures from the issue are gone. This is
   the regression test that maps one to one onto #51.
3. **The banner** (`tests/e2e/`, Playwright, per the frontend rules): a payload carrying
   `cache_unavailable` renders the notice.

Every one of these must be seen failing first.

## Docs to update in the same change

- `docs/DECISIONS.md`: a new entry, "Cache-store failures degrade, they do not crash",
  placed next to "Schema diff: durable baseline on disk" and pointing at it as the
  precedent.
- `docs/DESIGN.md`: section 2 (cache and rebuild trigger) gains the degradation rule;
  the section 3 endpoint description gains `cache_unavailable` beside `diff_unavailable`.
- `README.md`: one sentence in the snapshot/disk area (around the existing baseline
  paragraph) saying an unusable cache store degrades to a live read rather than an
  error, and that `truss:rebuild` still reports the failure.
- `CHANGELOG.md`: fixed entry crediting @HafizMMoaz.
- The docs site (`albertoarena/laravel-truss-docs`) after the release ships, since it
  reads from the latest package release.

## Definition of done

- [ ] Both PHP lanes and the Playwright test written first, seen red, then green.
- [ ] `composer test`, `composer lint`, `npm test` and `npx playwright test` all clean.
- [ ] `migrate` and `migrate:rollback` verified by hand against `CACHE_STORE=database`
      with no `cache` table, plus one run with the cache store pointed at a dead Redis.
- [ ] All five docs surfaces above updated in the same PR (the docs site follows the
      release).
- [ ] Reporter answered on #51, and told again when it ships.

## Release slot

**v1.8.4, ahead of v1.9.0** (decided 2026-08-17). It is a crash fix that can fail a
deploy, it is independent of the accessibility branch, and it keeps v1.9.0's notes and
announcement single-purpose. Two releases land close together as a result, and the
release-then-docs ordering rule applies to both: tag and publish the package first, then
push the docs repo, or the demo re-fetches the previous release.

The version is now public: it is named in the reply on #51, so it is a commitment to the
reporter, not just a preference.

## Out of scope

- Any new config knob for cache behaviour.
- Retry or circuit-breaker logic around the cache store. Truss reports and degrades, it
  does not manage someone else's infrastructure.
- Changing where the snapshot lives. It stays derived, disposable, cache-resident state.
