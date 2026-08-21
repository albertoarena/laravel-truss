# ADR 0003: Narrow pivot detection in `TRUSS-INT-007`

- **Status:** Accepted 21/08/2026. Implemented on `v1.10-doctor-calibration`, unreleased and pending review.
- **Verified against the field-study schemas, not only fixtures:** `TRUSS-INT-007` 69 -> 14 findings, exactly the 14 tables predicted below. No other rule's count changed. **Measured on the same sixteen applications the thresholds were fitted to, so it is in-sample.**
- **Evidence:** [field study](../research/2026-08-doctor-field-study.md)

## Context

`PivotWithoutUniqueKey` is a **high-confidence rule, so it runs in the
`recommended` preset**, which is the default. Its entire test for "is this a
pivot" is `pivotPair()`:

- the table has **exactly two foreign keys**, and
- each foreign key is **single-column**.

It never looks at the primary key, never counts the other columns, and never asks
whether the table resembles a join table. **Any entity table that happens to have
two foreign keys is classified as a many-to-many pivot.**

### What that produced in the field

Across the twelve applications measured, the rule fired **69 times**. Checked
against `information_schema` rather than inferred:

| App | Fired | On a table with a surrogate `id` primary key |
|---|---:|---:|
| monica | 26 | 17 |
| bagisto | 17 | 16 |
| firefly-iii | 17 | 17 |
| koel | 9 | 6 |
| **total** | **69** | **56 (81%)** |

**56 of the 119 `recommended` findings across the whole study are this rule
being wrong: 47% of everything the default preset said.**

Some of what it called a pivot:

| Table | Columns | What it is |
|---|---:|---|
| `bagisto.orders` | 67 | an order |
| `bagisto.cart` | 36 | a shopping cart |
| `monica.users` | 22 | a user |
| `firefly-iii.attachments` | 15 | an uploaded file |
| `firefly-iii.budgets` | 10 | a budget |

### Why this is worse than noise

**The advice it gives would destroy data.** The hint says to put a unique
constraint on the pair. On `firefly-iii.budgets`, flagged on
`(user_group_id, user_id)`, applying it means **a user may have exactly one
budget**. On `bagisto.cart`, unique `(channel_id, customer_id)` caps a customer at
one cart per channel forever. A noisy rule wastes attention; this one hands out a
migration that loses rows.

### And it misses the tables it exists for

`TRUSS-INT-001` caught four genuine primary-key-less join tables that this rule
should have owned: `cms_page_channels`, `object_groupables`,
`channel_inventory_sources`, `category_filterable_attributes`. **So the rule is
simultaneously too broad and too narrow**, which is why the fix is a better
definition rather than a threshold bolted on.

## Decision

**Replace the two-foreign-keys test with a composite one. A join table carries
its key pair and almost nothing else.**

Define `extra` as the number of columns that are not in the foreign-key pair, not
`id`, and not `created_at` / `updated_at` / `deleted_at`. Then:

| Primary key | Treat as a pivot when |
|---|---|
| none, or exactly the foreign-key pair | `extra <= 1` |
| a single surrogate `id` | `extra == 0` |
| anything else | never |

**The surrogate-key case is stricter on purpose.** A table that already has its
own identity is presumed an entity unless it carries literally nothing beyond the
pair. That is what separates `koel.genre_song` (an `id`, two keys, nothing else,
a real pivot) from `koel.playlist_folders` (an `id`, two keys, and a name, an
entity).

### Measured against the same 69 findings

**Keeps 14, drops 55.** Every finding independently confirmed as a true positive
survives, and every one confirmed false is dropped:

| Kept | Dropped |
|---|---|
| `post_tag`, `genre_song`, `contact_label`, `contact_post`, `contact_address`, `contact_life_metric`, `life_event_participants`, `timeline_event_participants`, `module_template_page`, `budget_transaction`, `category_transaction`, `category_transaction_journal`, `compare_items`, `category_filterable_attributes` | `cart` (31 extra), `orders` (62), `users` (17), `attachments` (9), `budgets` (4), `albums` (4), `categories` (2), `playlist_folders` (1, but has a surrogate key) and 47 more |

The kept list reads as what it should: Laravel-convention join tables, mostly
`singular_singular`, almost all with no primary key at all.

## Consequences

**On the sixteen applications the thresholds were fitted to, `recommended`
output drops by roughly 47%**, and what remains is defensible. This is the single
largest quality change available to the doctor, because it affects **every user's
first run**, not an edge case.

**That 47% is an in-sample figure and should be quoted as one.** The same
schemas suggested the thresholds and then measured them, so it describes this
set rather than predicting the next one. **What it does not depend on is the
fitting**: the 56 findings were judged wrong by inspecting each table, not by
assuming the rule was noisy, so "the rule was wrong 56 times out of 69 on real
schemas" holds however the fix is scored.

**The generalisation is untested until fresh schemas are run.** Five
applications outside the study, with the expected count written down before the
run, would settle it. See the field study's follow-up section.

**What the change cost, now measured rather than assumed** (21/08, full diff in
the field study). **No other rule moved by a single finding.** Every table named
above as a false positive is gone. **`koel.genre_song` survived**, and all
fourteen survivors are join tables by Laravel's naming convention. **The 69 and
the 14 cover twelve applications**, not sixteen: October and BookStack could not
run Truss before the fix, and Lunar and Snipe-IT were unrun. Across every
application that ran afterwards the rule fires **24** times. **"Some true
positives will be lost" is still not a count**, because the per-finding triage
is prose rather than data.

**Some true positives will be lost, and that is the accepted trade.** A genuine
pivot carrying two payload columns now escapes the rule. **Silence is the right
failure direction here**: a missed finding costs a user nothing, and a wrong one
costs them a destructive migration and their trust in every other rule.

**The heuristics live in one place and need to stay documented.** `extra <= 1`,
`extra == 0` and the timestamp names are judgement calls fitted to real schemas,
not laws. They belong in the rule's docblock with a pointer to this ADR, or the
next reader will "simplify" them back to the current behaviour.

**Fixture tests are not enough to protect this.** The rule passed its own test
suite while being wrong 56 times in the field. The regression tests should
include the shapes that actually broke it: a 36-column table with two foreign
keys, and a three-column pivot with a surrogate key.

## Alternatives considered

**Demote the rule to `Confidence::Heuristic`** so it leaves the default preset.
Rejected as a substitute, kept as a fallback. It would stop the damage without
fixing anything, and it would remove a rule that is genuinely useful 14 times in
this data. **If the narrowing cannot be made safe, demoting is better than
shipping the current behaviour.**

**Skip any table with a surrogate `id` primary key.** Rejected: it looked
sufficient at first (it removes 56 of the 69) and it is wrong. `koel.genre_song`
is a real Laravel pivot **with** an `id` primary key and no unique on the pair,
so duplicates are genuinely possible there. That alternative was tested and
discarded on the data, which is why the surrogate case survives with `extra == 0`
rather than being excluded outright.

**Use the table name.** Laravel's `singular_singular` convention is a strong
signal in this data. Rejected as a primary test: it is a convention rather than a
guarantee, it fails for any non-English or legacy schema, and structure is
available and objective. Reasonable as a future tie-breaker, not as the rule.
