# Plan: Truss as AI context

Turn Truss from "a live schema you look at" into "a live schema your coding agent
can read." The core promise is unchanged and stays the brand: give your AI agent
your real, live database structure, structure only, production safe, never row
data. This work adds the surfaces that make that real.

1. **AI-friendly export**: the same deterministic, structure-only export the CI
   command already produces, but annotated (business meaning a model cannot
   introspect), compact (token-trimmed), and focusable (one table and its
   foreign-key neighbourhood), so it is worth pasting or piping into Claude Code,
   Cursor, or any agent as grounding context. The concrete anti-hallucination
   tool: stop letting an agent invent columns, hand it the real schema.
2. **A public fluent facade** and a **frontend swap** that makes the PHP
   generators the single source of truth for the structural formats.
3. **An MCP server**: a Model Context Protocol server that exposes the live
   structure as MCP tools and a resource, so an agent queries the current schema
   on demand instead of being handed a stale paste. Read only and structure only
   by construction.

## Hard constraints

- **Structure only, never row data.** Every new surface (export flags, facade,
  route, MCP tools and resource) preserves it, proven by the canary test extended
  to cover all of them.
- **No LLM inside Truss.** Truss produces context; it never consumes it, never
  calls a model API, never needs a key. This holds for the MCP server too: it
  answers with structure, it does not reason with a model.
- **No new required runtime dependency.** `laravel/mcp` is an optional `suggest`
  dependency; MCP registration is guarded so a host without it installed is
  unaffected. Everything else is pure PHP over the existing snapshot.
- **Existing browser exports keep working** until the frontend swap replaces them
  with the gated server route; no user-visible export regression at any point.
- **Reuse the existing safeguards.** The `excluded_tables` stripping, the
  `managedConnections()` connection allow-list, and the `viewTruss` gate apply to
  every new access path, unchanged. Config stays the single source of truth.
- PHP 8.3+, Laravel 12+, minimum versions unchanged unless the optional MCP
  dependency documents its own higher floor. TDD with Pest (and Vitest /
  Playwright for client-side changes), tests first.

## Phases

The workstreams below group into four build phases. This is a build/review
grouping; the release grouping is a separate decision (a single coordinated
launch of Phases 1 to 3, with a fallback of shipping Phases 1 and 2 as an "AI
context export" release and following with the MCP server if Phase 3 slips).

- **Phase 0. Foundations.** MySQL and Postgres CI service lanes (the SQLite-only
  matrix cannot exercise native schema comments); the `laravel/mcp`
  compatibility and package-self-registration spikes; the optional `suggest`
  dependency plus a `class_exists`-guarded registration point. Status: done.
- **Phase 1. AI context export core (A, B, C, D).** Annotations, compact, focus,
  and the `llm` format plus its measurement. Status: A to D implemented.
- **Phase 2. Public programmatic surface (E, F).** The fluent facade (a permanent
  semver API) then the frontend swap (gated route, delete the duplicate JS
  generators and the cross-check).
- **Phase 3. MCP server (G).** A read-only, structure-only MCP server exposing
  the live schema. Reuses A, B, and C.

## Workstreams

### A. Annotations (config + database comments)

Types and foreign keys say what the shape is, not that `status = 1` means paid,
that `total_amount` is integer cents, or that `legacy_orders` is deprecated. That
knowledge is declared, from two sources with configurable precedence (first match
wins):

```php
'annotations' => [
    'source'  => ['config', 'database'], // drop 'database' to ignore DB comments
    'notes'   => ['All timestamps are UTC.'],
    'tables'  => ['orders' => 'One row per order, not per line item.'],
    'columns' => ['orders.status' => '0 draft, 1 paid, 2 refunded'],
],
```

The `database` source reads native schema comments, which are part of the
`CREATE TABLE` definition, not row content, so the structure-only guarantee holds
(the same boundary as column defaults). MySQL/MariaDB and Postgres expose them;
SQLite and SQL Server have none and are skipped.

An `Annotator` resolves the merged annotation set and enriches the snapshot
tables. It is pure over its inputs (config maps plus a comment map); the
comment-reading adapter (`CommentReader`) is the only DB-touching part, injected
so it can be faked in tests. An annotation for a table or column that no longer
exists is ignored, never an error.

Rendering is additive and opt-out, so an un-annotated export is byte-identical to
one produced without annotations at all: DBML column/table notes plus a project
note block; a Markdown Description column plus a global notes intro; JSON
annotation keys; a CSV annotation column; Mermaid omits them (no clean place).
`--no-annotations` (and the facade `withoutAnnotations()`) strip them everywhere.

### B. Compact mode

`--compact` (and facade `compact()`) drops the DBA detail that spends tokens
without adding meaning: column defaults and non-unique indexes (a unique index is
kept but reduced to its bare marker). It loses no table, column, or foreign key,
only verbosity. A snapshot transform applied before the generator runs, so no
generator learns about compactness.

### C. Server-side focus and depth

`--focus=<table> --depth=<n>` (and facade `focus('orders', depth: 1)`): the table
plus its foreign-key neighbours out to `n` hops. The neighbourhood is undirected,
so it is the connected diagram around the root; depth 0 is the table alone, and a
missing root is a clear error. The UI's interactive focus stays client-side (it
cannot round-trip per click), so the algorithm lives in two places, reconciled by
a shared fixture test both must satisfy rather than by a single shared function.

### D. The `llm` format, measured against compact DBML

A bespoke plaintext format: a short header with the table count and global notes,
then one line per column with pk/unique/nullability markers, foreign keys inline
with `->`, and annotations indented beneath their column. Not-null by absence (a
column is not-null unless marked `null`) for density. Deterministic and free of
any metadata that changes between runs.

