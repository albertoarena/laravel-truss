# Field study: `truss:doctor` against twelve real Laravel applications

**Run 20/08/2026. Truss v1.9.1.** This file is the permanent record. The harness
and the raw JSON live in a separate repository on an external volume
(`laravel-truss-experiment-08-2026`) which is **not backed up anywhere**, so
everything needed to act on the results, or to doubt them, is reproduced here.

## Why it was run

The doctor had 13 rules and a passing test suite, and no evidence about how it
behaves on schemas written by people who never heard of Truss. Fixtures cannot
answer that: they are written by the same person who wrote the rules, so they
encode the same assumptions.

## Method

Every application: create an empty database, install the app, add
`albertoarena/laravel-truss`, run migrations, run `truss:doctor` twice (once per
preset), record the JSON. Then hand-triage every finding.

**The controls that make the numbers mean something:**

- **One engine for everything: MySQL 8.0.** Not a preference.
  `TRUSS-IDX-001` says in its own docblock that InnoDB creates the index for you,
  so it barely fires on MySQL while PostgreSQL and SQLite make it fire
  constantly. Mixing engines would have measured the engine, not the schema.
- **Migrations only, never seeded.** Every rule reads structure, so rows change
  nothing and cost hours.
- **Two baselines, both zero.** Bare Laravel 12 and Laravel 13 skeletons each
  produced **9 tables and zero findings on both presets**, so every result below
  is measured against a real zero, and rows on either baseline are comparable.
- **Presets recorded separately and never summed.** `recommended` is the 7
  high-confidence rules. `strict` adds the 6 heuristic ones.
- **Every row carries the application's commit SHA or resolved version**, so a
  finding can be re-run against the same code after the project changes.
- **No `--ignore-platform-reqs`, ever.** A refusal to install is a result. That
  discipline is what produced ADR 0001.

## Results

**12 applications, 482 tables, 119 `recommended` findings, 281 `strict`.**

| App | Tables | `recommended` | errors | `strict` | tables hit |
|---|---:|---:|---:|---:|---:|
| bagisto 2.4.9 | 146 | 38 | 13 | 74 | 33% |
| monica | 100 | 42 | 14 | 60 | 42% |
| firefly-iii | 81 | 20 | 2 | 73 | 59% |
| koel | 40 | 17 | 4 | 28 | 52% |
| cachet 3.x | 32 | 1 | 0 | 24 | 44% |
| twill 3.x | 24 | 1 | 1 | 19 | 54% |
| jetstream | 14 | 0 | 0 | 3 | 14% |
| statamic 6.x | 9 | 0 | 0 | 0 | 0% |
| filament v5 | 9 | 0 | 0 | 0 | 0% |
| breeze | 9 | 0 | 0 | 0 | 0% |
| control, Laravel 13 | 9 | 0 | 0 | 0 | 0% |
| control, Laravel 12 | 9 | 0 | 0 | 0 | 0% |

### Findings by rule (`strict`)

| Code | Rule | Count | Apps |
|---|---|---:|---:|
| `TRUSS-INT-007` | PivotWithoutUniqueKey | 69 | 4 |
| `TRUSS-IDX-005` | UnindexedSoftDelete | 50 | 4 |
| `TRUSS-INT-002` | LikelyMissingForeignKey | 45 | 6 |
| `TRUSS-IDX-006` | MissingUniqueConstraint | 37 | 6 |
| `TRUSS-INT-001` | MissingPrimaryKey | 32 | 5 |
| `TRUSS-INT-009` | PolymorphicWithoutIndex | 28 | 5 |
| `TRUSS-IDX-003` | RedundantPrefixIndex | 10 | 5 |
| `TRUSS-IDX-004` | IndexDuplicatingPrimaryKey | 4 | 2 |
| `TRUSS-TYP-001` | MoneyAsFloat | 2 | 1 |
| `TRUSS-IDX-002` | DuplicateIndex | 2 | 1 |
| `TRUSS-INT-003` | ForeignKeyTypeMismatch | 2 | 1 |
| `TRUSS-IDX-001` | ForeignKeyWithoutIndex | **0** | 0 |

## What the study found about Truss

Three problems, each with its own ADR.

1. **`TRUSS-INT-007` is wrong 56 times out of 69**, which is 47% of the entire
   default preset. See [ADR 0003](../adr/0003-pivot-detection.md).
