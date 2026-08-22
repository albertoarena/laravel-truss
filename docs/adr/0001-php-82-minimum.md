# ADR 0001: Lower the PHP floor to 8.2

- **Status:** Accepted 21/08/2026. Implemented on `v1.10-doctor-calibration`, unreleased and pending review.
- **Verified:** suite green on PHP 8.2.30 and 8.4.19 with the branch code, 412 passed / 4 skipped on both.
- **Supersedes:** the "Minimum supported versions" entry in [`../DECISIONS.md`](../DECISIONS.md), in part
- **Evidence:** [field study](../research/2026-08-doctor-field-study.md)

## Context

Truss requires `php ^8.3`. Laravel 12, which Truss supports, requires `php ^8.2`.
**So the package excludes part of the very framework version it advertises**, and
the exclusion is invisible until `composer require` refuses.

The field study hit this in the wild rather than in theory. **BookStack (19.0k
stars) cannot install Truss at all**, on a machine running PHP 8.4.19:

> `albertoarena/laravel-truss[v1.9.0, ..., v1.9.1] require php ^8.3 -> your php
> version (8.2.0; overridden via config.platform, actual: 8.4.19) does not
> satisfy that requirement.`

The cause is not BookStack's PHP. It is `config.platform.php = "8.2.0"` in their
`composer.json`, which is **careful practice rather than neglect**: it makes
Composer resolve as if running the oldest PHP the project supports, so a
maintainer on 8.4 cannot accidentally lock a dependency that breaks for a user on
8.2. Truss is excluded precisely because the project is being disciplined.

**Checked across the study set rather than generalised from one case.** Three
applications pin `config.platform.php` to `8.2.0`: **BookStack, Invoice Ninja and
Pterodactyl**. Five more declare `php ^8.2` without pinning: **Snipe-IT, October
CMS, Filament, Jetstream and Breeze**. Those five install today and **would stop
the day any of them adds the pin**, with no change to Truss or to their code.

## Decision

**Lower `require.php` from `^8.3` to `^8.2`.** Keep `illuminate/* ^12.0 || ^13.0`
unchanged. Add PHP 8.2 to the CI matrix, because a floor nothing tests is a
claim rather than a guarantee.

## Evidence that it is safe

**No PHP 8.3-only feature is used in `src/`.** Checked by search on 21/08/2026:
no typed class constants, no `json_validate()`, no `#[\Override]`, no dynamic
class-constant fetch, no `ReflectionEnum`.

**The full suite passes on 8.2, identically.** Run against a clean `git archive`
of `HEAD` with `require.php` set to `^8.2` and `config.platform.php` set to
`8.2.30`, dependencies re-resolved from scratch:

| PHP | Result |
|---|---|
| 8.2.30 | **404 passed, 4 skipped, 1069 assertions** |
| 8.4.19 (baseline) | **404 passed, 4 skipped, 1069 assertions** |

**The 4 skips are identical on both and are driver-related, not
version-related**: native column comments need MySQL or PostgreSQL and the local
suite runs on SQLite. Same tests, same reason, both versions.

**The dependency tree resolves without drama.** Every runtime dependency already
supports 8.2: `illuminate/*` via Laravel 12, and `spatie/laravel-package-tools`
at `^8.1`. The dev toolchain resolves down without any constraint change, because
the existing ranges already allow it:

| Package | Resolved on 8.2 | Constraint in `composer.json` |
|---|---|---|
| `orchestra/testbench` | v10.11.0 (`php ^8.2`) | `^10.0 \|\| ^11.0`, unchanged |
| `pestphp/pest` | v3.8.7 (`php ^8.2.0`) | `^3.5`, unchanged |
| `laravel/pint` | v1.30.4 (`php ^8.2.0`) | `^1.18`, unchanged |
| `laravel/mcp` | v0.9.4 (`php ^8.2`) | `^0.9`, unchanged |

**`laravel/mcp` was raised as the specific risk and is not one.** It requires
`php ^8.2` in every 0.9.x release, and it is a **dev** dependency, so it does not
constrain what a user can install in the first place.

## Consequences

**Accepted cost: PHP 8.2 reaches end of security support on 31/12/2026.**
Supporting it means shipping a floor that becomes unsupported within the year,
and it means not using 8.3 language features until the floor rises again. Neither
is currently paid for anything: nothing in `src/` uses those features today.

**What this does not commit to.** It is not a promise to support 8.2 forever. The
natural moment to raise the floor again is when Laravel's own minimum moves, and
raising it then costs nothing, because a project pinned to a PHP that Laravel has
dropped has a larger problem than Truss.

**CI grows one lane.** 8.2 has to be tested or the claim is unverified. That is
the real recurring cost of this decision, and it is small.

## Alternatives considered

**Keep `^8.3` and do nothing.** Rejected. The status quo is defensible on its own
terms ("we support Laravel 12 on a supported PHP"), but the failure is silent:
nobody discovers it until Composer refuses, and the error names a PHP version the
user is not running, which reads as a bug in Truss rather than a policy.

**Keep `^8.3` and make the exclusion visible.** A README line and a
`conflict` entry with a readable message. Much cheaper, and it was the fallback
if the tests had failed on 8.2. **Rejected because the tests passed**: given the
package already runs on 8.2, documenting the exclusion is choosing to keep a
restriction that costs users and buys nothing.

**Support 8.1.** Rejected. Laravel 12 requires `^8.2`, so 8.1 buys no additional
application and reaches further past end of life.
