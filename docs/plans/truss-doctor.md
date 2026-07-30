# Plan: `truss:doctor`

Status: Phase 1 shipped (the engine, the rule catalogue below, the console/JSON
formatters, exit codes, and the config block). The later phases remain planned and are
documented here so the direction is not lost. The dashboard phase has its own plan in
`truss-doctor-dashboard.md`.
Owner: Alberto Arena
Roadmap: Approved next (concept published on trussphp.com).
Related: `src/Doctor/**`; `src/Commands/DoctorCommand.php`; `config/truss.php` (the
`doctor` block); `docs/DECISIONS.md` (Schema doctor); `src/Diff/**` (the mirrored
architecture).

Deterministic schema review for Laravel Truss. No AI, no network, no row data.

---

## Scope decision: ship Phase 1 as the MVP first

Build **Phase 1 only** to start (engine + core rules + console/JSON + exit codes +
config), release it, watch the real signal-to-noise on actual schemas, then decide
whether to pursue the later phases. Do NOT pre-commit to all five phases. The full
vision is kept below so it is not lost, but "committed" means Phase 1.

Positioning: this is a feature to **lead with** (its own release, docs page, and a
blog/social push). The framing is always "structural review", never "performance
analysis": Truss cannot know selectivity or query patterns and must not imply it does.

## Locked decisions (from the 2026-07-29 review)

1. **MVP = Phase 1.** Reassess breadth after real-world use.
2. **Confidence is separate from severity (the heuristic-rule answer).** Add a
   `confidence()` (high | heuristic) to the Rule contract, distinct from severity.
   The `recommended` preset runs **high-confidence rules only, at their real
   severity**; `strict` adds the heuristic rules. This keeps severity honest (a
   heuristic rule like INT-002 can still be a Warning when it fires) while keeping the
   default first run quiet, better than gating purely on the info level. Every rule
   stays individually toggleable and ignorable regardless.
3. **Rename the suppression file to avoid the "baseline" clash.** The schema-diff
   feature already has a *baseline* (the pre-migration snapshot in `BaselineStore`).
   The doctor's accepted-findings file is a different thing, so call it a
   **suppression file**: `--generate-suppressions` writes `truss-doctor-ignore.json`
   (finding fingerprints + `generated_at` + Truss version), `--review-suppressions`
   lists stale entries. The commit-hook mode that reuses the diff baseline snapshot
   stays `--since-baseline` (it genuinely uses the diff baseline).
4. **Fixture contract test is mandatory.** `tests/Support/SchemaBuilder` must produce
   byte-identical snapshots to real introspection, or rules drift from reality. Add
   one contract test that builds the same schema via real SQLite introspection AND via
   `SchemaBuilder` and asserts the snapshots are equal. Rule tests then run over the
   in-memory builder (same precedent as `SchemaDifferTest`, which uses pure array
   fixtures).
5. **Reuse the existing exclusion list.** Doctor respects `truss.excluded_tables` (+
   per-connection), the same list `SchemaApiController` already applies, plus an
   optional `doctor.exclude` extension.
6. **Static analysis:** the repo runs Pest + Pint only today (no PHPStan/Larastan), so
   drop "static analysis clean" from the definition of done unless we add it as part
   of Phase 1.

## Open questions, answered from the code

1. **Index column order + uniqueness in the snapshot?** Yes. The `Index` value object
   carries an ordered `columns` list and a `unique` flag (serialized `{name, columns,
   unique}`). IDX-002 and IDX-003 need no introspection change.
2. **Storage engine + collation?** No, the snapshot does not capture them (`Column` is
   name/type/nullable/default; `Table` is name/columns/pk/indexes/fks). INT-005 and
   INT-006 need the introspection layer extended, which is driver-specific and touches
   the pure layer. They are Phase 2, so the gap is deferred. Note: IDX-001's
   engine-awareness (MySQL auto-indexes FKs, PostgreSQL does not) does NOT need this;
   read the driver from `config('database.connections.{c}.driver')`.
3. **Exclusion list:** reuse the main one (decision 5).
4. **Suppression file location:** project root, committed (the team shares accepted
   findings), and it is the *suppression* file, not a baseline (decision 3).

---

## Architecture (mirrors `src/Diff/`)

```
src/Doctor/
    Severity.php          # enum: Error, Warning, Info
    Confidence.php        # enum: High, Heuristic   (new, per decision 2)
    Category.php          # enum: Integrity, Index, Type, Naming, Laravel
    Finding.php           # readonly VO, with fingerprint()
    FindingCollection.php
    Contracts/Rule.php
    RuleRegistry.php
    DoctorRunner.php
    Suppression/SuppressionFile.php
    Suppression/SuppressionFilter.php
    Formatters/ConsoleFormatter.php
    Formatters/JsonFormatter.php
    Rules/Integrity/*.php
    Rules/Index/*.php
    Rules/Type/*.php
```

`Rule` contract:

```php
interface Rule
{
    public function code(): string;             // 'TRUSS-IDX-001'
    public function category(): Category;
    public function confidence(): Confidence;   // High | Heuristic
    public function defaultSeverity(): Severity;
    public function title(): string;
    public function check(array $snapshot, string $connection): iterable; // Finding[]
}
```

`Finding` is readonly: `code, severity, connection, table, column?, message, hint,
suggestion?`. `fingerprint()` = stable hash of `code + connection + table + column`,
never the message text, so wording changes do not invalidate a suppression entry.

`DoctorRunner` takes a snapshot + resolved rule set, runs every rule, applies severity
overrides and ignore patterns from config, applies the suppression filter, returns a
`FindingCollection` sorted by severity, then table, then code. Rules never touch
config, suppressions, or output.

## Command (Phase 1 subset in bold)

```
php artisan truss:doctor                       # bold: default run
  --connection= --table= --only= --skip=       # bold: scoping
  --preset=recommended|strict                  # bold (laravel preset is Phase 2+)
  --format=console|json                        # bold (github|junit are Phase 2)
  --fail-on=error|warning|info|never           # bold
  --since-baseline                             # Phase 2 (reuses the diff baseline)
  --generate-suppressions --review-suppressions# Phase 2
  --fix=stub                                   # Phase 5
```

Alias `truss:check`. Exit codes: 0 clean (below fail level), 1 findings at/above fail
level, 2 config/connection/snapshot error.

## Config (`config/truss.php`, Phase 1)

```php
'doctor' => [
    'preset' => 'recommended',    // recommended | strict | none
    'rules' => [ /* Class => true|false|['severity'=>'error'] */ ],
    'ignore' => [ /* 'TRUSS-IDX-001' => ['audit_log.actor_id'] (fnmatch) */ ],
    'fail_on' => 'error',
    'suppressions' => base_path('truss-doctor-ignore.json'),
    'exclude' => [],              // extends truss.excluded_tables
    'thresholds' => ['max_indexes_per_table' => 8],
],
```

## Phase 1 rule catalogue (the MVP)

High confidence unless marked (H = heuristic; off in `recommended`, on in `strict`).

Integrity `TRUSS-INT-*`
- INT-001 table without a primary key. error.
- INT-002 `*_id` matching an existing table, no FK constraint. warning. **H**
  (false-positives on deliberate cross-database refs, e.g. an `external_user_id`;
  negative tests must cover intentional no-FK).
- INT-003 FK column type differs from the referenced primary key. error.
- INT-007 pivot table without a unique constraint on the key pair. warning.
- INT-009 polymorphic pair (`*_type`, `*_id`) without a composite index. warning. **H**

Index `TRUSS-IDX-*`
- IDX-001 FK column with no index. error on PostgreSQL, info on MySQL (engine-aware
  via the connection driver), reason in the hint.
- IDX-002 exact duplicate index. warning.
- IDX-003 index that is a left prefix of another. warning.
- IDX-004 index duplicating the primary key. warning.
- IDX-005 `deleted_at` present but not indexed. warning. **H**
- IDX-006 `email` / `slug` / `uuid` / `token` without a unique constraint. warning. **H**

Type `TRUSS-TYP-*`
- TYP-001 money-looking column stored as `float` or `double`. error. **H**
  (name-based; negative tests for legitimately-float columns like ratios).
- TYP-002 `is_*` / `has_*` column stored as `varchar`. warning. **H**

Later phases keep the rest of the original catalogue (INT-004/005/006/008/010, IDX-007
to 010, TYP-003 to 008, all NAM-*, all LRV-*), the `laravel` preset, GitHub/JUnit
formatters, suppression workflow, `--since-baseline`, dashboard panel + table badges
(Phase 4, `doctor` key on the existing JSON endpoint, reuse focus/URL state), and
`--fix=stub` (Phase 5, additive-only migration writing, never executed).

## Testing (TDD, no exceptions)

Per rule: positive case (detected, right table/column), negative case (clean schema
yields nothing, this is what stops false positives), and a rule-specific edge (excluded
table, or an engine difference). Plus: the `SchemaBuilder` contract test (decision 4);
runner tests (severity overrides, ignore patterns, ordering, `--only`/`--skip`,
confidence gating by preset); formatter golden-file snapshots; command exit-code tests;
and an integration run against the docs-site demo schema.

## Definition of done, per phase

Positive/negative/edge tests per rule; Pest green across the full PHP and Laravel CI
matrix; `docs/DECISIONS.md` updated with decisions taken; docs-site page updated in the
same PR; no new runtime dependency; no network call anywhere; README feature list
updated only when the phase is user-visible. (Static-analysis line dropped per decision
6 unless we add PHPStan.)

## Rollout

Phase 1 is one or a few PRs: value objects + contracts + registry + runner, then the
rule groups (INT, IDX, TYP) each test-first, then the console/JSON formatters, exit
codes, and the config block, with a new `docs/reference/doctor` page. Release it, push
the blog/social announcement, then reassess.
