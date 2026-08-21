# ADR 0002: Do not resolve the Gate contract at boot

- **Status:** Proposed (21/08/2026)
- **Evidence:** [field study](../research/2026-08-doctor-field-study.md)

## Context

`TrussServiceProvider` calls `registerGate()` during package boot
(`TrussServiceProvider.php:74`), and that method opens with:

```php
if (! Gate::has('viewTruss')) {
    Gate::define('viewTruss', ...);
}
```

`Gate::has()` resolves `Illuminate\Contracts\Auth\Access\Gate` from the
container. **In a standard Laravel application that binding always exists, so the
cost is invisible. In an application that does not bind it, every `artisan`
command dies.**

**Found in October CMS 4.x** (11.1k stars), which ships its own authentication
and does not bind Laravel's Gate:

> `Target [Illuminate\Contracts\Auth\Access\Gate] is not instantiable while
> building [Spatie\LaravelPackageTools\PackageServiceProvider]`

**Established by removing one variable rather than by reading the stack trace:**

| | Command | Result |
|---|---|---|
| A | `artisan migrate` with Truss v1.9.1 installed | the error above |
| B | `composer remove albertoarena/laravel-truss`, nothing else changed | `INFO Preparing database. ... Nothing to migrate.` |

`composer why spatie/laravel-package-tools` confirms Truss is the only package
pulling it, so the failure is ours.

**The blast radius is the whole CLI, not the dashboard.** Truss takes down
`migrate`, `cache:clear`, `tinker` and everything else, because the provider
boots for every command. A user who adds Truss to such an application sees their
entire `artisan` break, with a message naming Spatie and Laravel and not naming
Truss at all.

## The part that matters beyond October

**`truss:doctor` is documented as safe to run in CI**, deterministic, structure
only, no network call. **It currently cannot run without the authorization system
being resolvable**, for a gate that only the dashboard route ever consults. The
command has no use for `viewTruss` and never checks it.

So this is not only a compatibility bug with one CMS. It is a **layering
mistake**: an HTTP-surface concern is being registered in a code path shared with
the console, and it makes a CI-facing promise conditional on something CI does
not need.

## Decision

**Register the gate lazily, so nothing resolves the Gate contract at boot.**

Two changes, and the second is the durable one:

1. **Guard the call.** Skip gate registration entirely when the contract is not
   bound: `if (! $this->app->bound(GateContract::class)) { return; }`. Cheap,
   and it fixes October on its own.
2. **Move the registration to where it is used.** Define the gate from the route
   group or from `Authorize` middleware, so it is registered on the first HTTP
   request that needs it and never in a console-only lifecycle.

Change 2 makes change 1 mostly redundant, which is the point: **the guard treats
the symptom and the move removes the class of bug.** Keep both, because a guard
is a cheap assertion that the layering has not regressed.

## Consequences

**Behaviour a user can observe does not change.** The gate is defined before any
request can reach a Truss route, which is the only place it is consulted. A host
application that defines its own `viewTruss` still wins, because the `Gate::has()`
check is preserved wherever the definition ends up.

**One thing to watch in review.** Any code that assumes `viewTruss` exists
outside an HTTP request would now find it undefined. Nothing does today, and the
test suite covers the gate through the routes, but it is the regression this
change could plausibly introduce and it belongs in the PR description.

**A test is part of the fix, not a follow-up.** A regression test that boots the
package with the Gate binding removed from the container turns this from a fix
into a guarantee. Without it, the next provider-level convenience call
reintroduces the same failure and nothing catches it, since the whole test suite
runs in Testbench, where the binding always exists.

## Alternatives considered

**Do nothing, and document that October is unsupported.** Rejected. The
misleading error alone argues against it: the message names Spatie and Laravel,
so the user does not learn that Truss caused it, and the maintainers of the host
application get the bug report.

**Catch the exception around `registerGate()`.** Rejected. It would hide a real
layering problem behind a swallowed throwable, and it would leave `truss:doctor`
still booting the auth stack in the normal case.
