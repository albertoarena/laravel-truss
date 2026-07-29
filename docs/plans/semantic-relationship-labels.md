# Plan: Semantic relationship labels

Status: proposed, decisions locked 2026-07-29, ready to build when scheduled
Owner: Alberto Arena
Roadmap: Exploring, next investigation focus (2026-07-29)
Related: `resources/js/mermaid-definition.js` (edge generation); `src/Introspection/`
(pure DB layer, must stay untouched by this); `src/Cache/SchemaCacheRepository.php`
(envelope assembly); `src/Http/Controllers/SchemaApiController.php` (delivery);
`docs/DESIGN.md`; `docs/DECISIONS.md`; `.claude/rules/introspection.md`,
`.claude/rules/frontend.md`

## Goal

Label diagram edges with the Eloquent relationship they represent (`hasMany`,
`belongsTo`, `belongsToMany`, `morphMany`, and so on), and draw edges that the raw
foreign keys do not reveal. Today every edge in the diagram is derived from a
database foreign key. Eloquent knows relationships the database often does not, so
reading the models turns a structural picture into a semantic one.

Structure only, as always: this reads relationship *definitions* (which tables
relate, by which keys, of which type), never row data, and never executes a
relationship query.

## Why this is worth doing (grounded in how edges work now)

`resources/js/mermaid-definition.js` builds every edge from `table.foreign_keys`:

```js
// parent ||--o{ child : "constraint"
relationships.push(`  ${fk.references_table} ||--o{ ${table.name} : "${label}"`);
```

Two consequences:

1. **No FK constraints means no edges.** A large share of Laravel apps never add
   database-level foreign keys (they rely on Eloquent and application logic). For
   those apps the diagram today is a set of disconnected tables, even though the
   domain is full of relationships. Reading Eloquent recovers them. This is the
   single biggest reason the feature is valuable, more than the labels themselves.
2. **Every edge is the same shape (`||--o{`) and labelled with the constraint
   name.** Eloquent adds meaning a foreign key cannot express: direction
   (`belongsTo` vs `hasMany`), cardinality (`hasOne` vs `hasMany`), many-to-many
   (`belongsToMany`, where the pivot can be collapsed into one edge), and
   polymorphism (`morphMany`). That turns `orders ||--o{ order_items` into a
   labelled, directional, correctly-cardinal edge.

## Architectural constraint: this cannot live in the introspection layer

`src/Introspection/` is DB-only by rule (`.claude/rules/introspection.md`): no app
awareness, pure and deterministic given the same migrations. Reading Eloquent
models is the opposite of that: it boots application classes, depends on app code,
and is not deterministic from migrations alone. So relationship reading is a **new,
separate layer**, and the introspection layer stays exactly as it is.

Proposed namespace: `src/Relationships/` (autoloaded by the existing PSR-4 root).

## Design

### 1. Model discovery: `RelationshipModelLocator`

Find the app's Eloquent model classes to inspect.

- Default: scan `app_path('Models')` (the Laravel 8+ convention). Derive the FQCN
  from the app's PSR-4 prefix, `class_exists`, reflect, and keep classes that are
  concrete (not abstract) subclasses of `Illuminate\Database\Eloquent\Model`.
- Config-overridable: `truss.relationships.paths` (one or more directories) and/or
  `truss.relationships.models` (an explicit class list) for apps that keep models
  elsewhere or want an exact set. An explicit list short-circuits scanning.
- Never autoloads the world: only the configured paths are walked, and each file is
  resolved to a single expected class, so discovery cost is bounded by model count.

### 2. Relationship extraction: `RelationshipMapper` (no queries, ever)

For each discovered model, produce its relationships without touching the database:

- Consider public methods **declared on the model class itself** (not inherited
  from the base `Model`) that take **zero required parameters**.
- Detect a relationship two ways, preferring the cheap and safe one:
  - if the method has a return type that is a `Relation` subclass, it is a
    relationship (no invocation needed);
  - otherwise invoke it inside a `try/catch` and keep it only if the result is an
    `instanceof Illuminate\Database\Eloquent\Relations\Relation`. Invocation builds
    the relation object but **must never call `->get()`/`->first()`/any executor**,
    so no query runs. Any throwing method is skipped, never fatal.
- From each `Relation`, read metadata only:
  - `type` = the relation class basename (`HasMany`, `BelongsTo`, `BelongsToMany`,
    `MorphMany`, `MorphToMany`, `HasManyThrough`, ...).
  - `from_table` = `$model->getTable()`, `to_table` = `$relation->getRelated()->getTable()`.
  - keys per relation type via the getters each exposes (foreign key, owner/local
    key); for `BelongsToMany`, also the pivot table and the two pivot keys.
  - `method` = the method name (the label users recognize).

Output is a plain, ordered list, one entry per relationship:

```
{ from_table, to_table, type, method, from_model, to_model,
  keys: { ... }, pivot_table?, connection }
```

The mapper is defensive by construction: a single bad model or method degrades to
"that relationship is missing", never to a broken map or a thrown request.

### 3. Delivery: a new `relationships` field on the snapshot envelope

The pure snapshot is built by `SnapshotBuilder` and must stay pure, so relationships
are **not** added there. Instead they are assembled at the same orchestration point
that already stamps envelope fields (`generated_at`, and, from the tenant-aware
plan, `database`): the cache/serve layer attaches a top-level `relationships: [...]`
array beside `tables`. `SchemaApiController` passes it through, exclusion-filtered so
a relationship pointing at an excluded table is dropped (mirroring how the diff is
exclusion-consistent).

### 4. Rendering: extend `mermaid-definition.js` (client-side, per the frontend rule)

Edge generation stays in the browser. Given `relationships`, the generator:

- **labels** edges with `method` (and/or `type`) instead of the FK constraint name;
- **maps cardinality** to Mermaid: `belongsTo`/`hasOne` to a one-to-one style edge,
  `hasMany`/`morphMany` to `||--o{`, `belongsToMany`/`morphToMany` to a
  many-to-many edge (`}o--o{`);
- **collapses pure pivots** for `belongsToMany`: when the pivot table carries only
  the two foreign keys (plus optional timestamps), draw a single many-to-many edge
  between the two models and hide the pivot entity. A pivot that carries its own real
  columns (for example a `subscriptions` pivot with `status`, `expires_at`) is a
  meaningful entity and stays visible with normal edges;
- keeps the existing subset rule (an edge is emitted only when both endpoints are in
  the selected subset) and the self-reference-as-note behaviour.

FK-derived edges remain the fallback when relationships are absent or the feature is
off, so nothing regresses for apps that rely on database foreign keys. Because the
feature is opt-in, turning it on signals intent, so when it is enabled the view
**defaults to relationships mode**, with the toggle switching back to raw FK edges.

## Decisions (resolved 2026-07-29)

All seven were settled with the maintainer. The plan builds to these.

1. **Opt-in.** The feature is off by default (`truss.relationships.enabled`, default
   false). Booting arbitrary app models runs their constructors, global scopes, and
   boot hooks, a different risk class than pure DB introspection, so an app only pays
   for it when it asks. Revisit defaulting it on in a later minor once it is proven
   safe across real apps.

2. **Detect by hybrid: type hint first, then invoke-and-`instanceof`.** When a method
   declares a `Relation` return type, trust it without invoking (cheap, no side
   effect). Otherwise invoke it under guards (declared on the class, zero required
   params, `try/catch`) and keep it only if the result is a `Relation`. Type-only
   detection would miss the many apps that do not type-hint. The hard rule in both
   paths: never call an executor (`->get()`, `->first()`), so no query ever runs.
   That guard is the structure-only guarantee and has its own test.

3. **Toggle, not merge.** A "Relationships" toggle switches edges between FK-derived
   and Eloquent-derived, rather than merging the two (which would mean deduping
   FK-vs-relationship edges for the same pair and reconciling direction). FK edges
   stay the behaviour for apps that do not enable the feature. When the feature is
   enabled the view defaults to relationships mode (see the rendering section).

4. **Collapse pure pivots only.** `belongsToMany` through a pivot that holds just the
   two foreign keys (plus optional timestamps) collapses to one many-to-many edge with
   the pivot hidden. A pivot with its own real columns stays a visible entity. Default
   to collapsed for pure pivots once relationships mode is on.

5. **Same cache, documented staleness.** Relationships are built alongside the snapshot
   and cached in the same envelope, on the same rebuild triggers. Model-code changes
   fire no `MigrationsEnded` event, so the docs state that `truss:rebuild` (or a
   deploy-time cache clear) refreshes relationships after model edits. No separate
   cache artifact.

6. **Polymorphism, precisely.** `morphMany`/`morphOne`/`morphToMany` point at a
   concrete child model and render as normal edges. Only `morphTo` (the inverse) is
   ambiguous: if a morph map is registered, expand to its mapped targets as dashed
   edges; otherwise annotate it as polymorphic with no concrete edge. Documented
   limitation.

7. **Per-connection.** Each relationship is tagged with the model's resolved
   connection and rendered only on the matching diagram. A relationship spanning two
   connections is rare; its edge is skipped and noted.

## TDD test plan (write failing tests first)

This layer is not the introspection layer, so its tests use real Eloquent model
fixtures (not real migrations) and assert against the extracted map.

`tests/Unit/Relationships/RelationshipMapperTest.php` (fixture models: `User`
hasMany `Post`, `Post` belongsTo `User`, `User` belongsToMany `Role` through a
pivot, `Post` morphMany `Comment`):
- extracts each relationship with the correct `type`, `from_table`, `to_table`,
  `method`, and keys.
- `belongsToMany` yields the pivot table and both pivot keys.
- a relationship method that throws is skipped and does not break the whole map.
- **safety: no query is executed.** A model whose relationship (or a global scope)
  would hit the database is inspected without a query firing (assert via a
  `DB::listen` counter, or a fixture that throws if queried). This guards the
  structure-only promise directly.
- `morphTo` with and without a registered morph map behaves per decision 6.

`tests/Unit/Relationships/RelationshipModelLocatorTest.php`:
- discovers concrete `Model` subclasses under a fixture path; ignores abstract
  classes, traits, and non-models.
- honours an explicit `truss.relationships.models` list (skips scanning).
- honours `truss.relationships.paths`.

`tests/Feature/Http/SchemaApiTest.php` (extend):
- response carries `relationships` when the feature is on, `null`/absent when off.
- a relationship to an excluded table is filtered out, matching table exclusion.

`tests/js/mermaid-definition.test.js` (Vitest, extend):
- relationships produce labelled edges with the method name and the right
  cardinality per type.
- `belongsToMany` collapses to one many-to-many edge (and hides the pivot when the
  collapse option is on).
- with no relationships (or feature off), output is identical to today's FK-only
  edges (no regression).
- the both-endpoints-in-subset rule still holds for relationship edges.

`tests/e2e/truss.spec.js` (Playwright, extend):
- the "Relationships" toggle appears and, when on, relabels edges and adds edges
  that no FK produced.
- respect the zoom-crispness rule: no `will-change: transform` on `#truss-canvas`.

## Docs to update in the same change (docs-in-sync rule)

- `README.md`: a "Relationships" feature note, the opt-in config, and the model
  discovery paths, plus the structure-only reassurance (definitions, not data, and
  no queries run).
- `docs/DESIGN.md`: the new `src/Relationships/` layer (locator + mapper), the
  `relationships` envelope field, and the client-side rendering extension.
- `docs/DECISIONS.md`: record (a) relationship reading lives outside the pure
  introspection layer and why; (b) invoke-and-`instanceof` detection with the
  never-execute-a-query guard as the structure-only guarantee; (c) the opt-in
  default and the model-change staleness note.
- `config/truss.php`: a `relationships` block (`enabled`, `paths`, `models`, and any
  render defaults).
- Docs website (`albertoarena/laravel-truss-docs`, separate repo): a relationships
  guide and a demo sample that includes relationship edges. Lands with the release,
  since the site reads the latest release tag.

## Non-goals / caveats and risks

- **Structure only, enforced.** The mapper must never execute a relationship query;
  it reads definitions and key names only. This is the load-bearing constraint and
  has its own test (safety, above).
- **Model boot side effects.** Instantiating models runs their constructors and boot
  hooks; a model that does heavy or failing work there is a risk. Mitigation:
  per-model and per-method `try/catch`, no query execution, and opt-in default so an
  app only pays for this when it asks.
- **Discovery fragility.** Non-standard model locations or non-PSR-4 autoloading can
  hide models. Mitigation: configurable paths and an explicit model list escape
  hatch.
- **Polymorphism** (`morphTo`) has no single target without a morph map (decision 6).
- **Staleness after model edits.** No event fires on code change, so the cache can lag
  until `truss:rebuild` or a deploy cache clear (decision 5); documented, not solved
  automatically.
- **Performance.** Booting many models on rebuild has a cost proportional to model
  count; acceptable because it happens on rebuild, not per page view, and the result
  is cached.
- **Not an ORM audit.** The feature reflects declared relationships; it does not
  verify they are consistent with the database, and a wrong or exotic custom relation
  may be skipped rather than guessed at.

## Rollout order

1. Fixture models plus a failing `RelationshipMapperTest` (including the no-query
   safety test), then implement `RelationshipMapper`.
2. Failing `RelationshipModelLocatorTest`, then implement discovery.
3. Attach `relationships` to the envelope at the serve/cache layer; extend the API
   test; exclusion-filter it.
4. Vitest for `mermaid-definition.js` relationship edges (failing), then implement
   labelling, cardinality, and pivot collapse, guarding the no-regression case.
5. Wire the "Relationships" toolbar toggle; extend the Playwright spec.
6. Config `relationships` block; update `README.md`, `docs/DESIGN.md`,
   `docs/DECISIONS.md`.
7. `composer test` / `composer lint` / `npm test` / `npx playwright test` green,
   then commit. Follow-up in the docs repo: relationships guide and demo sample.
