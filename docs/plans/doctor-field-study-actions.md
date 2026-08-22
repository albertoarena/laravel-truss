# Plan: acting on the August 2026 doctor field study

**Written 21/08/2026. Nothing in here is done.** Source evidence:
[field study](../research/2026-08-doctor-field-study.md). Decisions:
[ADR 0001](../adr/0001-php-82-minimum.md), [0002](../adr/0002-defer-gate-registration.md),
[0003](../adr/0003-pivot-detection.md).

## The sequencing rule, and why it is not negotiable

**Fix Truss, then send the project PRs, then publish anything.**

The reason is specific rather than tidy. Twelve findings are worth sending to
Cachet, Bagisto, Firefly III and Koel. Those PRs are the introduction to those
projects, and the first thing an interested maintainer does is install Truss and
run it. **Today that gives them a screen that is 47% false positives, including
advice that would cap their users at one budget each.** Sending first spends
exactly the goodwill the PRs exist to build.

The same argument applies harder to writing. An article about problems found in
twelve projects, written by a tool that is wrong half the time, is dismantled in
one reply.

## Phase 1: fix Truss

Ordered by how many users each affects, not by effort.

### 1.1 Narrow `TRUSS-INT-007` (ADR 0003)

**Highest impact in the whole plan**, because it degrades every user's first run.

- [ ] Replace `pivotPair()`'s two-foreign-keys test with the composite rule:
      `extra` (columns outside the pair, `id` and the timestamps) must be `<= 1`
      when there is no primary key or the pair is the primary key, and `== 0`
      when there is a surrogate `id`.
- [ ] Document the thresholds in the rule docblock, pointing at ADR 0003. They
      are judgement calls fitted to real schemas and will otherwise be
      "simplified" back.
- [ ] Regression tests using the shapes that actually broke it: a 36-column
      table with two foreign keys and a surrogate key (`bagisto.cart`), a
      three-column pivot with a surrogate key that must still fire
      (`koel.genre_song`), and a key-less join table (`monica.post_tag`).
- [ ] **Separately, decide whether the rule should also own the four
      primary-key-less join tables that `TRUSS-INT-001` currently catches.** The
      rule is too narrow as well as too broad, and that half is unresolved.

### 1.2 Defer gate registration (ADR 0002)

- [ ] Move the `viewTruss` definition out of package boot, into the route group
      or `Authorize` middleware.
- [ ] Keep a `$this->app->bound(GateContract::class)` guard as a cheap assertion
      that the layering has not regressed.
- [ ] Regression test that boots the package **with the Gate binding removed
      from the container**. Without it nothing catches a recurrence, because
      Testbench always binds it.
- [ ] Verify against October CMS using the parked harness rather than by
      reasoning: `bin/run.sh october` should get past boot.
- [ ] Note in the PR: any code assuming `viewTruss` exists outside an HTTP
      request would now find it undefined. Nothing does today.

### 1.3 Lower the PHP floor to 8.2 (ADR 0001)

Already evidenced: **404 passed, 4 skipped, identical on 8.2 and 8.4.**

- [ ] `require.php` from `^8.3` to `^8.2`. No other constraint changes: the dev
      toolchain resolves down on its existing ranges.
- [ ] **Add PHP 8.2 to the CI matrix.** A floor nothing tests is a claim.
- [ ] Update the "Minimum supported versions" entry in
      [`../DECISIONS.md`](../DECISIONS.md) to point at ADR 0001.
- [ ] Check the README and trussphp.com for a stated PHP requirement and update
      both. **A requirement stated in three places is wrong in two of them
      eventually.**
- [ ] Verify by re-running the two blocked applications, not by assuming:
      `bin/run.sh bookstack` and `bin/run.sh invoiceninja`.

### 1.4 Release

- [ ] Ship as **v1.10.0**, not a patch. ADR 0003 changes what the default preset
      reports, and a user whose CI passes `--fail-on=error` will see the output
      change. That is minor-version behaviour.
- [ ] The changelog entry for `TRUSS-INT-007` should say plainly that the rule
      was wrong and how it was found. **The field study is a better release note
      than a euphemism**, and it is checkable.

## Phase 2: report to the projects

Only after Phase 1 ships. Twelve findings, listed in the field study, each small
enough to be a single-commit PR.

- [ ] cachet, `TRUSS-IDX-003` on `incident_components`
- [ ] firefly-iii, `TRUSS-IDX-003` on `transactions`
- [ ] bagisto, four `IDX-003`, two `IDX-002`, two `IDX-004`
- [ ] koel, two `TRUSS-INT-003` type mismatches, the strongest of the set

**How to write them, because this is outreach whether or not it is intended as
outreach.** Lead with the specific structural fact and the `SHOW CREATE TABLE`
that shows it. Mention Truss once, at most, as how it was found. **Do not pitch.**
A maintainer who wants to know what found it will ask.

**Do not report the `password_resets` findings as project bugs.** They are
Laravel's old scaffold, inherited by four projects independently. Reporting them
to each project individually would be four wrong bug reports.

## Phase 3: finish the study, if it is still worth it

Four applications unrun, none able to change the compatibility picture. **Snipe-IT
is the one with something to add**: it predates Laravel 5, so it is the maximum
migration-debt case, and it would test `IDX-002` and `IDX-003` harder than
anything measured. The harness is parked and re-runnable; see `PARKED.md` in the
experiment repository.

**The experiment repository is on an external volume and is backed up nowhere.**
That is the same failure the `dev-reputation` repo exists to prevent. Either give
it a remote or accept that this file and the field study are the surviving
record. **Decide, rather than discover it later.**

## Phase 4: writing, only if the tool is fixed first

The honest framing, and the strongest one available: **"I pointed my own tool at
twelve real Laravel schemas. It found more bugs in itself than in them."**

It cannot be refuted, because it is self-critical. It demonstrates the tool at
serious scale without accusing anybody. And the `password_resets` thread gives it
a genuine ecosystem observation that names no project unkindly.

**Constraints that carry over from the notes repo:** no application is named in a
negative frame before its maintainers have seen the finding, and nothing is
recorded as done without the artefact to show it.

## Open questions, not decisions

1. **Should `TRUSS-INT-007` also cover key-less join tables** currently caught by
   `TRUSS-INT-001`? Unresolved, and 1.1 ships without it.
2. **Do `TRUSS-IDX-005`, `INT-002`, `IDX-006` and `INT-009` deserve the same
   scrutiny?** Together they are 160 of the 281 `strict` findings and **not one
   has been hand-verified.** They are heuristics outside the default preset, so
   they are not urgent, but the honest position is that their accuracy is
   currently unknown rather than acceptable.
3. **Does the doctor need a "why did this not fire" mode?** The study found the
   rule missing real pivots only because a different rule caught them.
