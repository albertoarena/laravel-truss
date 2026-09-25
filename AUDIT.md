# Code Audit

Findings from `/code-smell`, the audit command in `.claude/commands/`. Tick an item
when it lands and note the commit next to it.

- P0: broken today, the behaviour is wrong.
- P1: misleading today, users or contributors will get it wrong.
- P2: inconsistent with siblings or other layers, or costly to change.
- P3: polish, duplication, dead code.

Every finding cites `path:line` as of the run that found it. Lines move; the named
code is what matters. `Needs a decision.` marks the ones where a maintainer has to
choose before anyone builds.

## Run log

| Run | Date | Scope | Found | Notes |
|---|---|---|---|---|
| 1 | 2026-09-25 | `src/` (79 files, 7,471 lines), `config/truss.php`, `resources/js` (16 modules, 2,864 lines), `resources/views/index.blade.php`, `resources/css/truss.css` | 5 P0, 10 P1, 25 P2, 17 P3 | First run, so nothing was already tracked. Four parallel slices, every P0 and P1 verified against the source by hand and six of them by running code. Item 1 fixed in the same session. |

## Re-running this audit

For whoever runs `/code-smell` next, so the second run adds to this file instead
of arguing with it:

- **Numbering is one sequence and numbers are never reused.** Continue from the
  highest number below, whatever its priority.
- **Anything already listed is out of scope**, including items left unticked, items
  marked won't-do, and everything under *Not findings* and *Considered and
  dropped*. Do not re-report one in different words. If a listed finding has since
  become wrong, say so in place rather than filing it again.
- **Tag new findings with their run number** in the Run log, and add a row there
  with the date and scope. A count per priority is what makes two runs comparable.
- **Read *Not findings* before judging anything.** It records the deliberate
  decisions this package makes that look like smells, and the sinks already
  checked and found safe. Run 1 spent findings discovering that list; a later run
  should not spend them again.
- **A ticked item stays in the file**, with its commit. The history of what was
  wrong is the point of a tracker.

## P0: Broken

- [x] 1. The HTML export embedded its payload with `JSON_UNESCAPED_SLASHES`
  (`src/Export/HtmlGenerator.php:77`), so a table name, index name or column
  default containing `</script>` ended the script element in
  `resources/views/index.blade.php:66` and the rest was parsed as markup by
  whoever opened the exported file. Added `JSON_HEX_TAG` at the single producer.
  **Fixed in `97556ae`.** The live dashboard was never exposed: that sink is
  behind `@if ($export ?? false)` and only `HtmlGenerator.php:49` sets it.
- [ ] 2. `SchemaDiffer::byName()` keys foreign keys by `name`
  (`src/Diff/SchemaDiffer.php:205`), which is always `''` on SQLite, because
  Laravel's `SQLiteProcessor` reports `'name' => null` and
  `src/Introspection/SnapshotBuilder.php:259` casts it. Every foreign key on a
  table collapses into one slot, so adding a third reports
  `foreign_keys_added: []` plus a fabricated change, and **merely reordering two
  unchanged keys reports `has_changes: true`**. MySQL, Postgres and SQL Server
  report real names and are unaffected. Two other consumers already compensate
  (`src/Export/SchemaExporter.php:147`, `src/Export/MermaidGenerator.php:41`); the
  differ does not. Fix: *Introduce Parameter Object* on `diffKeyed()`, taking an
  identity callback, with foreign keys identified by columns plus referenced
  table. **Needs a decision.** The alternative is synthesizing a stable name once
  in `SnapshotBuilder::foreignKeys()`, which also lets both compensations go, at
  the cost of changing the serialized payload on SQLite hosts. The suite is green
  because `tests/Support/TableDraft.php:165` fabricates MySQL-style names, so the
  differ has only ever been tested against a shape its own producer never emits
  on the default driver: fix that fixture in the same change. The argument that
  settles the decision is the baseline on disk: saved baselines hold `name: ""`,
  so synthesizing names would make the first diff after upgrading report every
  foreign key in the schema as removed and re-added. `.claude/rules/introspection.md:15`
  points the same way, but it is written about `Column.type` and reverse-mapping to
  migration verbs, so reading it as covering a synthesized foreign key name is an
  extension by analogy rather than a rule that synthesizing would break. Two more
  things belong in the same change. First, the origin is an asymmetry in the
  builder: `indexes()` writes `$index['name']` bare (`SnapshotBuilder.php:243`), so
  a missing index name raises an undefined-key warning and is loud, while
  `foreignKeys()` substitutes `''` (`:259`), so a missing foreign key name is
  silent. Somebody already knew the name could be absent and handled it by
  substituting an empty string, and that substitution is the bug. Second, and
  because of that, `byName()` should refuse an empty key and refuse a duplicate one
  rather than overwriting: the guard would have caught this on the first SQLite
  test, and it protects the next field that grows an optional name. Indexes are
  safe here only by accident.