2. **Truss cannot boot in October CMS**, taking every `artisan` command with it.
   See [ADR 0002](../adr/0002-defer-gate-registration.md).
3. **Truss cannot install in BookStack or Invoice Ninja**, because `php ^8.3`
   loses to a pinned 8.2 platform. See [ADR 0001](../adr/0001-php-82-minimum.md).

### And what it found working, which is evidence too

- **`TRUSS-IDX-001` fired zero times in 482 tables**, exactly as its docblock
  predicts on InnoDB. **A prediction in a comment is now a measurement.**
- **`TRUSS-TYP-001` stayed silent on Firefly III**, an 81-table personal-finance
  application, and found two real `double` money columns in Bagisto
  (`cart_shipping_rates.price` and `.base_price`). Silence on the application
  most made of money is a calibration result, not a miss.
- **`TRUSS-INT-003` found two genuine type mismatches in Koel**:
  `duplicate_uploads.existing_song_id` and `transcodes.song_id` are `varchar(191)`
  referencing `songs.id`, which is `varchar(36)`.
- **Both controls and all three starter kits scored zero**, so the rules do not
  fire on a clean Laravel schema.

## What the study found about the Laravel ecosystem

**The `password_resets` design is inherited, not written.** Six tables across
four projects have no primary key: Bagisto (`password_resets`,
`admin_password_resets`, `customer_password_resets`), Firefly III, Twill
(`twill_password_resets`) and Koel. All carry Laravel's old scaffold shape
(`email`, `token`, `created_at`, `KEY (email)`), against Laravel 13's own
`password_reset_tokens`, which has `PRIMARY KEY (email)`. **Twill renamed the
table and kept the design**, which is the clearest evidence that this propagated
rather than being chosen. Anything written publicly about these findings must say
so, or it accuses four projects of something the framework handed them.

**Starter kits add tables but not problems.** Breeze and Filament both landed
exactly on the control at 9 tables. Jetstream added five tables and still scored
zero under `recommended`. **The schema a new Laravel project starts with does not
depend on which starter kit is chosen.**

**A flat-file CMS keeps no content in the database.** Statamic scored exactly the
control.

**Open-source schemas are a biased sample, and the bias runs one way.** These
projects ship migrations built to run on MySQL, PostgreSQL and SQLite alike,
which suppresses foreign keys and engine-specific indexes by design. 16 of
Cachet's 24 `strict` findings are "looks like a foreign key but has no
constraint", and portability is a very plausible reason. **A private application
targeting one engine is a different population and this study cannot see one.**

## Findings worth reporting to the projects

Twelve findings across four of these applications are high-confidence, each
checkable in a single `SHOW CREATE TABLE`, and they are **not listed here on
purpose**.

Every project hears about its own schema from us first, as an issue or a pull
request, before anything is written publicly. Publishing a table of unreported
defects in somebody else's database, alongside a release that advertises the
tool which found them, would make that project's maintainers an exhibit rather
than the people being helped. The counts above are a size; a named defect is a
report, and a report has an owner who should read it first.

This section will carry the list once those reports have been sent.

## Limitations, stated rather than buried

- **Four applications of sixteen were not measured**, leaving the twelve every
  count in this document rests on: October and BookStack could not run Truss
  (which is itself the result), and Lunar and Snipe-IT were parked unrun.
  Snipe-IT is the notable gap: it predates Laravel 5 and is the maximum
  migration-debt case in the set. **Invoice Ninja and Pixelfed are not in this
  number and never were**: neither was ever a candidate, the first because its
  `composer.json` points at a path repository outside the clone and the second
  because it publishes on a `dev` branch that moves daily. They are listed as
  untestable on `/reference/tested-applications/`, not as parked.
- **81% is a floor, not an estimate.** The 13 `TRUSS-INT-007` findings not shown
  to be wrong were not shown to be right either.
- **The triage is one person's judgement.** "Entity table, not a pivot" is a
  reading of `bagisto.cart`, not a proof, though at 36 columns it is not a close
  call.
- **Two records were nearly wrong and were quarantined rather than deleted.**
  Lunar's `meta.json` was 33 minutes older than its `run.log`, describing a
  superseded run; and Snipe-IT recorded "Truss would not install on this app's
  dependency set" when the real cause was `APP_URL=null` in its own
  `.env.example` killing `package:discover`. **Both would have entered this
  document as evidence.** Neither is counted above.
