# CLAUDE.md — Introspection layer

Rules for everything under `src/Introspection/`. Loaded only when Claude is working in this folder, on top of the root `CLAUDE.md`.

- **No framework awareness.** Nothing here may reference `Illuminate\Http\*`, Blade, `Cache`, routes, or Mermaid. This layer takes a database connection (or, for the fallback, migrations) in and returns a plain schema representation out. Database access — connections and the schema builder — is expected here; the ban is on the web/render stack. If a class here needs to know it's running inside a web request, it belongs somewhere else.
- **Pure and deterministic.** Given the same migrations, `SnapshotBuilder` must produce the same output every time. No timestamps, random IDs, or environment-dependent values inside the serialized schema itself (the `generated_at` field is added by the caching layer, not by this layer).
- **Value objects, not arrays, internally.** `Table`, `Column`, `Index`, `ForeignKey` are typed value objects. Serialization to array/JSON happens at the boundary (`SchemaSerializer`), not scattered through the builder.
- **Native types only, no normalization.** `Column.type` is the native full type exactly as the database reports it (`varchar(255)`, `bigint unsigned`). Never reverse-map it to a Laravel migration verb (`string`, `integer`) — that is inference, it is lossy, and it belongs in the presentation layer as an optional label, not here.
- **Every test runs against a real database connection with real migrations.** No mocking the schema-introspection APIs. SQLite in-memory is fine as the test connection; the point is that this layer is correct against real schema behaviour, and mocking it away defeats that. Cover both paths: introspecting a live connection (primary) and the SQLite migration-replay fallback.
- **TDD, no exceptions.** A failing Pest test with a fixture migration comes before any implementation change here.