- [ ] 3. `migrate --pretend` destroys the diff baseline, unrecoverably.
  `RebuildOnMigrationsEnded::handle()` (`src/Listeners/RebuildOnMigrationsEnded.php:36-53`)
  never reads `$event->options['pretend']`, and Laravel fires `MigrationsEnded`
  on a dry run too (`Migrator.php:218`, after `runUp()` returned early). The
  listener saves the current schema as the baseline, and `config/truss.php:277-279`
  says why that is terminal: the baseline is on disk because it cannot be rebuilt
  from the live database once a migration has run. A read-only command also
  triggers a full introspection and a cache write. Fix: *Guard Clause* at the top
  of `handle()`, failing test first. `pretend` appears nowhere in `src/`, `tests/`,
  `config/`, `resources/` or `docs/`.
- [ ] 4. `truss:export --format=html --compact` embeds fabricated findings.
  `resolve()` applies `CompactTransform` (`src/Export/ExportBuilder.php:236-238`),
  which strips every non-unique index (`src/Export/CompactTransform.php:36-42`),
  and `contextFor()` then runs the doctor over those stripped tables (lines
  164-170). A correctly indexed foreign key yields
  `TRUSS-IDX-001 error : Foreign key column "posts.user_id" has no index`, at
  error severity and high confidence, so it is in the default preset. The same
  goes for `TRUSS-IDX-005` and `TRUSS-INT-009`, while `TRUSS-IDX-002/003/004` fall
  silent. `ExportCommand` has no check on the flag pair. Fix: *Split Temporary
  Variable* in `resolve()`, keeping the filtered-and-focused set apart from the
  compacted one and passing the former to `contextFor()`. **Needs a decision.**
  That, or refuse the pair in the command as it already refuses html without
  `--output`. The docblock at `ExportBuilder.php:144-156` reasons about `--tables`
  and `--focus` reducing the table set, and never about compact removing structure
  the rules read.
- [ ] 5. `truss:export` fails in the no-database CI scenario the README
  advertises. `applyAnnotations()` calls `DatabaseCommentReader::read()` whenever
  `annotations.source` contains `database`, the shipped default
  (`config/truss.php:372`). The driver guard reads `getDriverName()`, which is
  config-only, so an unreachable `mysql` passes it and then runs a live
  `getTables()` (`src/Export/DatabaseCommentReader.php:45`). The SQLite fallback
  keeps `'connection' => $requestedConnection`, so the export connects to the
  database the fallback exists to avoid, while `truss:show`, `truss:diff` and the
  dashboard all work. `src/Commands/ExportCommand.php:121-125` then blames the
  wrong step ("Could not load the schema") and `ExportController` 500s because it
  catches only `InvalidArgumentException`. Contradicts `docs/DESIGN.md:18` and
  `README.md:132`. Fix: skip the `database` source when `$snapshot['fallback']`
  is true, or degrade an unreachable connection to an empty comment map, which the
  class already does for a driver without comments (*Introduce Null Object*).
  **Needs a decision** on silent degradation versus a notice on stderr, matching
  the cache notice at `ExportCommand.php:144`. Untested because the default lane is
  SQLite, where the reader returns early at line 29.

## P1: Misleading

- [ ] 6. `truss:show`, `truss:diff`, `truss:rebuild` and `truss:doctor` accept an
  unmanaged `--connection` and answer with a fabricated schema
  (`src/Commands/ShowCommand.php:28-29`, `DiffCommand.php:35-36`,
  `RebuildCommand.php:22-24`, `DoctorCommand.php:61`).
  `SchemaCacheRepository::resolve()` (lines 220-223) never validates, and
  `SnapshotBuilder::isReachable()` (lines 66-75) catches `Throwable`, swallowing
  the `InvalidArgumentException` Laravel raises for an unconfigured name. So
  `truss:show --connection=mysq1` prints the whole migration-replayed schema as if
  it were that connection, `truss:rebuild` caches it under `truss:schema:mysq1`,
  and `truss:doctor` can fail CI on it. Every other entry point refuses the same
  input: `ExportCommand.php:83-88`, `ExportBuilder.php:218`,
  `SchemaApiController.php:39`, `DashboardPayload.php:124`. Fix: *Extract Method*,
  lifting the guard at `ExportCommand.php:83-88` into a command concern beside
  `WarnsWhenUncached`. **Needs a decision** on whether `truss:rebuild` should also
  refuse a configured-but-unmanaged connection.