- **The fix is measured on the same schemas that shaped it.** The `TRUSS-INT-007`
  thresholds (see [ADR 0003](../adr/0003-pivot-detection.md)) were chosen by
  looking at these sixteen applications, and the resulting "69 to 14" and "47%"
  were then measured on the twelve of them that ran both versions, which is the
  bullet above this one. **That is an in-sample figure**: it
  describes this set and does not predict the next one. **The 56 wrong findings
  are unaffected**, because each was judged by inspecting the table rather than
  by assuming the rule was noisy.

## Follow-up, pre-registered before it runs

**Written down before the numbers exist, because a threshold checked only
against the data that produced it proves nothing.**

**The five, chosen 21/08/2026 and fixed before any of them ran.** Framework
constraints were read from each `composer.json` rather than remembered.

| App | Laravel | PHP | Why it is in |
|---|---|---|---|
| **Pterodactyl** | `^12.60` | `^8.2 \|\| ^8.3` | Pins `config.platform.php` like BookStack, so v1.10 is the first Truss that can install there. Answers ADR 0001 and ADR 0003 in one run. Needs an 8.3 binary: its constraint excludes 8.4. |
| **Coolify** | `^12.65` | `^8.4` | The largest modern schema available outside the study, and teams, servers, applications and environments are the exact shape this rule judges. |
| **Lychee** | `^12.0` | `^8.4` | A domain unlike anything in the sixteen. Albums, photos and tags are textbook join tables, so it tests true positives rather than false ones. |
| **InvoiceShelf** | `^13.0` | `^8.4` | The only real Laravel 13 application in the set. Crater is unmaintained; this is its successor. |
| **Azuriom** | `^12.0` | `^8.2` | Run on 8.2 deliberately: Pterodactyl tests the pinned-platform half of ADR 0001, this tests whether Truss runs on the interpreter. |

**Reserves if one cannot install: Pelican** (`^13.26`) and **Wave** (`^12.18`).
Pelican is out of the five on purpose, because running it beside Pterodactyl
would spend two slots on one schema lineage, and Wave is a SaaS starter kit, so
it is closest to the control kits already measured.

**Ruled out on their own `composer.json`, recorded so they are not re-proposed:**
laravel.io (`laravel/framework ^11.5`), FreeScout (`v5.5.40`) and Akaunting
(`^10.0`) are all below Truss's Laravel 12 minimum.

**The expectation, registered 21/08/2026, before any of the five ran.**

**Stated as a rate rather than a count, on purpose.** Coolify and Pterodactyl
may be large, and adjusting an absolute band after seeing the table counts would
be postdiction. The narrowed rule fires 14 times across 697 tables here, about
one per fifty.

- **At most 1 finding per 25 tables, and at most 1 wrong in total.**
- **Three or more wrong** means the thresholds are fitted to this study rather
  than to Laravel schemas, and want widening before release.
- **Zero findings across all five is a fail, not a pass.** It would say the rule
  has been narrowed into silence, and those schemas then get checked by hand for
  a pivot it should have caught.

**Two things are unverified and are expected to be settled by the run rather
than by reading**: that each installs from a plain clone, which is how Invoice
Ninja dropped out, and that each has meaningful foreign keys, which is how
Snipe-IT turned out to be nearly useless at one across 58 tables. **An app that
fails either test is reported and replaced from the reserves**, not quietly
dropped.

### What the narrowing actually lost, measured 21/08/2026

`bin/compare.py 2026-08-20 2026-08-21-branch --preset recommended`.

**Nothing else moved by a single finding.** `TRUSS-INT-001` 32 to 32,
`TRUSS-IDX-003` 10 to 10, `TRUSS-IDX-004` 4 to 4, `TRUSS-IDX-002` 2 to 2,
`TRUSS-INT-003` 2 to 2. **A rule change that moved a count it was not meant to
touch would show up here and nowhere else**, and none did.

**Every table this document names as a false positive is gone**: `bagisto.cart`,
`bagisto.orders`, `monica.users`, `firefly-iii.attachments`,
`firefly-iii.budgets`, `firefly-iii.budget_limits`, `firefly-iii.auto_budgets`.

**`koel.genre_song` survived**, which is the one the ADR names as a genuine
Laravel pivot and the reason the fix had to be a narrowing rather than a
deletion.