Measured against annotated compact DBML: on the demo schema it is about 38%
smaller (a directional win on a small sample with a token proxy). DBML stays the
documented `truss.export.default_format` until the win is confirmed on a large
real schema with a real tokenizer and a grounding check. DBML stays first-class
regardless, because it is a standard other tools already read.

### E. Public fluent facade

Expose the immutable builder once the internal shape (A to D) is proven:

```php
Truss::snapshot()
    ->only(['orders', 'order_lines'])
    ->focus('orders', depth: 1)
    ->compact()
    ->toDbml();
```

Filters: `only()`, `except()`, `focus()`, `compact()`, `withoutAnnotations()`,
`fresh()`, `connection()`. Terminals: `toDbml()`, `toJson()`, `toCsv()`,
`toMarkdown()`, `toMermaid()`, `toLlm()`, `toArray()`. Immutable (each modifier
returns a new instance). A permanent, semver-bound public surface, which is why
it comes after the generators and filters are stable.

### F. Frontend swap (single source of truth)

Add a gated route `GET {prefix}/export/{format}` behind the `viewTruss` gate,
accepting the same filters as query params and reusing the same exclusion merge
and connection allow-list the schema endpoint uses. Rewire the dashboard download
button to it, then delete the duplicate JS structural generators and the PHP/JS
cross-check that guarded their coexistence. PNG and SVG stay in the browser (they
are canvas/DOM artifacts with no server equivalent).

### G. MCP server

A read-only, structure-only MCP server built on the first-party `laravel/mcp`
package (optional `suggest` dependency; registration guarded on
`class_exists`, so a host that does not install it is byte-identical to today).
Local stdio transport in v1 (`Mcp::local('truss', TrussSchemaServer::class)`,
started with `php artisan mcp:start truss`); the HTTP transport is a later
follow-up. The `laravel/mcp` Registrar is a singleton, so the package can
self-register from its own service provider boot; no host-side `routes/ai.php`
snippet is required.

Tools and a resource, all read-only and structure-only:

- `list_tables` -> table names with a row-less one-line summary each.
- `describe_table(table, connection?)` -> columns, primary key, indexes, foreign
  keys, and annotations for one table.
- `get_schema(format?, compact?, tables?, connection?)` -> the full export in a
  chosen structural format (reuses A to D).
- `focus_table(table, depth?, connection?)` -> the table plus its FK
  neighbourhood (reuses C).
- Resource `truss://schema` -> the compact, annotated schema as one readable
  resource.
- `get_structural_review` -> the deterministic `truss:doctor` findings, a thin
  wrapper over shipped code; built last and dropped first if the workstream runs
  long.

Every tool reads the snapshot through the existing cache repository, strips
`excluded_tables`, honours `managedConnections()`, and passes through the same
structure-only serialisation, so an excluded table can never surface. The canary
test drives every tool and the resource and asserts no row data appears.

## Testing strategy

- Per-format golden tests for annotations, compact, focus, and `llm`.
- Annotations: config source in every supporting format; DB-comment source per
  driver (MySQL and Postgres, on their CI lanes); precedence both ways;
  `--no-annotations` strips everywhere; missing table/column annotation ignored;
  quotes and newlines in an annotation do not break DBML or CSV.
- Focus: depth 0, 1, 2; a table with no FKs; a missing table raises; a
  self-referencing FK; a composite PK; and the shared fixture the JS interactive
  focus must also satisfy.
- Compact: no defaults or non-unique indexes in the output; compact and full
  describe the same tables, columns, and foreign keys.
- Determinism, extended to every new path: run twice, assert byte-identical;
  shuffle input order, assert identical output.
- The canary/guarantee test, extended: seed a fixture row with a sentinel, then
  exercise every export format, the facade terminals, the gated route, and every
  MCP tool and the resource, and assert the sentinel appears in none.
- Facade: each filter and terminal; immutability.
- Route (F): gated (denied `viewTruss` is a 404), honours `excluded_tables` and
  `managedConnections()`, and produces output byte-identical to the command.
- MCP (G): each tool's input-schema validation; excluded tables never returned;
  an unmanaged `connection` argument rejected; local stdio transport works
  against the fixture app.

## Docs

- A "Using Truss as AI context" page: the export flags, the facade, and the
  honesty notes (a schema is not a semantic layer; if you feed schema context to
  a tool that executes generated SQL, that tool needs a read-only connection and
  its own validation, which Truss does not and will not provide).
- An "MCP server" page: install, the local stdio setup with a copy-paste client
  config for Claude Code and Cursor, the tool/resource reference, and the
  structure-only guarantee restated.
- `docs/DECISIONS.md`, the README export section, and the CHANGELOG updated with
  each user-visible workstream.

## Acceptance criteria

- Annotations from config and from DB comments both appear, with documented
  precedence, and `--no-annotations` strips them.
- `--compact` measurably reduces size and loses no table, column, or foreign key.
- `--focus`/`--depth` return a correct neighbourhood and agree with the JS focus
  on the shared fixture.
- The recommended AI default format is chosen by a recorded measurement, not by
  assertion.
- The public facade produces output byte-identical to the command for the same
  filters, and is immutable.
- The dashboard download is served by the gated PHP route; the duplicate JS
  structural generators are gone; the browser export UX is unchanged for the user.
- An MCP client can, over local stdio, list tables, describe a table, get the
  schema in a chosen format, and focus a table, all structure only; an excluded
  table never appears through any tool or the resource.
- No export, facade call, route response, MCP tool result, or resource read, in
  any format or flag combination, contains row data.