- [ ] 7. `DuplicateIndex` can tell you to drop a unique constraint. It keys on
  columns alone, ignoring `unique` (`src/Doctor/Rules/Index/DuplicateIndex.php:51`),
  and hints "Drop one" (line 63). With `email` carrying both a unique and a plain
  index it raises `TRUSS-IDX-002`, and **which index it blames depends on the order
  the driver reports them**: non-unique first and the finding names
  `users_email_unique` as the duplicate, so acting on it removes the uniqueness
  guarantee. That order dependence also makes the finding non-deterministic for one
  logical schema, against the doctor's own determinism rule. The sibling
  `RedundantPrefixIndex.php:16-18,52-54` skips unique indexes and documents why.
  Fix: include `unique` in the grouping key and always name the non-unique one as
  droppable (*Extract Method* plus *Consolidate Conditional Expression*). Not
  covered by `tests/Unit/Doctor/Rules/DuplicateIndexTest.php`, whose three cases
  are all non-unique.
- [ ] 8. An invalid severity override kills the dashboard. `Severity::from()` is
  unguarded at `src/Doctor/DoctorReport.php:141`, so `['severity' => 'critical']`
  throws `ValueError`, and `SchemaApiController::__invoke` has no catch
  (`src/Http/Controllers/SchemaApiController.php:32-42`), so
  `GET {prefix}/api/schema` 500s and the diagram never renders. It also escapes
  `GetStructuralReview`, which catches only `InvalidArgumentException`
  (`src/Mcp/Tools/GetStructuralReview.php:39`). Fix: `Severity::tryFrom()` with a
  fallback at the `DoctorReport` boundary (*Introduce Assertion*).
- [ ] 9. A misspelled preset silently reports a clean schema.
  `RuleRegistry::isActive` ends in `default => false`
  (`src/Doctor/RuleRegistry.php:102-106`), so `TRUSS_DOCTOR_PRESET=recomended`
  disables every rule and the dashboard shows zero findings and a clean summary,
  while `DoctorCommand::valid()` (`src/Commands/DoctorCommand.php:88-93`) catches
  the same typo and exits 2. The browser's answer is a lie. Same boundary as
  item 8, opposite direction, separate fix: validate the preset token where the
  config is read (*Extract Method*).
- [ ] 10. `truss:doctor --only=indexes` exits 0 having run nothing.
  `valid()` checks format, preset and fail-on but never the category tokens, and
  `categories()` only trims and splits (`src/Commands/DoctorCommand.php:88-103`).
  `--table=user` behaves the same way: `DoctorReport::selectTables` returns an
  empty list for an unknown table (lines 111-112). A CI gate built on either
  passes green while checking nothing. `ExportCommand.php:127-131` treats the
  identical empty case as exit 2. Fix: validate tokens in `valid()` and fail on an
  unmatched `--table` (*Replace Nested Conditional with Guard Clause*). **Needs a
  decision**: `Category::Naming` and `Category::Laravel` have no rules
  (`src/Doctor/Category.php:16-17`), so validating against the enum alone still
  exits 0 clean for those two. No test covers `--only` at all.
- [ ] 11. One column named `full name` blanks the whole diagram. `mermaidType()`
  collapses non-alphanumerics precisely because "Mermaid's ER grammar splits
  attributes on whitespace" (`resources/js/mermaid-definition.js:12-13`), and
  `accSafe()` guards the filter text for the same reason, but `column.name` and
  `table.name` are interpolated raw at lines 62 and 65, with the identical hole in
  `src/Export/MermaidGenerator.php:70,73`. Mermaid rejects the three-token
  attribute line and `resources/js/truss.js:1236` replaces the entire canvas with
  "Diagram failed to render", so one legal quoted identifier takes out the
  dashboard rather than one row. `truss:export --format=mermaid` emits the same
  invalid definition. Fix: one shared `mermaidIdentifier()` (*Extract Function*)
  quoting entity names and normalising attribute names, applied on both sides.
  **Needs a decision** on label fidelity. No test on either side uses a name
  containing whitespace.
- [ ] 12. Eight docblocks and a decision entry describe a guard that was deleted.
  `resources/js/{dbml,markdown,table}-export.js`, `tests/js/dbml-export.test.js`
  and `tests/js/markdown-export.test.js` do not exist, and no cross-check test
  exists anywhere. Still claiming otherwise: `src/Export/DbmlGenerator.php:12-15`,
  `MarkdownGenerator.php:11-12`, `MermaidGenerator.php:11-12`,
  `JsonGenerator.php:15-16`, `CsvGenerator.php:14-15`,
  `tests/Unit/Export/DbmlGeneratorTest.php:11`, `MermaidGeneratorTest.php:9-11`,
  `MarkdownGeneratorTest.php:9-10`, and `docs/DECISIONS.md:183`, which contradicts
  line 219 of its own file. A contributor believes changing DBML output breaks a
  guard test and a browser download; neither exists, and the browser fetches the
  route (`resources/js/export-request.js:1-5`). Real consequence:
  `tests/Fixtures/export/schema.{dbml,md,mmd,json}` are now read only by
  `tests/e2e/truss.spec.js:33-37` as a route stand-in, so no PHP test pins them
  and a generator change leaves e2e green against stale bytes. Fix: *Remove Dead
  Comment* on the eight, amend `DECISIONS.md:183`, and add the golden test that
  entry claims exists.