**All fourteen survivors are join tables by Laravel's own naming convention**:
`bagisto.category_filterable_attributes`, `bagisto.compare_items`,
`firefly-iii.budget_transaction`, `firefly-iii.category_transaction`,
`firefly-iii.category_transaction_journal`, `koel.genre_song`,
`monica.contact_address`, `monica.contact_label`, `monica.contact_life_metric`,
`monica.contact_post`, `monica.life_event_participants`,
`monica.module_template_page`, `monica.post_tag`,
`monica.timeline_event_participants`.

**Two things this does not settle, stated rather than glossed.**

**The 69 and the 14 cover twelve applications, not sixteen.** October CMS and
BookStack could not run Truss at all in the before run, which is itself the
result, and Lunar and Snipe-IT were parked unrun. **Across every application
that ran afterwards the narrowed rule fires 24 times**, the extra ten being
Lunar. Both figures are right and they answer different questions, so any
public number has to say which one it is.

**How many of the 13 "not shown to be wrong" survived is still not a count.**
The per-finding triage for the full run is prose rather than data, so the
strongest verified claim is the naming one above, not a true-positive retention
rate. Recording the triage as JSON alongside the findings would close this for
the next rule change, and is cheap to do while the reasoning is fresh.

### The holdout ran 21/08/2026, and the band was met

Two passes over the five, released `v1.9.1` then the `v1.10` branch, so the same
schemas are measured before and after rather than only after.

**Two of the five never produced findings, and both are results.**

- **Coolify is out, for a reason that has nothing to do with Truss.** Its
  migrations fail on MySQL: `Identifier name
  'environment_variables_key_application_id_is_build_time_is_preview_unique' is
  too long`, at **72 characters against MySQL's limit of 64**. It is a
  PostgreSQL-first application. It cannot be rescued by switching engines,
  because one pinned MySQL is the first invariant of this harness.
- **Pterodactyl and Azuriom could not install Truss v1.9 at all**, which is the
  ADR 0001 case reproduced twice on projects that were never used to justify it.
  Pterodactyl: `require php ^8.3 -> your php version (8.2.0; overridden via
  config.platform, actual: 8.3.30)`. Azuriom: PHP 8.2 outright. **Both install
  and run under v1.10**, at 35 and 28 tables. **That is the strongest evidence
  for the PHP floor change in the file**, because neither app influenced it.

**The rule, out of sample.** Across the two applications with both runs
(InvoiceShelf 49 tables, Lychee 52), `TRUSS-INT-007` goes **10 to 1**, nine gone
and none new. **No other rule moved by a single finding**: `TRUSS-IDX-003` 13 to
13, `TRUSS-IDX-002` 7 to 7, `TRUSS-IDX-004` 3 to 3, `TRUSS-INT-001` 1 to 1,
`TRUSS-INT-003` 1 to 1. The same surgical property holds on schemas the
thresholds never saw.

**Scored against the band registered before any of this ran.** Four applications
produced findings: 164 tables, **3 `TRUSS-INT-007` findings, a rate of 1 per 55
tables** against the registered ceiling of 1 per 25.

| Survivor | Shape | Verdict |
|---|---|---|
| `azuriom.page_role` | `page_id`, `role_id`, **no primary key at all** | Textbook. Exactly what the rule exists for |
| `azuriom.likes` | `id`, `post_id`, `author_id`, no payload | Correct: an author should like a post once |
| `invoiceshelf.user_company` | `id`, `user_id`, `company_id`, timestamps only | Correct: a Laravel `withTimestamps()` pivot |

**Zero wrong out of three**, against a ceiling of one. **And not zero findings**,
so the rule has not been narrowed into silence.

**The nine dropped were checked against `information_schema`, not assumed.**
`users` (18 columns), `faces` (14), `custom_field_values` (14), `transactions`
(10), `persons` (9) and `impersonation_logs` (8) are entities with surrogate keys
and real payload. Three are worth naming:

- **`lychee.tag_albums`** is a subtype table: its `id` is both the primary key
  and a foreign key to `base_albums`. A unique constraint on the pair would have
  been meaningless, since `id` is already unique.
- **`lychee.album_user_thumbs`** carries a generated `user_id_unique_key`
  column, so **the maintainers had already solved the uniqueness problem their
  own way**. The rule was telling them to do something they had done.
- **`lychee.purchasable_pixel_sizes`** carries `price_cents` and `license_type`,
  so it is a priced offer rather than a join.

**What this does and does not license.** It licenses saying the narrowing was
tested on schemas it was not fitted to, and held. It does not license a
percentage: two applications is a small before-and-after, and the band was about
the rate and the error count rather than a proportion.
