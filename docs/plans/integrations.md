# Plan: integrations and plugins

**Opened 21/08/2026, after the doctor field study.** Nothing here is decided.

## Why now, and what the real reason is

The honest reason to build an integration is **distribution, not capability**.
Truss already runs inside any Laravel application; a Filament plugin does not let
anyone do something they cannot do today. What it does is put Truss in a place
developers **browse**, which is the thing Truss does not have. Every channel
tried so far pushes outward (posts, comments, submissions). A plugin directory is
somebody else's audience arriving with intent.

So judge each candidate on two axes, in this order:

1. **Is there a discovery channel**, a directory or marketplace people actually
   browse?
2. **Does that platform's typical application have a schema worth looking at?**
   The field study can answer this from data rather than opinion.

## Reach, read from Packagist 21/08/2026

| Platform | Downloads | Directory | Schema worth viewing? |
|---|---:|---|---|
| **Filament** | 34.9M | filamentphp.com/plugins | The panel adds nothing itself, but it sits **on top of** somebody's real schema |
| **Laravel Boost** | 29.5M | Laravel's own | n/a, it is an agent surface |
| Laravel Pulse | 17.2M | none really | n/a, performance not structure |
| Statamic | 3.9M | Statamic Marketplace | **No. Measured: 9 tables, Laravel defaults only** |
| Backpack | 3.8M | backpackforlaravel.com/addons | Same shape as Filament |
| October CMS | 1.7M | October Marketplace | Yes, measured at 41 tables |
| MoonShine | 276k | has one | Too small to justify the work |
| Nova | not on Packagist | Nova sites | Commercial, needs a licence to develop against |

## Recommendation, in order

### 1. Filament, and it is not close

34.9M downloads, an actual plugin directory, and **the fit is exact**: Filament is
an admin panel over somebody's database, and Truss draws that database. A user
who has installed Filament has already decided they want an admin surface.

**One design consequence, and it lands right where v1.10 has been working.**
Filament panels carry their own authentication and their own access checks. ADR
0002 has just moved gate registration out of boot and into the `Authorize`
middleware, and a Filament page would not pass through that middleware at all.
**So the plugin needs its own authorization path**, and getting that wrong is the
one bug in this whole area that would actually matter. Design it deliberately
rather than inheriting whatever the panel does.

### 2. Laravel Boost

**A branch already exists, `feat/boost-guideline`**, cut from v1.9.0 and rebased
onto main 21/08/2026. It ships a guideline, a skill and three test files
including a discovery contract test. Not pushed, under test.

**A real-world test case found by accident, worth trying before it ships.**
Snipe-IT (14.8k stars) requires `laravel/boost ^2.5` and runs
`@php artisan boost:update` in its `post-update-cmd`. In the field-study harness
that app sat in a state where **Boost was installed but never initialised**, and
every `composer require` failed:

> `Please set up Boost with [php artisan boost:install] first.`
> `Script @php artisan boost:update --ansi handling the post-update-cmd event returned with error code 1`

Not Truss's doing, and Truss was not even involved in the failure. **But it is
exactly the state a Truss Boost guideline would land in**, so it is worth
knowing what the branch does there: installed-but-not-initialised is evidently a
state real applications sit in, not a hypothetical.

It is also the first evidence in this file that Boost is in production use in a
large Laravel application, rather than only widely downloaded.



29.5M downloads, and **the work is integration rather than construction**: Truss
already ships an optional read-only MCP server. Boost support was already on the
roadmap for v1.10 before this discussion.

Strategically it is the strongest of the set for a reason unrelated to size:
**what an agent quotes about your schema becomes what the developer believes.**
Truss being the thing that answers "what does this database look like" inside
Laravel's own agent tooling is a better position than a menu entry.

### 3. October CMS, but only after Filament proves the model

It has a marketplace, and as of v1.10 Truss actually works there, which it did
not a week ago. At 1.7M against Filament's 34.9M it is a second experiment, not a
first one. **The interesting part is that the plugin listing would be evidence
the compatibility fix was real**, which pairs with the compatibility table.

### 4. Backpack, later

Same shape as Filament at roughly a tenth the reach. Worth doing if the Filament
plugin returns anything, and not worth doing speculatively.

## Explicit no, on evidence rather than taste

**Statamic.** The field study measured it at **9 tables, identical to a bare
Laravel skeleton**, because Statamic keeps content in flat files. **A schema
viewer has nothing to show a Statamic user.** Building an addon for a 3.9M
download platform would look like reach and deliver an empty diagram, which is
worse than absence.

That rejection is the clearest argument for having run the study at all: without
it, Statamic looks like an obvious third target on download count alone.

**Laravel Pulse.** A Pulse card is about what is happening now. Truss is about
what the structure is. Forcing structure into a performance dashboard produces a
widget nobody asked for.

**Nova.** Commercial, needs a licence to develop and test against, and no free
discovery channel. Revisit only if a Nova user asks.

## Open questions

1. **Does a Filament plugin embed the existing dashboard, or re-render inside
   the panel?** Embedding is cheap and looks foreign; re-rendering is real work
   and the Mermaid dependency has to come along either way.
2. **Where does the plugin live**, this repository or its own? A separate
   repository keeps `laravel-truss` free of a Filament dependency, which matters
   because Filament's own version cadence would otherwise drive Truss releases.
3. **What does the plugin do about `truss:doctor`?** The Health panel already
   exists in the dashboard. A Filament page showing findings is arguably more
   valuable than the diagram to that audience, and it is the half that just got
   calibrated.