- [ ] 13. Migrating one connection re-baselines every managed connection.
  `src/Listeners/RebuildOnMigrationsEnded.php:42-46` scopes the refresh with
  `$event->options['database']`, but no first-party Laravel command puts
  `database` in that array: `MigrateCommand` passes `['pretend', 'step']`,
  `RollbackCommand` adds `batch`, `ResetCommand` passes a bool, and `--database`
  is applied through `Migrator::usingConnection()`. The branch is unreachable and
  the all-connections fallback always runs, so with two managed connections a
  migration on one discards the other's baseline, unrecoverably per
  `config/truss.php:277-279`. The single-connection case is correct only by
  accident, through `config('database.default')` being swapped. Fix: *Remove Dead
  Code* for the branch, derive the connection explicitly with a comment recording
  why the default is the migrated one, and rewrite the tests to emit the real
  event: `tests/Feature/Cache/RebuildTriggerTest.php:16,24,65,77,91,107` all
  hand-build a shape Laravel never emits. **Needs a decision** on whether
  multi-connection hosts get per-connection scoping at all, given Laravel does not
  say which connection ran.
- [ ] 14. Health markers leave dead tab stops behind. `markDoctorRows` sets
  `role="button"`, `tabindex="0"` and a findings `title`
  (`resources/js/truss.js:986-988`); `clearDoctorRows` removes only the classes and
  datasets (lines 955-961). After closing the Health panel those labels stay
  keyboard-focusable, announce as buttons, do nothing when activated, have no focus
  indicator, and still reveal the findings on hover. WCAG 2.4.7 and 4.1.2. Fix:
  *Extract Function* for a `makeTrigger`/`unmakeTrigger` pair.
- [ ] 15. Three toolbar buttons are named by a decorative glyph: More `⋯`, Legend
  `▤`, Theme `◐` (`resources/views/index.blade.php:117,135,136`). The glyph is text
  content, so it wins over `title` in the accessible-name computation and is what
  gets announced. `#truss-health-max-btn` (line 178) already does it right with
  `aria-label` plus `aria-hidden` on the glyph. Compounding it, the axe scan runs
  against `tests/e2e/harness.html`, which has no Legend or Theme button at all, so
  no CI scan has ever seen two of them. The harness divergence is separately known;
  this gives it three concrete misses.

## P2: Inconsistent or costly to change

### The doctor

- [ ] 16. "Does the primary key count as an index?" is answered four ways across
  six rules: it counts (`ForeignKeyWithoutIndex.php:89`,
  `PivotWithoutUniqueKey.php:157`), it counts only when exactly `[$name]`
  (`MissingUniqueConstraint.php:63`, while lines 87-100 accept any column of any
  unique index), exact equality only (`IndexDuplicatingPrimaryKey.php:54`), or
  never (`UnindexedSoftDelete.php:72-81`, `RedundantPrefixIndex.php:88-105`). The
  split exists because `SnapshotBuilder::primaryKey()` hoists the key out of
  `indexes`. Consequences: a primary key of `(team_id, email)` is flagged
  `TRUSS-IDX-006` although it already guarantees the scoped uniqueness the rule's
  own docblock calls acceptable, while the same shape written as
  `unique(['team_id','email'])` is clean; and a pivot with a genuinely redundant
  `index(['post_id'])` under primary key `(post_id, tag_id)` is caught by no rule
  at all. Fix: *Extract Class* for one read-only table helper, then *Move Method*
  the six private helpers onto it. **Needs a decision** on the per-rule semantics
  first.
- [ ] 17. A column name means two things. Three rules lowercase before matching
  (`Type/MoneyAsFloat.php:80`, `Type/BooleanAsString.php:50`,
  `Index/MissingUniqueConstraint.php:59`) and five use the raw name
  (`Integrity/LikelyMissingForeignKey.php:59`, `ForeignKeyPointsAtWrongTable.php:86`,
  `PolymorphicWithoutIndex.php:53`, `Index/UnindexedSoftDelete.php:52`,
  `PivotWithoutUniqueKey.php:40,137`). On an upper-case schema the Type rules and
  `TRUSS-IDX-006` fire while those five go silent, and `TRUSS-INT-007` can never
  reach `extra == 0`, so it never fires at all. Fix: *Extract Method* then *Pull Up
  Method* onto the helper from item 16. **Needs a decision**: normalising at the
  serializer boundary is the alternative.
- [ ] 18. Two rules resolve "the table a `*_id` names" differently and only one is
  prefix-aware (`LikelyMissingForeignKey.php:99-112` versus
  `ForeignKeyPointsAtWrongTable.php:141-179`). On a `lunar_`-style schema an
  unconstrained `user_id` is invisible to `TRUSS-INT-002` while `TRUSS-INT-010`
  resolves `lunar_users` fine, though it calls itself INT-002's sibling. Fix:
  *Extract Class* for the shared resolution. **Needs a decision**: check
  `docs/research/` first, this may be deliberate calibration.
- [ ] 19. `Finding::$column` carries a real column, an index name, or a
  comma-joined list (`ForeignKeyWithoutIndex.php:61,68`, `DoctorRunner.php:64`,
  `config/truss.php:334`). A composite foreign key finding cannot be silenced by
  any documented `table.column` ignore pattern, its fingerprint hashes the
  pseudo-column, and the dashboard drops its marker. Fix: *Split Loop* plus
  *Extract Method* to emit one finding per column, as `ForeignKeyTypeMismatch`
  already does.
- [ ] 20. There is no rule extension point. `RuleRegistry::default()` is hardcoded
  (lines 42-60) and `DoctorReport` is `new`-ed on two of four paths
  (`DoctorCommand.php:72`, `ExportBuilder.php:164`), so a container binding would be
  honoured by only half the callers and an unknown code renders as high-confidence
  with a null category. A third party cannot add a rule at all. Fix: *Introduce
  Parameter Object* to inject the registry, plus *Remove Middle Man*. **Needs a
  decision** on whether third-party rules are supported.
- [ ] 21. Category and code prefix are crossed in both directions:
  `Integrity/PolymorphicWithoutIndex.php:36,44` files an index problem as
  INT/Integrity, and `Index/MissingUniqueConstraint.php:26-33` files a uniqueness
  problem as IDX/Index. So `--only=index` omits an index rule and `--skip=index`
  still runs it. The codes are permanent, so there is no free fix. **Needs a
  decision**, probably to document it.
- [ ] 22. `MoneyAsFloat` is the only heuristic rule emitting `Severity::Error`
  (`Type/MoneyAsFloat.php:33-42,78-88`) where five sibling heuristics use Warning.
  With `--preset=strict` and the default `fail_on=error`, a `double` named
  `discount_rate` fails the build on a name guess. Fix: one line to Warning.
  **Needs a decision**: genuine money-as-float may warrant Error.
- [ ] 23. `SchemaResource` skips the `textFormats()` guard both MCP tools apply
  (`src/Mcp/Resources/SchemaResource.php:28-32` versus `Tools/GetSchema.php:25-31`).
- [ ] 24. The HTML export ignores `truss.doctor.dashboard`.
  `DashboardPayload::doctorFor()` honours it (lines 143-146),
  `ExportBuilder::contextFor()` does not (lines 158-172), and
  `HtmlGenerator::payload()` embeds whatever it is given (lines 73-75). So an
  install that set the switch to keep findings off the panel still ships every
  finding inside a file it hands to a client. **Needs a decision, and it is
  genuinely ambiguous**: `config/truss.php:315-317` scopes the switch to "the
  schema endpoint" and says it leaves "the CLI/CI doctor untouched", and the export
  is CLI-produced. Either honour it in both consumers (*Extract Method* plus *Move
  Method* onto `DoctorReport`) or document that an export always carries the
  review.

### Layers and duplication

- [ ] 25. `ExportController::MIME` is a second hardcoded format list that nothing
  keeps in step with `SchemaExporter::GENERATORS`, with a third copy in the
  frontend (`src/Http/Controllers/ExportController.php:27-34,41,73`,
  `src/Export/SchemaExporter.php:25-33`, `resources/js/truss.js:451-455`). A
  seventh text format passes the route guard and dies on an undefined array key as
  a 500, and adding a format today means five coordinated edits with no
  registration point for a third-party generator. Fix: *Move Field*, putting mime
  and extension on the `Generator` contract or a registry beside `GENERATORS`.
- [ ] 26. The "global plus per-connection `excluded_tables`" merge exists four
  times and the copies have already drifted:
  `src/Dashboard/DashboardPayload.php:243-249` and
  `src/Export/ExportBuilder.php:277-283` are byte-identical,
  `src/Commands/ShowCommand.php:86-97` is inline without `array_unique`, and
  `src/Doctor/DoctorReport.php:103-113` adds `truss.doctor.exclude`. This is not
  the documented server-side/client-side split, it is one config rule written out
  four times, and missing one is a silent structure leak, which
  `ShowCommand.php:33-36` records having shipped once. Fix: *Extract Class*, an
  injected `ExcludedTables` resolver with `for()` and `filter()`, `DoctorReport`
  passing its extra list in.
- [ ] 27. Theme colours accept any bare word. `ThemeStylesheet::COLOR` ends in
  `|[a-z]+)$` (`src/Theme/ThemeStylesheet.php:49`), so `'background' => 'navyblue'`
  is emitted as `--bp-bg: navyblue` (line 118). Custom properties accept any token,
  so `background: var(--bp-bg)` becomes invalid at computed-value time and resolves
  to transparent rather than to the shipped default, contradicting the docblock
  (lines 18-21) and `config/truss.php:201`, which both promise a fallback. One typo
  gives an unreadable dashboard, silently. Bare keywords are deliberate
  (`tests/Unit/Theme/ThemeStylesheetTest.php:125` pins `rebeccapurple`,
  `transparent`, `currentColor`); only the breadth is wrong. Fix: *Replace Magic
  Literal* with a `NAMED_COLORS` constant checked by membership. **Needs a
  decision**: about 148 names in a constant, or soften the documented promise. If
  the constant is taken, source the names from the CSS Color specification rather
  than typing them out, or the list drifts and the documented fallback quietly
  becomes false again in a new way; keep `transparent` and `currentColor` as
  explicit extras, since neither is a colour name. Re-read both promises
  afterwards, the docblock and `config/truss.php:201`, because making them true is
  the entire point of the change.
- [ ] 28. `TrussManager` is bound a `singleton`
  (`src/TrussServiceProvider.php:65`) and constructor-injects the `scoped`
  `SchemaCacheRepository` (line 62), whose binding comment says every reader must
  "share the same `lastError()`" because it is per-request state. Under Octane and
  queue workers the container flushes scoped instances but not singletons, so
  `Truss::payload()` keeps a first-resolution repository for the worker's life
  while `SchemaApiController` gets the current one. The stated invariant is false on
  exactly the targets `scoped` exists for, and inert under FPM. Fix: make line 65
  `scoped` so the lifetime matches the dependency. `DashboardPayload.php:157` also
  claims singleton resolution that no binding provides.

### The frontend

- [ ] 29. `fetch`, `response.json()` and `inlinePayload` are unguarded and
  `loadSchema()` is uncaught (`resources/js/truss.js:1316-1335,1598`), so being
  offline or a malformed embedded payload leaves "Loading schema…" on screen
  forever. Fix: one try/catch into the existing error banner.
- [ ] 30. Export actions fail silently: no clipboard check, no non-OK fetch check,
  and `only` always carries every table name (`truss.js:494,458-466,490-491`). Copy
  JSON does nothing over plain http, and a large unfiltered Markdown export 414s
  with no message. Fix: a single failure path plus a `clipboardAvailable`
  predicate in `export-menu.js`.
- [ ] 31. `#truss-viewport` hardcodes `100vh` minus toolbar and footer with no term
  for the banners (`resources/css/truss.css:539`), so any banner pushes the footer
  below the fold and the fixed popover drifts from the absolute panels.
  `tests/e2e/harness.html:22` overrides the rule, so no test sees it. Fix: flex
  column at `100dvh` (*Replace Magic Literal*).
- [ ] 32. Three numeric data-attribute fallbacks use `||`
  (`resources/js/truss.js:26-28`), swallowing a legitimate `0`: `TRUSS_FOCUS_DEPTH=0`
  silently behaves as 1 while `?depth=0` works, and `min_zoom` 0 becomes 0.4. Fix:
  a `numberAttr` helper using `??` (*Extract Function*).
- [ ] 33. Escape and outside-click dismissal exist only for the popover, not for
  the More, Legend, Changes or Health overlays (`truss.js:1492-1496,1511-1513`
  versus `1073-1101,714-728,851-868`), so the folded `⋯` panel sits over the
  diagram with no way to dismiss it but its own button. Fix: one `dismissOverlays`
  behind the existing `closeOverlays` (*Extract Method*).
- [ ] 34. The Export button's `aria-expanded` is cleared only in `hidePopover`,
  which `positionPopover` deliberately skips (`truss.js:1508,409,324-327,1070`), so
  opening a table menu after Export leaves the button filled and announced as
  expanded. Fix: move the trigger bookkeeping into `placePopover` (*Move Statements
  into Function*).
- [ ] 35. `render()` is async, unserialized, and reuses the fixed Mermaid id
  `truss-graph` (`truss.js:1185-1238,1212,1233`), so two quick control changes can
  leave the older diagram drawn and `lastKey` written out of order, skipping a
  needed auto-fit. Fix: a generation-counter guard clause.
- [ ] 36. The two Mermaid generators diverge on the accessibility lines
  (`resources/js/mermaid-definition.js:142-144` versus
  `src/Export/MermaidGenerator.php:46`), so `truss:export --format=mermaid` output
  carries no `accTitle`/`accDescr`. Fix: port the lines or state the difference.
  Related to item 12's stale parity comments.
- [ ] 37. The dark palette is 28 declarations duplicated byte-identically
  (`resources/css/truss.css:70-98` and `105-133`), and
  `tests/js/palette-contrast.test.js:20-24` reads only the last copy, so a
  one-sided edit goes unmeasured for OS-dark users while CI stays green. The test's
  own comment states the assumption without enforcing it. Fix: alias one
  `--bp-dark-*` set (*Extract Variable*).
- [ ] 38. `#truss-banners` is a plain div with no live region
  (`resources/views/index.blade.php:140`), while the Focus picker two lines up at
  :98 uses `role="status" aria-live="polite"` on an `sr-only` span. Filtering to
  zero matches empties the diagram and announces nothing. WCAG 4.1.3. Fix: the
  same pattern the Focus picker already uses.
- [ ] 39. The Show hidden tables handler never re-validates `state.focusRoot`,
  bypassing the single `setFocus` gate (`resources/js/truss.js:1431-1436` versus
  `128-143`), so unticking the box while focused on a revealed table blanks the
  diagram. Fix: route through `setFocus` (*Remove Middle Man*).
- [ ] 40. `docs/DESIGN.md:144,146` still names three deleted client-side export
  modules as shipped. Fix: point the bullets at `export-request.js` (*Change
  Reference*). Same deletion as item 12.

## P3: Polish

### Duplicates to remove

- [ ] 41. `escapeHtml` is duplicated identically in `resources/js/truss.js:254-256`
  and `resources/js/focus-combobox.js:15-19`, and the `truss.js` copy is untested.
  *Extract Module*.
- [ ] 42. The SVG namespace string appears three times under three names
  (`resources/js/truss.js:217,511,915`). Consolidate to one constant.
- [ ] 43. The two popover-trigger selector lists disagree
  (`resources/js/truss.js:1512,1523`), so a new trigger misses one behaviour.
  *Extract Constant*.
- [ ] 44. `DiffCommand::describeTableChanges` is nine near-identical
  foreach-and-format blocks (`src/Commands/DiffCommand.php:124-164`), and the three
  "changed" branches already print less detail than the columns one. *Extract
  Method* driven by a `key => label` map.

### Dead code and stale references

- [ ] 45. Four exported, unit-tested functions have no caller
  (`resources/js/diff-view.js:14,34,39`, `resources/js/doctor-view.js:47`), plus
  four unused `buildExportUrl` parameters (`resources/js/export-request.js:14`).
  Kept alive by their own specs. **Needs a decision**: delete, or document as an
  embedding API.
- [ ] 46. Two comments cite `src/Introspection/CLAUDE.md`, absent since the rules
  moved to `.claude/rules/introspection.md`
  (`src/Introspection/SnapshotBuilder.php:26`,
  `tests/Unit/Introspection/PurityTest.php:5`).
- [ ] 47. Dead and stale odds in the frontend: unused `el.zoom`
  (`resources/js/truss.js:76`), a "only on phone" comment that is wrong
  (`resources/css/truss.css:244`), and an unreachable zoom clamp
  (`resources/js/truss.js:159,1556` against
  `resources/views/index.blade.php:150`).

### Primitive values and boundaries

- [ ] 48. `BaselineStore` reads `truss.diff.disk` without the default used
  elsewhere (`src/Diff/BaselineStore.php:147` versus
  `src/Commands/DiffCommand.php:50`), so an absent key writes the baseline to the
  application's default disk, which `config/truss.php` explicitly forbids, while
  the CLI still names `[local]`. *Extract Method*, one `disk()` accessor carrying
  the default.
- [ ] 49. `BaselineStore::slug()` slugs the connection name while the cache key
  uses it raw (`src/Diff/BaselineStore.php:138-143` versus
  `src/Cache/SchemaCacheRepository.php:148-151`), so `tenant_1` and `tenant-1`
  share one baseline file but not a cache entry, and one connection's baseline is
  diffed against the other's snapshot. *Rename Method* plus a disambiguating
  suffix.
- [ ] 50. `MarkdownGenerator` flattens table annotations with `flatten()` but
  column annotations with `cell()`, which strips only `|` and `\n`
  (`src/Export/MarkdownGenerator.php:48` versus `59,131`), with the same blind spot
  in `src/Export/CsvGenerator.php:66`. A CRLF column comment leaves a bare `\r`
  inside a Markdown table cell.
- [ ] 51. Severity and connection names are interpolated unescaped into attributes
  while being escaped as text (`resources/js/truss.js:1030,1032,1045,984,1300`),
  breaking the escape-everything convention. Trusted sources today. Escape both, or
  use `createElement` for options.
- [ ] 52. A MySQL `set` column is labelled an enum and its tooltip header is cut
  mid-token (`resources/js/truss.js:241-245,251,344`): hover reads `set( (3):` and
  the popover is titled `enum · 3`. *Extract Variable* deriving the keyword from
  the existing capture.

### Splits and efficiency

- [ ] 53. `SnapshotBuilder::primaryKey()` (line 219) and `indexes()` (line 237)
  each call `getIndexes()`, and both are invoked from the same `new Table(...)`
  argument list (lines 181-184), so a 200-table schema issues 200 redundant
  introspection round trips per rebuild over a possibly remote connection. Fetch
  once and partition in one pass.
- [ ] 54. `ExportBuilder` takes 12 constructor parameters and `copy()` repeats a
  nine-line `array_key_exists` ladder (`src/Export/ExportBuilder.php:33-46,288-303`),
  so adding one filter needs three coordinated edits and a forgotten ladder entry
  silently drops that filter on every derived instance. *Introduce Parameter
  Object* for the nine filter fields.
- [ ] 55. `AssetInliner` imports `AssetController` from the Http layer purely for
  its static asset inventory (`src/Export/Html/AssetInliner.php:7,58`), and three
  asset names are hardcoded beside it at lines 43, 50 and 59. *Move Field* to a
  neutral registry both layers read.
- [ ] 56. `positionCluster` hardcodes the panels' 62px and 14px offsets in
  JavaScript (`resources/js/truss.js:334-338` versus
  `resources/css/truss.css:267,283,313`), so a toolbar height change is four edits.
  Move to an `is-cluster` class.
- [ ] 57. `findTableNode` rescans the whole SVG per name from six callers
  (`resources/js/truss.js:230-238`; callers at 667, 672, 743, 754, 906, 929),
  which is tens of thousands of label queries per render on a large schema. Build
  one Map after `normalizeSvg`.

## Not findings

Deliberate decisions that look like smells. Run 1 established these; a later run
should not spend findings rediscovering them.

- **Column defaults are structure**, not data. The boundary is the `CREATE TABLE`
  definition versus table rows, so defaults are serialized on purpose
  (`docs/DECISIONS.md`). They are still an injection *vector*, which is item 1, but
  their presence is not a leak.
- **`Column.type` is the native full type** and is deliberately not normalised to a
  Laravel verb. Not missing abstraction.
- **~48 `config()` calls across `src/`** are the single-source-of-truth rule
  working, not scatter. A hardcoded value that should be config is still a finding.
- **The Gate contract is deliberately not resolved at boot** (ADR 0002), because
  October CMS binds no Gate and every artisan command died. The
  `$this->app->bound()` guard stays.
- **`viewTruss` is a fixed ability name**, the callback is the app's, the gate is
  consulted only outside local, and denial is 404 rather than 403.
- **Exclusions are filtered server-side while filter and focus run client-side.**
  Two filtering sites is a decision; item 26 is about the merge being written four
  times, which is different.
- **Cache-store failures degrade rather than crash**, so the swallowed exception in
  the cache layer is intended.
- **The Mermaid definition generator is client-side JavaScript** and the PHP one
  serves exports. The split is deliberate; item 36 is a concrete divergence within
  it.
- **No build step, no bundler, no CDN, CSP-safe.** Never propose one.
- **`will-change: transform` must stay off `#truss-canvas`**; its absence is
  deliberate.
- **Toolbar breakpoints are JS-driven** via a ResizeObserver since v1.13.1, not a
  media query and not `container-type`.
- **The HTML export inlines ~3.5 MB of Mermaid on purpose.** Size is an accepted
  trade.
- **`PivotWithoutUniqueKey` matches ADR 0003 line by line**, checked in run 1. Its
  thresholds are calibrated against a 20-application field study, not guesswork.
- **The other three unescaped Blade sinks were checked in run 1 and are safe**:
  `$inlineCss`, `$inlineMermaid` and `$inlineModules`
  (`resources/views/index.blade.php:22,25,195`) carry package assets, not
  schema-derived values, and the theme values that do reach the CSS are filtered by
  allow-lists that reject `<` and `>`.

## Considered and dropped

- **The doctor context missing a `driver` key.** Suspected during run 1, which
  would have made severity wrong on MySQL exports too. `DoctorReport::for()` sets
  it at line 40. Wrong.
- **The e2e harness diverging from the Blade shell** is not filed on its own,
  because it was already known and planned before this audit. It appears inside
  item 15 as the reason two findings were invisible to CI.
